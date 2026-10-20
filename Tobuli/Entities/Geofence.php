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

    /**
     * Corredor: una linea con anchura. La linea va en `coordinates`, igual que
     * el poligono, y `radius` es la distancia maxima al eje en metros.
     *
     * Se apoya en el mismo pointIn() que el resto, asi que alertas, reportes,
     * limites por tipo de vehiculo y el pipeline de historial lo entienden sin
     * tocar nada de eso.
     */
    const TYPE_LINE = 'line';

    /**
     * Uso de la geocerca dentro de una operacion de acarreo (modulo de
     * Operaciones). `type` ya esta ocupado por la forma, por eso va aparte.
     * Carga y descarga son los puntos entre los que se cuentan las vueltas;
     * grifo, taller y descanso son zonas donde una parada queda autorizada.
     */
    const CATEGORY_LOAD = 'carga';
    const CATEGORY_UNLOAD = 'descarga';
    const CATEGORY_FUEL = 'grifo';
    const CATEGORY_WORKSHOP = 'taller';
    const CATEGORY_REST = 'descanso';
    // Operaciones de ruta: el punto donde empieza y donde termina la vuelta.
    const CATEGORY_ROUTE_START = 'inicio_ruta';
    const CATEGORY_ROUTE_END = 'fin_ruta';

    const CATEGORIES = [
        self::CATEGORY_LOAD,
        self::CATEGORY_UNLOAD,
        self::CATEGORY_ROUTE_START,
        self::CATEGORY_ROUTE_END,
        self::CATEGORY_FUEL,
        self::CATEGORY_WORKSHOP,
        self::CATEGORY_REST,
    ];

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
        'category',
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
        'category',
    ];

    protected $polygonHelpers = [];

    protected static function boot()
    {
        parent::boot();

        static::saved(function (Geofence $item) {
            if (!$item->wasChanged(['type', 'coordinates']) && !$item->wasRecentlyCreated) {
                return;
            }

            $coordinates = json_decode($item->coordinates, true) ?: [];

            if ($item->type === self::TYPE_POLYGON) {
                $polygon = "ST_PolygonFromText('POLYGON((" . gen_polygon_text($coordinates) . "))')";
            } elseif ($item->type === self::TYPE_LINE && count($coordinates) >= 2) {
                // La columna es `geometry`, no `polygon`: admite la linea.
                $polygon = "ST_LineStringFromText('LINESTRING(" . gen_polygon_text($coordinates) . ")')";
            } else {
                $polygon = 'NULL';
            }

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

    /** Puntos de control de rutas que usan esta geocerca (se borran en cascada). */
    public function routeCheckpoints(): HasMany
    {
        return $this->hasMany(RouteCheckpoint::class, 'geofence_id');
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

    /**
     * Limite que aplica a un dispositivo en esta geocerca, usando la
     * clasificacion (tipo/estado) que el DUEÑO de la geocerca le asigno.
     * Otros usuarios con el dispositivo compartido no afectan al resultado.
     */
    public function speedLimitForDevice(Device $device): ?float
    {
        $ownerId = (int) $this->user_id;

        // Geocerca sin dueño: no hay clasificacion que consultar, solo el legacy.
        if (!$ownerId)
            return $this->speedLimitFor(null, null);

        return $this->speedLimitFor(
            $device->vehicleTypeFor($ownerId),
            $device->vehicleStateFor($ownerId)
        );
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

        if ($this->type == self::TYPE_CIRCLE)
            return $this->pointInCircle($point);

        if ($this->type == self::TYPE_LINE)
            return $this->pointNearLine($point);

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

        // Corredor: largo por ancho. Sale pequeno frente a un poligono grande,
        // que es justo lo que quiere pickBySpeedLimitPriority: si la unidad
        // esta dentro de los dos, manda el limite del corredor.
        if ($this->type === self::TYPE_LINE) {
            $largo = $this->lineLengthMeters();

            if (empty($largo) || empty($this->radius))
                return null;

            return $largo * 2 * (float) $this->radius;
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
     * Dentro del corredor: a menos de `radius` metros del eje de la linea.
     *
     * Se proyecta a metros alrededor del propio punto (equirectangular) en vez
     * de ir con haversine tramo a tramo: los tramos son cortos y asi la
     * distancia punto-segmento es una cuenta plana, que es lo que hace falta
     * cuando esto se llama una vez por posicion y por geocerca.
     */
    private function pointNearLine($point): bool
    {
        $radio = (float) $this->radius;

        if ($radio <= 0)
            return false;

        $coordinates = $this->lineCoordinates();

        if (count($coordinates) < 2)
            return false;

        $metrosPorLat = 111320.0;
        $metrosPorLng = 111320.0 * cos(deg2rad((float) $point['lat']));

        // Descarte barato antes de medir: el punto tiene que caer dentro de la
        // caja de la linea ensanchada por el radio.
        $margenLat = $radio / $metrosPorLat;
        $margenLng = $metrosPorLng > 0 ? $radio / $metrosPorLng : 180;

        $caja = $this->lineBounds($coordinates);

        if ($point['lat'] < $caja['minLat'] - $margenLat || $point['lat'] > $caja['maxLat'] + $margenLat
            || $point['lng'] < $caja['minLng'] - $margenLng || $point['lng'] > $caja['maxLng'] + $margenLng
        ) {
            return false;
        }

        $anterior = null;

        foreach ($coordinates as $coordinate) {
            $x = ((float) $coordinate['lng'] - (float) $point['lng']) * $metrosPorLng;
            $y = ((float) $coordinate['lat'] - (float) $point['lat']) * $metrosPorLat;

            if ($anterior !== null
                && self::distanceToSegment(0.0, 0.0, $anterior[0], $anterior[1], $x, $y) <= $radio
            ) {
                return true;
            }

            $anterior = [$x, $y];
        }

        return false;
    }

    /** Distancia de un punto al segmento AB, todo en metros planos. */
    private static function distanceToSegment(float $px, float $py, float $ax, float $ay, float $bx, float $by): float
    {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $largo2 = $dx * $dx + $dy * $dy;

        if ($largo2 <= 0.0)
            return sqrt(pow($px - $ax, 2) + pow($py - $ay, 2));

        // Proyeccion del punto sobre el segmento, recortada a sus extremos
        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $largo2;
        $t = max(0.0, min(1.0, $t));

        return sqrt(pow($px - ($ax + $t * $dx), 2) + pow($py - ($ay + $t * $dy), 2));
    }

    /** Largo del eje del corredor, en metros. */
    public function lineLengthMeters(): ?float
    {
        $coordinates = $this->lineCoordinates();

        if (count($coordinates) < 2)
            return null;

        $total = 0.0;
        $anterior = null;

        foreach ($coordinates as $coordinate) {
            if ($anterior !== null) {
                $total += getDistance(
                    $anterior['lat'], $anterior['lng'],
                    $coordinate['lat'], $coordinate['lng']
                ) * 1000;
            }

            $anterior = $coordinate;
        }

        return $total;
    }

    private function lineCoordinates(): array
    {
        $coordinates = json_decode($this->coordinates, true);

        return is_array($coordinates) ? array_values($coordinates) : [];
    }

    private function lineBounds(array $coordinates): array
    {
        $lats = array_column($coordinates, 'lat');
        $lngs = array_column($coordinates, 'lng');

        return [
            'minLat' => min($lats), 'maxLat' => max($lats),
            'minLng' => min($lngs), 'maxLng' => max($lngs),
        ];
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

    /** @param string|string[] $category una categoria o varias (ver CATEGORIES). */
    public function scopeCategory(Builder $query, $category): Builder
    {
        return $query->whereIn('geofences.category', (array) $category);
    }

    /** Categorias con su etiqueta traducida, para selects y filtros. */
    public static function categories(): array
    {
        $list = [];

        foreach (self::CATEGORIES as $category) {
            $list[$category] = trans('front.geofence_category_' . $category);
        }

        return $list;
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
                // Corredor: distancia del punto a la linea guardada. ST_Distance
                // devuelve grados, asi que se compara contra el radio pasado a
                // grados de latitud. En longitud eso ensancha el corredor un
                // 1/cos(lat) (aqui, menos del 2%): sobra para este filtro, que
                // solo alimenta el listado de "zonas en las que esta".
                // El calculo fino, el que usan alertas y reportes, es pointIn().
                $query
                    ->where('geofences.type', self::TYPE_LINE)
                    ->whereRaw("ST_DISTANCE(geofences.polygon, ST_GeomFromText(CONCAT('POINT(', $lat, ' ', $lng, ')'))) <= geofences.radius / 111320");
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
