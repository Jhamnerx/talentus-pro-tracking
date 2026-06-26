<?php

namespace Tobuli\Entities;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Tobuli\Helpers\PolygonHelper;
use Tobuli\Traits\ChangeLogs;
use Tobuli\Traits\DisplayTrait;
use Tobuli\Traits\Filterable;
use Tobuli\Traits\Searchable;

class Geofence extends AbstractEntity implements DisplayInterface
{
    use ChangeLogs;
    use DisplayTrait;
    use HasFactory;
    use Filterable;
    use Searchable;

    const TYPE_CIRCLE = 'circle';
    const TYPE_POLYGON = 'polygon';

    public static string $displayField = 'name';

    protected $table = 'geofences';

    protected $fillable = [
        'user_id',
        'group_id',
        'device_id',
        'name',
        'active',
        'polygon_color',
        'speed_limit',
        'type',
        'radius',
        'center',
        'polygon',
        'additional',
    ];

    protected $hidden = array('polygon');

    protected $casts = [
        'radius' => 'float',
        'additional' => 'json',
    ];

    protected $searchable = [
        'name',
    ];

    protected $filterables = [
        'group_id',
    ];

    protected $polygonHelpers = [];

    protected static function boot()
    {
        parent::boot();

        static::saved(function (Geofence $item) {
            if (!$item->wasChanged(['type', 'coordinates']) && !$item->wasRecentlyCreated) {
                return;
            }

            $polygon = $item->type === self::TYPE_POLYGON
                ? "ST_PolygonFromText('POLYGON((" . gen_polygon_text(json_decode($item->coordinates, true)) . "))')"
                : 'NULL';

            DB::unprepared("UPDATE geofences SET polygon = $polygon WHERE id = $item->id");
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(GeofenceGroup::class);
    }

    public function diemRate(): BelongsTo
    {
        return $this->belongsTo(DiemRate::class);
    }

    public function vehicleSpeedLimits(): HasMany
    {
        return $this->hasMany(GeofenceVehicleSpeedLimit::class);
    }

    /**
     * Resolves the speed limit that applies to a given vehicle type/state in this
     * geofence, falling back to the type-level default and finally to the legacy
     * single speed_limit column.
     */
    public function speedLimitFor(?VehicleType $vehicleType, ?VehicleState $vehicleState): ?float
    {
        if ($vehicleType) {
            $limits = $this->relationLoaded('vehicleSpeedLimits')
                ? $this->vehicleSpeedLimits
                : $this->vehicleSpeedLimits()->where('vehicle_type_id', $vehicleType->id)->get();

            $limits = $limits->where('vehicle_type_id', $vehicleType->id);

            if ($vehicleState) {
                $exact = $limits->firstWhere('vehicle_state_id', $vehicleState->id);

                if ($exact)
                    return (float) $exact->speed_limit;
            }

            $default = $limits->firstWhere('vehicle_state_id', null);

            if ($default)
                return (float) $default->speed_limit;
        }

        return empty($this->speed_limit) ? null : (float) $this->speed_limit;
    }

    public function setPolygonAttribute($value)
    {
        if (is_array($value))
            $value = json_encode($value);

        $this->attributes['coordinates'] = $value;
    }

    public function getGroupIdAttribute($value)
    {
        if (is_null($value))
            return 0;

        return $value;
    }

    public function setGroupIdAttribute($value)
    {
        if (empty($value))
            $value = null;

        $this->attributes['group_id'] = $value;
    }

    public function setDeviceIdAttribute($value)
    {
        if (empty($value))
            $value = null;

        $this->attributes['device_id'] = $value;
    }

    public function setCenterAttribute($value)
    {
        if (is_string($value))
            $value = json_decode($value, true);

        if (is_array($value))
            $value = json_encode([
                'lat' => round($value['lat'], 8),
                'lng' => round($value['lng'], 8)
            ]);

        $this->attributes['center'] = $value;
    }

    public function getCenterAttribute($value)
    {
        return json_decode($value, true);
    }

    public function pointIn($data)
    {
        if (is_object($data)) {
            $point = [
                'lat' => $data->latitude,
                'lng' => $data->longitude
            ];
        } elseif (is_array($data)) {
            $point = [
                'lat' => $data['latitude'],
                'lng' => $data['longitude']
            ];
        } elseif (is_string($data)) {
            $coordinates = explode(" ", $data);
            $point = [
                'lat' => $coordinates[0],
                'lng' => $coordinates[1]
            ];
        } else {
            return null;
        }

        if ($this->type == 'circle')
            return $this->pointInCircle($point);

        return $this->pointInPolygon($point);
    }

    public function pointOut($data)
    {
        return ! $this->pointIn($data);
    }

    /**
     * @param $point ['latitude' => x, 'longitude' => y]
     * @return float|int
     */
    public function pointAwayBy($point)
    {
        if ($this->pointIn($point))
            return 0;

        $center = $this->getCenter();

        return getDistance($center['lat'], $center['lng'], $point['latitude'], $point['longitude']);
    }

    public function getCenter()
    {
        if ($this->type == self::TYPE_CIRCLE)
            return $this->center;

        return $this->getPolygonHelper()->getCenter();
    }

    public function approxAreaSqMeters(): ?float
    {
        if ($this->type === self::TYPE_CIRCLE) {
            if (empty($this->radius))
                return null;

            return M_PI * pow($this->radius, 2);
        }

        $area = $this->getPolygonHelper()->getArea();

        if (empty($area))
            return null;

        $center = $this->getCenter();

        if (empty($center))
            return null;

        $metersPerDegreeLat = 111320;
        $metersPerDegreeLng = 111320 * cos(deg2rad($center['lat']));

        return $area * $metersPerDegreeLat * $metersPerDegreeLng;
    }

    public static function pickBySpeedLimitPriority(iterable $geofences): ?self
    {
        $candidates = collect($geofences)->filter(function (self $geofence) {
            return !empty($geofence->speed_limit);
        });

        if ($candidates->isEmpty())
            return null;

        return $candidates->sortBy(function (self $geofence) {
            $area = $geofence->approxAreaSqMeters();

            return is_null($area) ? PHP_FLOAT_MAX : $area;
        })->first();
    }

    private function pointInPolygon($point)
    {
        return false !== $this->getPolygonHelper()->pointInPolygon($point);
    }

    private function pointInCircle($point)
    {
        $center = $this->center;

        return $this->radius > (getDistance($center['lat'], $center['lng'], $point['lat'], $point['lng']) * 1000);
    }

    /**
     * @return PolygonHelper
     */
    private function getPolygonHelper()
    {
        if (!isset($this->polygonHelpers[$this->id])) {
            $coordinates = json_decode($this->coordinates, TRUE);

            $this->polygonHelpers[$this->id] = new PolygonHelper($coordinates ?? []);
        }

        return $this->polygonHelpers[$this->id];
    }

    public function scopeVisible($query)
    {
        $query->where('active', 1);
    }

    public function scopeUserOwned(Builder $query, User $user): Builder
    {
        return $query->where(['user_id' => $user->id]);
    }

    public function scopeUserAccessible(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->userOwned($user);
        });
    }

    public function scopeContainPoint(Builder $query, $lat, $lng): Builder
    {
        return $query->where(function (Builder $query) use ($lat, $lng) {
            $query->where(function (Builder $query) use ($lat, $lng) {
                $query
                    ->where('geofences.type', self::TYPE_POLYGON)
                    ->whereRaw("ST_CONTAINS(geofences.polygon, POINT($lat, $lng))");
            });

            $query->orWhere(function (Builder $query) use ($lat, $lng) {
                $query
                    ->where('geofences.type', self::TYPE_CIRCLE)
                    ->whereRaw("ST_DISTANCE_SPHERE_2D(
                        $lat,
                        $lng,
                        SUBSTRING_INDEX(SUBSTRING_INDEX(geofences.center, 'lat\":', -1), ',', 1),
                        SUBSTRING_INDEX(SUBSTRING_INDEX(geofences.center, 'lng\":', -1), '}', 1)
                    ) <= geofences.radius");
            });
        });
    }
}
