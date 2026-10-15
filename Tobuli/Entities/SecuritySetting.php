<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Model;

class SecuritySetting extends Model
{
    protected $table = 'security_settings';
    protected $guarded = [];
    protected $casts = ['speed_limit' => 'int', 'validity_minutes' => 'int', 'stop_minutes' => 'int', 'gap_minutes' => 'int'];

    public static function forUser(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id]);
    }
}
