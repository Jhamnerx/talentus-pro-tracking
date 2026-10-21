<?php

namespace Tobuli\Services\CommandRules;

use Tobuli\Entities\CommandRuleCondition;

/**
 * Decide si una respuesta cumple una condicion.
 */
class ConditionEvaluator
{
    public static function matches(CommandRuleCondition $condition, string $response): bool
    {
        switch ($condition->match_type) {
            case CommandRuleCondition::MATCH_CONTAINS:
                return $condition->pattern !== null && $condition->pattern !== ''
                    && mb_stripos($response, $condition->pattern) !== false;

            case CommandRuleCondition::MATCH_REGEX:
                return static::regexMatches((string) $condition->pattern, $response);

            case CommandRuleCondition::MATCH_COMPARE:
                $actual = ResponseParser::value($response, (string) $condition->field);
                if ($actual === null || !is_numeric($condition->value)) {
                    return false;
                }
                return static::compare($actual, (string) $condition->operator, (float) $condition->value);
        }

        return false;
    }

    /** Valida una expresion regular tal como la escribe el usuario (con o sin delimitadores). */
    public static function isValidRegex(string $pattern): bool
    {
        $regex = static::normalizeRegex($pattern);

        return $regex !== null && @preg_match($regex, '') !== false;
    }

    private static function regexMatches(string $pattern, string $response): bool
    {
        $regex = static::normalizeRegex($pattern);
        if ($regex === null) {
            return false;
        }

        return @preg_match($regex, $response) === 1;
    }

    /**
     * Permite escribir "DO1:1" o "/DO1:1/i". Sin delimitadores se envuelve en ~ ~
     * para no chocar con las barras del texto.
     */
    private static function normalizeRegex(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        if (preg_match('/^(.)(.*)\1[imsxuADSUXJ]*$/s', $pattern, $m) && !ctype_alnum($m[1]) && $m[1] !== '\\') {
            return $pattern;
        }

        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    private static function compare(float $actual, string $operator, float $expected): bool
    {
        switch ($operator) {
            case '=':  return abs($actual - $expected) < 0.000001;
            case '!=': return abs($actual - $expected) >= 0.000001;
            case '>':  return $actual > $expected;
            case '>=': return $actual >= $expected;
            case '<':  return $actual < $expected;
            case '<=': return $actual <= $expected;
        }

        return false;
    }
}
