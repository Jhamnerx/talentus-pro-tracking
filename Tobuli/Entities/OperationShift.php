<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de una operacion: nombre y rango horario en la hora local del dueño.
 * Si la hora de fin es menor o igual a la de inicio, termina al dia siguiente
 * (Noche 19:00-07:00 es un solo turno).
 */
class OperationShift extends AbstractEntity
{
    protected $table = 'operation_shifts';

    protected $fillable = [
        'operation_id',
        'name',
        'start_time',
        'end_time',
        'position',
    ];

    protected $casts = [
        'position' => 'int',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** Descansos del turno (programados y por fatiga), en el orden configurado. */
    public function breaks(): HasMany
    {
        return $this->hasMany(OperationShiftBreak::class, 'shift_id')->orderBy('position')->orderBy('id');
    }

    public function crossesMidnight(): bool
    {
        return self::seconds($this->end_time) <= self::seconds($this->start_time);
    }

    /** @param string $localTime hora local H:i o H:i:s */
    public function covers(string $localTime): bool
    {
        $t = self::seconds($localTime);
        $start = self::seconds($this->start_time);
        $end = self::seconds($this->end_time);

        if ($this->crossesMidnight()) {
            return $t >= $start || $t < $end;
        }

        return $t >= $start && $t < $end;
    }

    /**
     * Dia en que empezo el turno para una hora local dada. En un turno que
     * cruza la medianoche, una vuelta de las 02:30 del dia 5 pertenece al
     * turno que empezo el dia 4.
     */
    public function shiftDateFor(\DateTimeInterface $local): string
    {
        $t = self::seconds($local->format('H:i:s'));

        if ($this->crossesMidnight() && $t < self::seconds($this->start_time)) {
            return (clone $local)->modify('-1 day')->format('Y-m-d');
        }

        return $local->format('Y-m-d');
    }

    /** Dos turnos se solapan si alguno cubre el inicio del otro. */
    public function overlaps(OperationShift $other): bool
    {
        return $this->covers($other->start_time) || $other->covers($this->start_time);
    }

    public static function seconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return ($parts[0] ?? 0) * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }
}
