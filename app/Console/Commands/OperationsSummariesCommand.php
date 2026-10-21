<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Entities\Operation;
use Tobuli\Services\Operations\OperationShiftSummaryService;

/**
 * Reporte de turno: calcula (o recalcula) los resumenes de turno, los
 * abastecimientos y las filas por hora de una o todas las operaciones para un
 * rango de dias operativos (fechas locales del dueño, Y-m-d).
 *
 * Es el camino manual / de relleno; el cierre automatico de cada turno lo
 * hace operations:process (closeShifts).
 */
class OperationsSummariesCommand extends Command
{
    protected $signature = 'operations:summaries
                            {--operation= : id de la operacion (vacio = todas las activas)}
                            {--from= : primer dia operativo Y-m-d (por defecto ayer)}
                            {--to= : ultimo dia operativo Y-m-d (por defecto igual a --from)}
                            {--device= : solo este vehiculo (id)}';

    protected $description = 'Calcula los resumenes de turno (reporte de turno) de operaciones por rango de dias';

    public function handle(): int
    {
        if (! config('tobuli.operations_enabled')) {
            $this->error('OPERATIONS_ENABLED esta apagado');
            return 1;
        }

        $from = $this->option('from') ?: date('Y-m-d', strtotime('-1 day'));
        $to = $this->option('to') ?: $from;

        foreach ([$from, $to] as $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("Fecha invalida: {$date} (Y-m-d)");
                return 1;
            }
        }

        if ($to < $from) {
            $this->error('--to es anterior a --from');
            return 1;
        }

        $query = Operation::with('user', 'shifts.breaks');

        if ($id = $this->option('operation')) {
            $query->where('id', (int) $id);
        } else {
            $query->visible();
        }

        $operations = $query->get();

        if ($operations->isEmpty()) {
            $this->error('Sin operaciones');
            return 1;
        }

        $service = new OperationShiftSummaryService();
        $deviceId = $this->option('device') ? (int) $this->option('device') : null;

        foreach ($operations as $operation) {
            if ($operation->shifts->isEmpty()) {
                $this->line("Operacion {$operation->id} ({$operation->name}): sin turnos, se omite");
                continue;
            }

            $this->line("Operacion {$operation->id} ({$operation->name}): {$from} .. {$to}");

            if ($deviceId) {
                $this->buildDevice($service, $operation, $deviceId, $from, $to);
            } else {
                $service->buildRange($operation, $from, $to);
            }
        }

        $this->info('Listo');

        return 0;
    }

    /** Un solo vehiculo: cada turno de cada dia, y luego la flota de ese rango. */
    protected function buildDevice(OperationShiftSummaryService $service, Operation $operation, int $deviceId, string $from, string $to): void
    {
        $device = Device::with('sensors')->find($deviceId);

        if (! $device) {
            $this->error("Vehiculo {$deviceId} inexistente");
            return;
        }

        $spanFrom = null;
        $spanTo = null;

        for ($d = new \DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
            foreach ($operation->shifts as $shift) {
                $shiftDate = $service->shiftDateForOperativeDay($operation, $shift, $d->format('Y-m-d'));
                [$startUtc, $endUtc] = $service->shiftRangeUtc($operation, $shift, $shiftDate);

                if (strtotime($startUtc . ' UTC') > time()) {
                    continue;
                }

                $service->buildShift($operation, $device, $shift, $shiftDate);

                $spanFrom = is_null($spanFrom) ? $startUtc : min($spanFrom, $startUtc);
                $spanTo = is_null($spanTo) ? $endUtc : max($spanTo, $endUtc);
            }
        }

        if ($spanFrom && $spanTo) {
            $service->buildFleetHours($operation, $spanFrom, $spanTo);
        }
    }
}
