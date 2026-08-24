<?php

namespace Tobuli\Entities;

use Tobuli\Traits\ChangeLogs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMaintenance extends Model
{
    use ChangeLogs;

    const TIPO_MANTENIMIENTO  = 'mantenimiento';
    const TIPO_SUSPENSION     = 'suspension';
    const TIPO_REACTIVACION   = 'reactivacion';

    protected $table = 'device_maintenances';

    protected $fillable = [
        'device_id',
        'tipo',
        'fecha',
        'motivo',
        'notas',
        'user_id',
        'synced_at',
    ];

    protected $casts = [
        'fecha'     => 'date',
        'synced_at' => 'datetime',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Tobuli\Entities\User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOfType($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePendingSync($query)
    {
        return $query->whereNull('synced_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getTipoLabelAttribute(): string
    {
        return match ($this->tipo) {
            self::TIPO_SUSPENSION    => 'Suspensión',
            self::TIPO_REACTIVACION  => 'Reactivación',
            default                  => 'Mantenimiento',
        };
    }

    public function getTipoBadgeClassAttribute(): string
    {
        return match ($this->tipo) {
            self::TIPO_SUSPENSION    => 'bg-red-100 text-red-700',
            self::TIPO_REACTIVACION  => 'bg-green-100 text-green-700',
            default                  => 'bg-blue-100 text-blue-700',
        };
    }

    public function markAsSynced(): void
    {
        $this->update(['synced_at' => now()]);
    }
}
