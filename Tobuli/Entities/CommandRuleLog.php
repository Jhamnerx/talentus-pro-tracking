<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de una norma de comando: cada consulta, respuesta evaluada y accion.
 */
class CommandRuleLog extends AbstractEntity
{
    public const PHASE_PROBE_SENT    = 'probe_sent';
    public const PHASE_PROBE_FAILED  = 'probe_failed';
    public const PHASE_PROBE_SKIPPED = 'probe_skipped';
    public const PHASE_RESPONSE      = 'response';
    public const PHASE_MATCHED       = 'matched';
    public const PHASE_ACTION_SENT   = 'action_sent';
    public const PHASE_ACTION_FAILED = 'action_failed';

    public const PHASES = [
        self::PHASE_PROBE_SENT,
        self::PHASE_PROBE_FAILED,
        self::PHASE_PROBE_SKIPPED,
        self::PHASE_RESPONSE,
        self::PHASE_MATCHED,
        self::PHASE_ACTION_SENT,
        self::PHASE_ACTION_FAILED,
    ];

    public const UPDATED_AT = null;

    protected $table = 'command_rule_logs';

    protected $fillable = [
        'command_rule_id',
        'command_rule_condition_id',
        'device_id',
        'phase',
        'message',
        'created_at',
    ];

    protected $casts = [
        'id'                        => 'integer',
        'command_rule_id'           => 'integer',
        'command_rule_condition_id' => 'integer',
        'device_id'                 => 'integer',
        'created_at'                => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommandRule::class, 'command_rule_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(CommandRuleCondition::class, 'command_rule_condition_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public static function record(CommandRule $rule, Device $device, string $phase, ?string $message = null, ?CommandRuleCondition $condition = null): self
    {
        return static::create([
            'command_rule_id'           => $rule->id,
            'command_rule_condition_id' => $condition ? $condition->id : null,
            'device_id'                 => $device->id,
            'phase'                     => $phase,
            'message'                   => $message !== null ? mb_substr($message, 0, 4000) : null,
            'created_at'                => now(),
        ]);
    }
}
