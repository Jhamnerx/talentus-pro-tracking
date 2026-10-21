<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tobuli\Traits\ChangeLogs;
use Tobuli\Traits\DisplayTrait;
use Tobuli\Traits\Searchable;

/**
 * Operacion de acarreo: un grupo de vehiculos que da vueltas entre puntos de
 * carga y puntos de descarga, con sus turnos y umbrales.
 *
 * Borrado logico: sus vueltas siguen en los reportes. Todo lo que busque
 * operaciones "vivas" debe filtrar por `active` y dejar que SoftDeletes
 * excluya las eliminadas.
 */
class Operation extends AbstractEntity implements DisplayInterface
{
    use ChangeLogs;
    use DisplayTrait;
    use Searchable;
    use SoftDeletes;

    // Acarreo (carga/descarga) o ruta (inicio/fin). Misma maquina de estados:
    // el rol 'load' es el punto donde empieza la vuelta y 'unload' el otro extremo.
    const KIND_HAUL = 'haul';
    const KIND_ROUTE = 'route';

    // Descuentos de las horas trabajadas netas (fase 3), en el orden del plan.
    const DEDUCTION_REFUELING = 'refueling';
    const DEDUCTION_IDLE = 'idle';
    const DEDUCTION_UNAUTHORIZED_STOPS = 'unauthorized_stops';

    const DEDUCTIONS = [
        self::DEDUCTION_REFUELING,
        self::DEDUCTION_IDLE,
        self::DEDUCTION_UNAUTHORIZED_STOPS,
    ];

    public static string $displayField = 'name';

    protected $table = 'operations';

    protected $fillable = [
        'user_id',
        'name',
        'kind',
        'device_group_id',
        'active',
        'min_dwell_seconds',
        'stop_threshold_seconds',
        'max_leg_hours',
        'day_start',
        'vehicle_type_id',
        'loaded_state_id',
        'empty_state_id',
        'authorized_categories',
        'worked_hours_deductions',
    ];

    protected $casts = [
        'active'                  => 'bool',
        'min_dwell_seconds'       => 'int',
        'stop_threshold_seconds'  => 'int',
        'max_leg_hours'           => 'int',
        'vehicle_type_id'         => 'int',
        'loaded_state_id'         => 'int',
        'empty_state_id'          => 'int',
        'authorized_categories'   => 'array',
        'worked_hours_deductions' => 'array',
    ];

    protected $searchable = [
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(OperationShift::class)->orderBy('position')->orderBy('start_time');
    }

    /** Tipo de vehiculo (Seguridad) cuyo estado cambia solo segun el sentido del viaje. */
    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    /** Estado que se pone al salir de carga (tramo de ida). */
    public function loadedState(): BelongsTo
    {
        return $this->belongsTo(VehicleState::class, 'loaded_state_id');
    }

    /** Estado que se pone al salir de descarga (tramo de vuelta). */
    public function emptyState(): BelongsTo
    {
        return $this->belongsTo(VehicleState::class, 'empty_state_id');
    }

    /**
     * Hora local (H:i) a la que empieza el dia operativo: `day_start` o, si
     * es nulo, el inicio del primer turno por posicion; 00:00 sin turnos.
     */
    public function dayStart(): string
    {
        if ($this->day_start) {
            return substr((string) $this->day_start, 0, 5);
        }

        $first = $this->shifts->first();

        return $first ? substr((string) $first->start_time, 0, 5) : '00:00';
    }

    /** Todas las versiones de todos los puntos. */
    public function points(): HasMany
    {
        return $this->hasMany(OperationPoint::class);
    }

    /** Solo las versiones vigentes. */
    public function currentPoints(): HasMany
    {
        return $this->hasMany(OperationPoint::class)->whereNull('active_to');
    }

