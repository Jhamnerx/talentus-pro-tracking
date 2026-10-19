<?php

namespace Tobuli\Entities;

class SecurityDailyStat extends AbstractEntity
{
    const SHIFT_DAY = 'day';
    const SHIFT_NIGHT = 'night';

    const STATE_NO_DATA = 'no_data';
    const STATE_MOVING = 'moving';
    const STATE_STANDBY = 'standby';
    const STATE_INACTIVE = 'inactive';

    public $timestamps = false;

    protected $table = 'security_daily_stats';
    protected $guarded = [];
    protected $casts = [
        'work_date'         => 'date',
        'range_from'        => 'datetime',
        'range_to'          => 'datetime',
        'calculated_at'     => 'datetime',
        'distance_km'       => 'float',
        'speed_max'         => 'float',
        'engine_seconds'    => 'int',
        'moving_seconds'    => 'int',
        'stop_count'        => 'int',
        'overspeed_events'  => 'int',
        'overspeed_calc'    => 'int',
        'overspeed_total'   => 'int',
        'zone_exits'        => 'int',
        'disconnect_events' => 'int',
        'signal_gaps'       => 'int',
        'positions'         => 'int',
        'seatbelt_alerts'   => 'int',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Estado diario del Excel: Movimiento / Stand by* / Sin actividad. */
    public function dailyState(): string
    {
        if ($this->positions <= 0) {
            return self::STATE_NO_DATA;
        }

        if ($this->moving_seconds > 0) {
            return self::STATE_MOVING;
        }

        if ($this->engine_seconds > 0) {
            return self::STATE_STANDBY;
        }

        return self::STATE_INACTIVE;
    }

    public static function shifts(): array
    {
        return [self::SHIFT_DAY, self::SHIFT_NIGHT];
    }
}
