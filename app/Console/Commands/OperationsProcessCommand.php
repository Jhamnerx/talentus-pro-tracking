<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationRecalculation;
use Tobuli\Entities\OperationShiftSummary;
use Tobuli\Helpers\Formatter\Formatter;
use Tobuli\Services\Operations\OperationCycleWriter;
use Tobuli\Services\Operations\OperationLegCalculator;
use Tobuli\Services\Operations\OperationRebuildService;
use Tobuli\Services\Operations\OperationService;
use Tobuli\Services\Operations\OperationShiftSummaryService;

/**
 * Trabajo por minuto del modulo de Operaciones. Todo lo pesado va aqui y no
 * en PositionsWriter:
 *
 *  1. Sincroniza los vehiculos de cada operacion activa con su grupo.
 *  2. Calcula los tramos cerrados pendientes (una pasada del pipeline por tramo).
 *  3. Marca "Sin datos" a los vehiculos en tramo sin trama en NO_DATA_SECONDS.
 *  4. Cierra como incompletos los tramos que superan max_leg_hours.
 *  5. Atiende la cola de recalculos, con tope por pasada.
 *  6. Cierra los turnos recien terminados: resumen de turno por vehiculo y
 *     filas de flota por hora (reporte de turno).
 */
class OperationsProcessCommand extends Command
{
    protected $signature = 'operations:process
                            {--legs=50 : tramos a calcular por pasada}
                            {--rebuilds=5 : recalculos a atender por pasada}';

    protected $description = 'Calcula tramos cerrados, detecta vehiculos sin datos y atiende recalculos de operaciones';

    const NO_DATA_SECONDS = 90;

    /**
     * Ventana, en minutos despues del fin del turno, en la que se calcula su
     * resumen. Se espera CLOSE_SHIFT_MIN_MINUTES para que los tramos que
     * cerraron al final del turno ya hayan pasado por calculateLegs (paradas,
     * ralenti) y se corta en CLOSE_SHIFT_MAX_MINUTES para que un comando que
     * estuvo caido no recalcule turnos viejos en cada pasada (eso es trabajo
     * de operations:summaries). Como el comando corre cada minuto y salta los
     * resumenes ya calculados despues del fin del turno, cada turno se cierra
     * una sola vez.
     */
    const CLOSE_SHIFT_MIN_MINUTES = 10;
    const CLOSE_SHIFT_MAX_MINUTES = 70;

    public function handle(): int
    {
        if (! config('tobuli.operations_enabled')) {
            return 0;
        }

        $operations = Operation::visible()->with('user')->get();

        foreach ($operations as $operation) {
            $this->syncDevices($operation);
        }

        $this->calculateLegs((int) $this->option('legs'));
        $this->detectNoData($operations);
        $this->expireLegs($operations);
        $this->runRecalculations((int) $this->option('rebuilds'));
        $this->closeShifts($operations);

        return 0;
    }

    protected function syncDevices(Operation $operation): void
    {
        $result = (new OperationService($operation->user))->syncDeviceStates($operation);

        if ($result['added'] || $result['removed']) {
            $this->line("Operacion {$operation->id}: +{$result['added']} -{$result['removed']} vehiculos");
        }
    }

    protected function calculateLegs(int $limit): void
    {
        $calculator = new OperationLegCalculator();

        $legs = OperationLeg::pendingCalculation()
            ->orderBy('ended_at')
            ->limit($limit)
            ->get();

        foreach ($legs as $leg) {
            try {
                $calculator->calculate($leg);
            } catch (\Throwable $e) {
                \Log::error("operations:process tramo {$leg->id}: " . $e->getMessage());
                // Que no bloquee la cola: queda calculado sin datos.
                $leg->calculated_at = date('Y-m-d H:i:s');
                $leg->save();
            }
        }

        if ($legs->count()) {
            $this->line("Tramos calculados: {$legs->count()}");
        }
    }

    /**
     * PositionsWriter solo corre cuando llega una trama, asi que "sin datos"
     * se detecta aqui leyendo traccar.time. Al volver una trama,
     * OperationCycleWriter deshace el estado.
     */
    protected function detectNoData($operations): void
    {
        $limit = time() - self::NO_DATA_SECONDS;

        $states = OperationDeviceState::whereIn('operation_id', $operations->pluck('id'))
            ->whereNotNull('open_leg_id')
            ->where('state', '!=', OperationDeviceState::STATE_NO_DATA)
            ->with('device.traccar')
            ->get();

        foreach ($states as $state) {
            $traccar = $state->device ? $state->device->traccar : null;

            if (! $traccar || ! $traccar->time) {
                continue;
            }

            if (strtotime($traccar->time) >= $limit) {
                continue;
            }

            $state->state = OperationDeviceState::STATE_NO_DATA;
            $state->no_data_since = $traccar->time;
            $state->save();
        }
    }

