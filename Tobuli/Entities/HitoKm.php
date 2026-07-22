<?php

namespace Tobuli\Entities;

/**
 * Hito kilométrico de la red vial nacional del Perú (dataset Provías,
 * actualización semestral vía hitos:import). Ver docs/implementacion_hitos_km.md.
 */
class HitoKm extends AbstractEntity
{
    protected $table = 'hitos_km';

    protected $guarded = [];

    public $timestamps = false;

    /** Versión más reciente cargada (cacheada por request). */
    protected static $currentVersion;

    public static function currentVersion(): ?string
    {
        if (static::$currentVersion === null) {
            static::$currentVersion = static::max('version');
        }

        return static::$currentVersion;
    }

    public function scopeCurrent($query)
    {
        return $query->where('version', static::currentVersion());
    }
}
