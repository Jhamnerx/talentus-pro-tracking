<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationHourStat;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationRefueling;
use Tobuli\Entities\OperationShiftSummary;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\OperationVisit;

/**
 * Purga de las tablas de Operaciones anteriores a una fecha. La llama
 * `server:autoclean` (Limpieza automatica del historial) cuando el modulo
 * esta activo; no se configura aparte.
 *
 * Borra por lotes de ids (whereIn) y en orden hijo -> padre para que el
 * conteo por tabla sea fiel aunque las FK tengan cascade. No toca
 * `operation_device_state`, `operation_recalculations` ni los puntos.
 */
class OperationsCleanCommand extends Command
{
    protected $signature = 'operations:clean {date : Y-m-d, se borra lo anterior a esta fecha (compara en UTC con 00:00)}';

    protected $description = 'Borra resúmenes, estadísticas, abastecimientos, paradas, tramos, visitas y vueltas de Operaciones anteriores a la fecha';

    const CHUNK = 1000;

    public function handle()
    {
        $date = $this->argument('date');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
            $this->error("Fecha inválida: {$date} (esperado Y-m-d)");

            return 1;
        }

        $limit = $date.' 00:00:00';

        $totals = [
            'operation_refuelings'      => $this->purge(OperationRefueling::where('started_at', '<', $limit)),
            'operation_hour_stats'      => $this->purge(OperationHourStat::where('hour_at', '<', $limit)),
            'operation_shift_summaries' => $this->purge(OperationShiftSummary::where('shift_date', '<', $date)),
            'operation_stops'           => $this->purge(OperationStop::whereIn('leg_id', function ($q) use ($limit) {
                $q->select('id')->from('operation_legs')->where('started_at', '<', $limit);
            })),
            'operation_legs'            => $this->purge(OperationLeg::where('started_at', '<', $limit)),
            'operation_visits'          => $this->purge(OperationVisit::where('entered_at', '<', $limit)),
            'operation_cycles'          => $this->purge(OperationCycle::where('started_at', '<', $limit)),
        ];

        foreach ($totals as $table => $count) {
            $this->line(sprintf('%-28s %d', $table.':', $count));
        }

        $this->line("Job done[OK]\n");

        return 0;
    }

    /** Borra por lotes de ids lo que cumpla el filtro; devuelve las filas borradas. */
    protected function purge(Builder $query): int
    {
        $model = $query->getModel();
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += $model->newQuery()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