    protected function expireLegs($operations): void
    {
        foreach ($operations as $operation) {
            $cutoff = date('Y-m-d H:i:s', time() - $operation->max_leg_hours * 3600);

            $states = OperationDeviceState::where('operation_id', $operation->id)
                ->whereNotNull('open_leg_id')
                ->whereHas('openLeg', fn ($q) => $q->where('started_at', '<', $cutoff))
                ->with(['openLeg', 'openCycle', 'openVisit', 'currentPoint'])
                ->get();

            if ($states->isEmpty()) {
                continue;
            }

            $formatter = new Formatter();
            $formatter->byUser($operation->user);

            foreach ($states as $state) {
                $at = $state->last_time ? $state->last_time->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

                (new OperationCycleWriter($operation, $state, collect(), $formatter))->abandon($at);

                $this->line("Operacion {$operation->id}: tramo vencido del vehiculo {$state->device_id}");
            }
        }
    }

    /**
     * Resumen de turno de los turnos que terminaron hace entre
     * CLOSE_SHIFT_MIN_MINUTES y CLOSE_SHIFT_MAX_MINUTES. Se miran las fechas
     * de turno de hoy y de ayer (hora local del dueño): un turno que cruza la
     * medianoche termina el dia siguiente al de su shift_date.
     */
    protected function closeShifts($operations): void
    {
        $service = new OperationShiftSummaryService();
        $now = time();
        $minEnd = $now - self::CLOSE_SHIFT_MAX_MINUTES * 60;
        $maxEnd = $now - self::CLOSE_SHIFT_MIN_MINUTES * 60;

        foreach ($operations as $operation) {
            $operation->loadMissing('shifts.breaks');

            if ($operation->shifts->isEmpty()) {
                continue;
            }

            $formatter = new Formatter();
            $formatter->byUser($operation->user);
            $today = $formatter->time()->convert(date('Y-m-d H:i:s'), 'Y-m-d');
            $yesterday = (new \DateTime($today))->modify('-1 day')->format('Y-m-d');

            $devices = null;

            foreach ($operation->shifts as $shift) {
                foreach ([$yesterday, $today] as $shiftDate) {
                    [$startUtc, $endUtc] = $service->shiftRangeUtc($operation, $shift, $shiftDate);
                    $endTs = strtotime($endUtc . ' UTC');

                    if ($endTs < $minEnd || $endTs > $maxEnd) {
                        continue;
                    }

                    if (is_null($devices)) {
                        $devices = Device::whereIn('id', $operation->deviceIds())->with('sensors')->get();
                    }

                    $done = OperationShiftSummary::where('operation_id', $operation->id)
                        ->where('shift_id', $shift->id)
                        ->where('shift_date', $shiftDate)
                        ->where('calculated_at', '>', $endUtc)
                        ->pluck('device_id')
                        ->flip();

                    $built = 0;

                    foreach ($devices as $device) {
                        if ($done->has($device->id)) {
                            continue;
                        }

                        try {
                            $service->buildShift($operation, $device, $shift, $shiftDate);
                            $built++;
                        } catch (\Throwable $e) {
                            \Log::error("operations:process resumen de turno {$shift->id}/{$shiftDate} vehiculo {$device->id}: " . $e->getMessage());
                        }
                    }

                    if ($built) {
                        $service->buildFleetHours($operation, $startUtc, $endUtc);
                        $this->line("Operacion {$operation->id}: turno {$shift->name} {$shiftDate} cerrado ({$built} vehiculos)");
                    }
                }
            }
        }
    }

    protected function runRecalculations(int $limit): void
    {
        $service = new OperationRebuildService();

        $items = OperationRecalculation::pending()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($items as $item) {
            // Toma la fila solo si nadie la tomo entre medias.
            $taken = OperationRecalculation::where('id', $item->id)
                ->where('status', OperationRecalculation::STATUS_PENDING)
                ->update(['status' => OperationRecalculation::STATUS_RUNNING]);

            if (! $taken) {
                continue;
            }

            $operation = Operation::withTrashed()->with('user')->find($item->operation_id);
            $device = Device::find($item->device_id);

            if (! $operation || ! $device) {
                $item->update(['status' => OperationRecalculation::STATUS_FAILED, 'error' => 'operacion o vehiculo inexistente']);
                continue;
            }

            try {
                $service->rebuild($operation, $device, $item->from_time->format('Y-m-d H:i:s'), $item->use_current_points);
                $item->update(['status' => OperationRecalculation::STATUS_DONE]);
                $this->line("Recalculo {$item->id}: vehiculo {$device->id} desde {$item->from_time}");
            } catch (\Throwable $e) {
                \Log::error("operations:process recalculo {$item->id}: " . $e->getMessage());
                $item->update(['status' => OperationRecalculation::STATUS_FAILED, 'error' => substr($e->getMessage(), 0, 2000)]);
            }
        }
    }
}