    public function deviceStates(): HasMany
    {
        return $this->hasMany(OperationDeviceState::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(OperationCycle::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(OperationVisit::class);
    }

    public function recalculations(): HasMany
    {
        return $this->hasMany(OperationRecalculation::class);
    }

    public function scopeUserOwned(Builder $query, User $user): Builder
    {
        return $query->where('operations.user_id', $user->id);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('operations.active', 1);
    }

    /**
     * Vehiculos del grupo, tal como los ve el dueño de la operacion.
     * DeviceGroup::devices() ya filtra el pivot por el user_id del grupo.
     *
     * @return \Illuminate\Support\Collection<int> ids de devices
     */
    public function deviceIds()
    {
        if (! $this->deviceGroup) {
            return collect();
        }

        return $this->deviceGroup->devices()->pluck('devices.id');
    }

    public static function kinds(): array
    {
        return [
            self::KIND_HAUL  => trans('front.operation_kind_haul'),
            self::KIND_ROUTE => trans('front.operation_kind_route'),
        ];
    }

    public function isRoute(): bool
    {
        return $this->kind === self::KIND_ROUTE;
    }

    /** Etiquetas de los dos roles de punto segun el tipo de operacion. */
    public static function roleLabels(?string $kind): array
    {
        return $kind === self::KIND_ROUTE
            ? ['load' => trans('front.operation_route_start'), 'unload' => trans('front.operation_route_end')]
            : ['load' => trans('front.geofence_category_carga'), 'unload' => trans('front.geofence_category_descarga')];
    }

    /** Categorias de geocerca que corresponden a cada rol segun el tipo. */
    public static function roleCategories(?string $kind): array
    {
        return $kind === self::KIND_ROUTE
            ? ['load' => Geofence::CATEGORY_ROUTE_START, 'unload' => Geofence::CATEGORY_ROUTE_END]
            : ['load' => Geofence::CATEGORY_LOAD, 'unload' => Geofence::CATEGORY_UNLOAD];
    }

    /** Etiquetas de estado en vivo segun el tipo. */
    public static function stateLabels(?string $kind): array
    {
        $route = $kind === self::KIND_ROUTE;

        return [
            OperationDeviceState::STATE_UNKNOWN   => trans('ui.ops_state_unknown'),
            OperationDeviceState::STATE_AT_LOAD   => trans($route ? 'ui.ops_state_at_start' : 'ui.ops_state_at_load'),
            OperationDeviceState::STATE_OUTBOUND  => trans('ui.ops_state_outbound'),
            OperationDeviceState::STATE_AT_UNLOAD => trans($route ? 'ui.ops_state_at_end' : 'ui.ops_state_at_unload'),
            OperationDeviceState::STATE_INBOUND   => trans('ui.ops_state_inbound'),
            OperationDeviceState::STATE_NO_DATA   => trans('ui.ops_state_no_data'),
        ];
    }

    /** @return string[] descuentos activos para las horas trabajadas netas */
    public function workedHoursDeductions(): array
    {
        $list = $this->worked_hours_deductions;

        return is_array($list) ? array_values(array_intersect($list, self::DEDUCTIONS)) : [];
    }

    public static function deductionLabels(): array
    {
        return [
            self::DEDUCTION_REFUELING          => trans('front.operation_deduction_refueling'),
            self::DEDUCTION_IDLE               => trans('front.operation_deduction_idle'),
            self::DEDUCTION_UNAUTHORIZED_STOPS => trans('front.operation_deduction_unauthorized_stops'),
        ];
    }

    /** @return string[] categorias de geocerca donde una parada es autorizada */
    public function authorizedCategories(): array
    {
        $list = $this->authorized_categories;

        if (! is_array($list)) {
            return [Geofence::CATEGORY_FUEL, Geofence::CATEGORY_WORKSHOP, Geofence::CATEGORY_REST];
        }

        return array_values(array_intersect($list, Geofence::CATEGORIES));
    }

    /** Turno vigente a una hora local (H:i:s) del dueño, o null si cae en un hueco. */
    public function shiftAt(string $localTime): ?OperationShift
    {
        foreach ($this->shifts as $shift) {
            if ($shift->covers($localTime)) {
                return $shift;
            }
        }

        return null;
    }
}
