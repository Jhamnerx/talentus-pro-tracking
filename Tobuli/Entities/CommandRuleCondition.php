<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condicion de una norma: como se evalua la respuesta y que comando se envia.
 *
 *  - contains: la respuesta contiene `pattern` (sin distinguir mayusculas)
 *  - regex:    la respuesta cumple la expresion regular `pattern`
 *  - compare:  el valor numerico de `field` en la respuesta (ej. AIN1 en
 *              "DI1:1 AIN1:91 DO1:1") cumple `operator` `value`
 */
class CommandRuleCondition extends AbstractEntity
{
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_REGEX    = 'regex';
    public const MATCH_COMPARE  = 'compare';

    public const MATCH_TYPES = [
        self::MATCH_CONTAINS,
        self::MATCH_REGEX,
        self::MATCH_COMPARE,
    ];

    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    protected $table = 'command_rule_conditions';

    protected $fillable = [
        'command_rule_id',
        'position',
        'match_type',
        'pattern',
        'field',
        'operator',
        'value',
        'action_command',
        'stop_on_match',
    ];

    protected $casts = [
        'id'              => 'integer',
        'command_rule_id' => 'integer',
        'position'        => 'integer',
        'stop_on_match'   => 'boolean',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommandRule::class, 'command_rule_id');
    }

    /** Texto corto de la condicion para listados y bitacora. */
    public function describe(): string
    {
        switch ($this->match_type) {
            case self::MATCH_REGEX:
                return 'regex ' . $this->pattern;
            case self::MATCH_COMPARE:
                return trim($this->field . ' ' . $this->operator . ' ' . $this->value);
            default:
                return 'contiene "' . $this->pattern . '"';
        }
    }
}
