<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Model;

class SecurityDailyStat extends Model
{
    const SHIFT_DAY = 'day';
    const SHIFT_NIGHT = 'night';

    protected $table = 'security_daily_stats';
    protected $guarded = [];
    protected $casts = [
        'work_date'     => 'date',
        'range_from'    => 'datetime',
        'range_to'      => 'datetime',
        'calculated_at' => 'datetime',
        'distance_km'   => 'float',
        'speed_max'     => 'float',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** Estado diario del Excel: Movimiento / Stand by* / Sin actividad. */
    public function dailyState(): string
    {
        if ($this->positions <= 0) return 'no_data';
        if ($this->moving_seconds > 0) return 'moving';
        if ($this->engine_seconds > 0) return 'standby';

        return 'inactive';
    }

    public static function shifts(): array
    {
        return [self::SHIFT_DAY, self::SHIFT_NIGHT];
    }
}
