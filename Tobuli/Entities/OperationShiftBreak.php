<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Descanso de un turno (Operaciones, reporte de turno).
 *
 * - `scheduled`: almuerzo o refrigerio con hora de inicio y fin dentro del
 *   turno. El primero de la lista define las columnas "antes" y "despues del
 *   descanso". Si end <= start cruza la medianoche, igual que el turno.
 * - `fatigue`: fatiga y somnolencia, sin horario. Cuenta cada parada con
 *   motor apagado fuera de los descansos programados. Uno por turno.
 */
class OperationShiftBreak extends AbstractEntity
{
    const KIND_SCHEDULED = 'scheduled';
    const KIND_FATIGUE = 'fatigue';

    const KINDS = [
        self::KIND_SCHEDULED,
        self::KIND_FATIGUE,
    ];

    protected $table = 'operation_shift_breaks';

    protected $fillable = [
        'shift_id',
        'name',
        'kind',
        'start_time',
        'end_time',
        'position',
    ];

    protected $casts = [
        'position' => 'int',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(OperationShift::class, 'shift_id');
    }

    public function isScheduled(): bool
    {
        return $this->kind === self::KIND_SCHEDULED;
    }

    public function isFatigue(): bool
    {
        return $this->kind === self::KIND_FATIGUE;
    }

    public function crossesMidnight(): bool
    {
        return OperationShift::seconds((string) $this->end_time) <= OperationShift::seconds((string) $this->start_time);
    }

    /**
     * Solo un descanso programado cubre horas; el de fatiga nunca.
     *
     * @param string $localTime hora local H:i o H:i:s
     */
    public function covers(string $localTime): bool
    {
        if (! $this->isScheduled() || ! $this->start_time || ! $this->end_time) {
            return false;
        }

        $t = OperationShift::seconds($localTime);
        $start = OperationShift::seconds((string) $this->start_time);
        $end = OperationShift::seconds((string) $this->end_time);

        if ($this->crossesMidnight()) {
            return $t >= $start || $t < $end;
        }

        return $t >= $start && $t < $end;
    }

    /** Dos descansos programados se solapan si alguno cubre el inicio del otro. */
    public function overlaps(OperationShiftBreak $other): bool
    {
        if (! $this->isScheduled() || ! $other->isScheduled()) {
            return false;
        }

        return $this->covers((string) $other->start_time) || $other->covers((string) $this->start_time);
    }

    public static function kindLabels(): array
    {
        return [
            self::KIND_SCHEDULED => trans('front.operation_break_kind_scheduled'),
            self::KIND_FATIGUE   => trans('front.operation_break_kind_fatigue'),
        ];
    }
}
