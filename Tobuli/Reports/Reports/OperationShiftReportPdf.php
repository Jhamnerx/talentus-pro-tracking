<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tobuli\Entities\Operation;
use Tobuli\Entities\User;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\Operations\OperationShiftReport;
use Tobuli\Services\Operations\OperationShiftSummaryService;

/**
 * Reporte de turno de Operaciones en PDF (o HTML): lo mismo que la pestaña
 * «Turno» (tarjetas, cinco graficas por hora, estados por equipo y tablas),
 * con las graficas dibujadas como SVG (ShiftReportSvg). Al ser un tipo de
 * reporte mas, hereda la programacion diaria/semanal y el envio por correo
 * de reports:daily.
 *
 * Que dias entran: cada dia operativo (desde day_start de la operacion,
 * 24 h) cuyo punto medio cae dentro del rango del reporte. Asi un envio
 * diario a las 08:00 (rango ayer 08:00 -> hoy 08:00) trae el dia operativo
 * de ayer, y un rango manual del 1 al 9 trae del 1 al 9.
 *
 * Los datos salen de los resumenes de turno ya calculados; si un dia ya
 * terminado no tiene resumenes se calculan en el momento (como el boton
 * «Calcular ahora» de la pantalla).
 */
class OperationShiftReportPdf extends DeviceReport
{
    const TYPE_ID = 914;

    /** Tope de dias operativos por reporte. */
    const MAX_DAYS = 31;

    protected $formats = ['pdf_land', 'pdf', 'html'];

    protected $disableFields = ['devices', 'geofences', 'speed_limit', 'stops', 'show_addresses', 'zones_instead', 'metas'];

    protected $deviceless = true;

    /** @var Operation|null */
    protected $operation;

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.report_operation_shift');
    }

    public static function isUserEnabled(User $user): bool
    {
        return config('tobuli.operations_enabled') && static::isEnabled() && $user->perm('operations', 'view');
    }

    protected function defaultMetas()
    {
        return [];
    }

    public function getInputParameters(): array
    {
        $operations = Operation::userOwned($this->user)->with('shifts')->orderBy('name')->get();

        $shifts = ['all' => trans('ui.ops_shift_all')];

        foreach ($operations as $op) {
            foreach ($op->shifts as $s) {
                $shifts[$s->id] = $op->name . ' · ' . $s->name . ' (' . substr($s->start_time, 0, 5) . '–' . substr($s->end_time, 0, 5) . ')';
            }
        }

        return [
            \Field::select('operation_id', trans('front.operations'))->setOptions($operations->pluck('name', 'id')->all()),
            \Field::select('shift', trans('ui.ops_shift'), 'all')->setOptions($shifts),
        ];
    }

    public function getFilename()
    {
        $name = $this->operation ? Str::slug($this->operation->name, '_') : 'operacion';

        return 'turno_' . $name . '_' . date('Ymd_His');
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    protected function generate()
    {
        $id = (int) Arr::get($this->parameters, 'operation_id');

        $this->operation = Operation::userOwned($this->user)->find($id)
            ?: Operation::userOwned($this->user)->orderBy('name')->first();

        if (! $this->operation) {
            return;
        }

        $this->operation->loadMissing('user', 'shifts.breaks');

        // Turno pedido solo si es de esta operacion; si no, todos.
        $shift = (string) Arr::get($this->parameters, 'shift', 'all');
        if ($shift !== 'all' && ! $this->operation->shifts->contains('id', (int) $shift)) {
            $shift = 'all';
        }

        $builder = new OperationShiftReport(app(\Tobuli\Helpers\Formatter\Formatter::class));
        $service = new OperationShiftSummaryService();
        $nowLocal = Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d H:i:s');

        foreach ($this->operativeDays() as $date) {
            $data = $builder->build($this->operation, $date, $shift);

            // Dia ya terminado sin resumenes: se calculan ahora.
            if (! empty($data['empty']) && ($data['hint'] ?? null) === trans('ui.ops_shift_empty')
                && $this->dayEndLocal($date) <= $nowLocal) {
                try {
                    $service->buildDay($this->operation, $date);
                    $data = $builder->build($this->operation, $date, $shift);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $this->items[] = [
                'date'       => $date,
                // Fecha local tal cual (sin pasar por la zona horaria).
                'date_label' => date('d/m/Y', strtotime($date)),
                'data'       => $data,
            ];
        }
    }

    /**
     * Dias operativos (Y-m-d) cuyo punto medio cae en [date_from, date_to].
     *
     * @return string[]
     */
    protected function operativeDays(): array
    {
        $fromLocal = Formatter::time()->convert($this->date_from, 'Y-m-d H:i:s');
        $toLocal = Formatter::time()->convert($this->date_to, 'Y-m-d H:i:s');
        $dayStart = $this->operation->dayStart();

        $days = [];
        $cursor = (new \DateTime(substr($fromLocal, 0, 10)))->modify('-1 day');
        $last = substr($toLocal, 0, 10);

        for (; $cursor->format('Y-m-d') <= $last && count($days) < self::MAX_DAYS; $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $mid = (new \DateTime($date . ' ' . $dayStart . ':00'))->modify('+12 hours')->format('Y-m-d H:i:s');

            if ($mid >= $fromLocal && $mid <= $toLocal) {
                $days[] = $date;
            }
        }

        return $days;
    }

    protected function dayEndLocal(string $date): string
    {
        return (new \DateTime($date . ' ' . $this->operation->dayStart() . ':00'))->modify('+1 day')->format('Y-m-d H:i:s');
    }
}
