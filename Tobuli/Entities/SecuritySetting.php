<?php

namespace Tobuli\Entities;

class SecuritySetting extends AbstractEntity
{
    protected $table = 'security_settings';
    protected $fillable = [
        'user_id',
        'day_start',
        'day_end',
        'speed_limit',
        'validity_minutes',
        'stop_minutes',
        'gap_minutes',
    ];
    // Mismos valores que los DEFAULT de la migracion: firstOrCreate devuelve
    // el modelo recien creado sin hidratar los defaults de la base.
    protected $attributes = [
        'day_start'        => '07:00',
        'day_end'          => '19:00',
        'validity_minutes' => 10,
        'stop_minutes'     => 1,
        'gap_minutes'      => 10,
    ];
    protected $casts = [
        'speed_limit'      => 'int',
        'validity_minutes' => 'int',
        'stop_minutes'     => 'int',
        'gap_minutes'      => 'int',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function forUser(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id]);
    }
}
