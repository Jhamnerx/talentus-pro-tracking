<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de carga o descarga de una operacion. Guarda SU PROPIA copia de la
 * geometria de la geocerca: mover la geocerca cierra esta version
 * (`active_to`) y abre otra, asi las vueltas cerradas no cambian.
 */
class OperationPoint extends AbstractEntity
{
    const ROLE_LOAD = 'load';
    const ROLE_UNLOAD = 'unload';

    protected $table = 'operation_points';

    protected $fillable = [
        'operation_id',
        'geofence_id',
        'role',
        'name',
        'geometry',
        'active_from',
        'active_to',
    ];

    protected $casts = [
        'geometry' => 'array',
    ];

    protected $dates = [
        'active_from',
        'active_to',
    ];

    /** @var Geofence|null geocerca transitoria armada desde `geometry` */
    private $shape;

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('active_to');
    }

    /** Version vigente en un instante dado (UTC). */
    public function scopeActiveAt(Builder $query, string $utc): Builder
    {
        return $query
            ->where('active_from', '<=', $utc)
            ->where(function (Builder $q) use ($utc) {
                $q->whereNull('active_to')->orWhere('active_to', '>', $utc);
            });
    }

    public function isLoad(): bool
    {
        return $this->role === self::ROLE_LOAD;
    }

    /** Copia de la geometria de una geocerca, en el formato de `geometry`. */
    public static function geometryFrom(Geofence $geofence): array
    {
        return [
            'type'        => $geofence->type,
            'coordinates' => json_decode($geofence->coordinates, true) ?: [],
            'center'      => $geofence->center,
            'radius'      => $geofence->radius,
        ];
    }

    /** Dos geometrias son iguales si no cambia nada que afecte a pointIn(). */
    public static function sameGeometry(array $a, array $b): bool
    {
        if (($a['type'] ?? null) !== ($b['type'] ?? null)) {
            return false;
        }

        if (($a['type'] ?? null) === Geofence::TYPE_CIRCLE) {
            return self::sameNumber($a['radius'] ?? null, $b['radius'] ?? null, 0.5)
                && self::sameNumber($a['center']['lat'] ?? null, $b['center']['lat'] ?? null)
                && self::sameNumber($a['center']['lng'] ?? null, $b['center']['lng'] ?? null);
        }

        $ca = array_values($a['coordinates'] ?? []);
        $cb = array_values($b['coordinates'] ?? []);

        if (count($ca) !== count($cb)) {
            return false;
        }

        foreach ($ca as $i => $p) {
            if (! self::sameNumber($p['lat'] ?? null, $cb[$i]['lat'] ?? null)
                || ! self::sameNumber($p['lng'] ?? null, $cb[$i]['lng'] ?? null)) {
                return false;
            }
        }

        if (($a['type'] ?? null) === Geofence::TYPE_LINE) {
            return self::sameNumber($a['radius'] ?? null, $b['radius'] ?? null, 0.5);
        }

        return true;
    }

    /**
     * Geocerca transitoria (no se guarda) para reutilizar pointIn() tal cual:
     * circulo, poligono y corredor sin duplicar la matematica.
     */
    public function shape(): Geofence
    {
        if ($this->shape) {
            return $this->shape;
        }

        $g = $this->geometry ?: [];

        $this->shape = new Geofence([
            'type'    => $g['type'] ?? Geofence::TYPE_POLYGON,
            'polygon' => $g['coordinates'] ?? [],
            'center'  => $g['center'] ?? null,
            'radius'  => $g['radius'] ?? null,
        ]);

        return $this->shape;
    }

    public function contains($latitude, $longitude): bool
    {
        return (bool) $this->shape()->pointIn(['latitude' => $latitude, 'longitude' => $longitude]);
    }

    /** Punto medio aproximado, para dibujar o para medir el cruce del segmento. */
    public function centroid(): ?array
    {
        $g = $this->geometry ?: [];

        if (($g['type'] ?? null) === Geofence::TYPE_CIRCLE && ! empty($g['center'])) {
            return ['lat' => (float) $g['center']['lat'], 'lng' => (float) $g['center']['lng']];
        }

        $coords = array_values($g['coordinates'] ?? []);

        if (! $coords) {
            return null;
        }

        $lat = $lng = 0;

        foreach ($coords as $p) {
            $lat += (float) $p['lat'];
            $lng += (float) $p['lng'];
        }

        return ['lat' => $lat / count($coords), 'lng' => $lng / count($coords)];
    }

    private static function sameNumber($a, $b, float $tolerance = 0.0000005): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) <= $tolerance;
    }
}
