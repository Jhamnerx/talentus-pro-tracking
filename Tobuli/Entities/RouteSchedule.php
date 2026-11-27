<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Tobuli\Traits\ChangeLogs;
use Tobuli\Traits\DisplayTrait;
use Tobuli\Traits\Searchable;

/**
 * Programacion de una ruta: a que hora sale la vuelta, que dias, con que
 * tolerancia y que vehiculos la tienen asignada.
 *
 * Una programacion describe UNA vuelta diaria. Si la unidad da tres vueltas
 * al dia se crean tres programaciones, que es mas claro de leer en el reporte
 * que una sola con una lista de horas.
 */
class RouteSchedule extends AbstractEntity implements DisplayInterface
{
    use ChangeLogs;
    use DisplayTrait;
    use Searchable;

    public static string $displayField = 'name';

    protected $table = 'route_schedules';

    protected $fillable = [
        'user_id',
        'route_id',
        'name',
        'weekdays',
        'start_time',
        'return_start_time',
        'tolerance_early',
        'tolerance_late',
        'active',
    ];

    protected $casts = [
        'active'          => 'bool',
        'tolerance_early' => 'int',
        'tolerance_late'  => 'int',
    ];

    protected $searchable = [
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_route_schedule', 'route_schedule_id', 'device_id');
    }

    public function scopeUserOwned(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /** @return int[] Dias ISO-8601: 1 lunes ... 7 domingo. */
    public function getWeekdaysListAttribute(): array
    {
        $dias = array_filter(array_map('intval', explode(',', (string) $this->weekdays)));

        return array_values(array_unique($dias));
    }

    public function runsOn(int $isoWeekday): bool
    {
        $dias = $this->weekdays_list;

        // Sin dias marcados la programacion no corre ningun dia: es mas seguro
        // que asumir "todos" y llenar el reporte de vueltas que nadie planifico.
        return in_array($isoWeekday, $dias, true);
    }

    public function hasReturn(): bool
    {
        return ! empty($this->return_start_time);
    }

    /** Horas de salida por sentido, en la hora local del usuario. */
    public function startTimes(): array
    {
        $horas = [RouteCheckpoint::DIRECTION_OUTBOUND => $this->start_time];

        if ($this->hasReturn()) {
            $horas[RouteCheckpoint::DIRECTION_INBOUND] = $this->return_start_time;
        }

        return $horas;
    }
}
