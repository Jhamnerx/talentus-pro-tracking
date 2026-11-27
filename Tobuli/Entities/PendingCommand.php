<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comando GPRS en cola (feature "Auto-send when online" de la UI v2):
 * se crea cuando el usuario marca auto-envío y el dispositivo está
 * desconectado; commands:dispatch-pending lo envía al reconectar.
 */
class PendingCommand extends AbstractEntity
{
    protected $table = 'pending_commands';

    protected $guarded = [];

    protected $casts = [
        'parameters' => 'array',
        'sent_at'    => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';
    const STATUS_EXPIRED = 'expired';

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
