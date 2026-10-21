<?php

namespace Tobuli\Entities;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tobuli\Traits\Searchable;

/**
 * Norma de comando: cada `interval_minutes` envia `query_command` a sus vehiculos;
 * la respuesta se evalua contra las condiciones y, si coincide, se envia la accion.
 *
 * Los comandos (consulta y accion) se envian con el usuario dueño (`user`), que
 * debe ser admin con permiso send_command.edit.
 */
class CommandRule extends AbstractEntity
{
    use Searchable;

    protected $table = 'command_rules';

    protected array $searchable = [
        'name',
        'query_command',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'active',
        'query_command',
        'interval_minutes',
        'only_connected',
        'evaluate_any_response',
        'cooldown_minutes',
        'last_run_at',
    ];

    protected $casts = [
        'id'                    => 'integer',
        'user_id'               => 'integer',
        'active'                => 'boolean',
        'interval_minutes'      => 'integer',
        'only_connected'        => 'boolean',
        'evaluate_any_response' => 'boolean',
        'cooldown_minutes'      => 'integer',
        'last_run_at'           => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'command_rule_devices', 'command_rule_id', 'device_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(CommandRuleCondition::class, 'command_rule_id')->orderBy('position')->orderBy('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommandRuleLog::class, 'command_rule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /** Normas activas cuyo intervalo ya vencio. */
    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?: Carbon::now();

        return $query->active()->where(function (Builder $q) use ($now) {
            $q->whereNull('last_run_at')
              ->orWhereRaw('DATE_ADD(last_run_at, INTERVAL interval_minutes MINUTE) <= ?', [$now->format('Y-m-d H:i:s')]);
        });
    }

    /** Normas activas que incluyen al dispositivo. */
    public function scopeForDevice(Builder $query, int $deviceId): Builder
    {
        return $query->active()->whereHas('devices', function (Builder $q) use ($deviceId) {
            $q->where('devices.id', $deviceId);
        });
    }

    public function isDue(?Carbon $now = null): bool
    {
        if (!$this->last_run_at) {
            return true;
        }

        return $this->last_run_at->copy()->addMinutes($this->interval_minutes)->lte($now ?: Carbon::now());
    }

    /**
     * Minutos que se considera "respuesta a la consulta" tras enviarla.
     * Dos intervalos, nunca menos de 10 min (los equipos pueden tardar en responder).
     */
    public function probeWindowMinutes(): int
    {
        return max(10, $this->interval_minutes * 2);
    }

    public function probeCacheKey(int $deviceId): string
    {
        return "command_rule:{$this->id}:{$deviceId}:probe";
    }

    public function cooldownCacheKey(int $conditionId, int $deviceId): string
    {
        return "command_rule:{$this->id}:{$conditionId}:{$deviceId}:cooldown";
    }
}
