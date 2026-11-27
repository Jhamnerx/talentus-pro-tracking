<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de control de una ruta: una geocerca por la que la unidad tiene que
 * pasar, en un orden y a una hora prevista dentro de la vuelta.
 */
class RouteCheckpoint extends AbstractEntity
{
    const DIRECTION_OUTBOUND = 'outbound';
    const DIRECTION_INBOUND  = 'inbound';

    protected $table = 'route_checkpoints';

    protected $fillable = [
        'route_id',
        'geofence_id',
        'direction',
        'position',
        'name',
        'planned_offset',
        'tolerance_early',
        'tolerance_late',
    ];

    protected $casts = [
        'position'        => 'int',
        'planned_offset'  => 'int',
        'tolerance_early' => 'int',
        'tolerance_late'  => 'int',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public static function directions(): array
    {
        return [
            self::DIRECTION_OUTBOUND => trans('front.route_direction_outbound'),
            self::DIRECTION_INBOUND  => trans('front.route_direction_inbound'),
        ];
    }

    /** Etiqueta propia si la tiene; si no, el nombre de la geocerca. */
    public function getLabelAttribute(): string
    {
        if (! empty($this->attributes['name'])) {
            return $this->attributes['name'];
        }

        return $this->geofence->name ?? '#' . $this->geofence_id;
    }

    /**
     * Tolerancias efectivas: las del punto si las tiene, si no las de la
     * programacion que se este evaluando.
     */
    public function toleranceEarly(RouteSchedule $schedule): int
    {
        return $this->tolerance_early ?? $schedule->tolerance_early;
    }

    public function toleranceLate(RouteSchedule $schedule): int
    {
        return $this->tolerance_late ?? $schedule->tolerance_late;
    }
}
