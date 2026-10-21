<?php

namespace Tobuli\Services\CommandRules;

use CustomFacades\Validators\AdminCommandRuleValidator;
use Illuminate\Support\Arr;
use Tobuli\Entities\CommandRule;
use Tobuli\Entities\CommandRuleCondition;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\EntityLoader\DevicesGroupLoader;

/**
 * Alta/edicion/baja de normas desde el admin.
 */
class CommandRuleService
{
    /**
     * @param array              $data   request del formulario (conditions[i][...], devices[] via loader)
     * @param User               $user   admin que guarda; dueño de la norma al crearla
     * @param DevicesGroupLoader $loader selector de vehiculos (clave `devices`)
     */
    public function save(array $data, User $user, DevicesGroupLoader $loader, ?CommandRule $rule = null): CommandRule
    {
        $data = $this->normalize($data);

        AdminCommandRuleValidator::validate('save', $data);
        $this->validateRegexes($data['conditions']);

        if ($rule === null) {
            $rule = new CommandRule();
            $rule->user_id = $user->id;
        }

        try {
            beginTransaction();

            $rule->fill(Arr::only($data, [
                'name', 'description', 'active', 'query_command', 'interval_minutes',
                'only_connected', 'evaluate_any_response', 'cooldown_minutes',
            ]));
            $rule->save();

            $this->syncConditions($rule, $data['conditions']);

            if ($loader->hasSelect()) {
                $rule->devices()->syncLoader($loader);
            }

            commitTransaction();
        } catch (\Throwable $e) {
            rollbackTransaction();
            throw $e;
        }

        return $rule;
    }

    public function delete(CommandRule $rule): void
    {
        $rule->delete(); // cascade: devices, conditions, logs
    }

    /**
     * Del formulario llegan conditions[n][campo]; se descartan filas vacias
     * (la fila plantilla) y se normalizan booleanos/espacios.
     */
    private function normalize(array $data): array
    {
        $data['active']                = (int) !empty($data['active']);
        $data['only_connected']        = (int) !empty($data['only_connected']);
        $data['evaluate_any_response'] = (int) !empty($data['evaluate_any_response']);
        $data['query_command']         = trim((string) ($data['query_command'] ?? ''));
        $data['name']                  = trim((string) ($data['name'] ?? ''));
        $data['interval_minutes']      = $data['interval_minutes'] ?? 5;
        $data['cooldown_minutes']      = $data['cooldown_minutes'] ?? 0;

        $conditions = [];
        foreach ($this->conditionRows($data) as $row) {
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);

            $empty = ($row['pattern'] ?? '') === '' && ($row['field'] ?? '') === ''
                && ($row['value'] ?? '') === '' && ($row['action_command'] ?? '') === '';
            if ($empty) {
                continue;
            }

            $type = $row['match_type'] ?? CommandRuleCondition::MATCH_CONTAINS;

            $conditions[] = [
                'match_type'     => $type,
                'pattern'        => $type === CommandRuleCondition::MATCH_COMPARE ? null : ($row['pattern'] ?? null),
                'field'          => $type === CommandRuleCondition::MATCH_COMPARE ? ($row['field'] ?? null) : null,
                'operator'       => $type === CommandRuleCondition::MATCH_COMPARE ? ($row['operator'] ?? null) : null,
                'value'          => $type === CommandRuleCondition::MATCH_COMPARE ? ($row['value'] ?? null) : null,
                'action_command' => $row['action_command'] ?? null,
                'stop_on_match'  => (int) !empty($row['stop_on_match']),
            ];
        }

        $data['conditions'] = $conditions;

        return $data;
    }

    /**
     * El formulario admin usa el repetidor `.empty-input-items`, que clona filas
     * con los mismos nombres, asi que las condiciones llegan como arrays paralelos
     * (cond_match_type[], cond_pattern[], ...). Tambien se acepta conditions[n][...]
     * (API / semilla).
     */
    private function conditionRows(array $data): array
    {
        if (!empty($data['conditions']) && is_array($data['conditions'])) {
            return array_values(array_filter($data['conditions'], 'is_array'));
        }

        $rows  = [];
        $types = (array) ($data['cond_match_type'] ?? []);

        foreach ($types as $i => $type) {
            $rows[] = [
                'match_type'     => $type,
                'pattern'        => $data['cond_pattern'][$i] ?? null,
                'field'          => $data['cond_field'][$i] ?? null,
                'operator'       => $data['cond_operator'][$i] ?? null,
                'value'          => $data['cond_value'][$i] ?? null,
                'action_command' => $data['cond_action_command'][$i] ?? null,
                'stop_on_match'  => $data['cond_stop_on_match'][$i] ?? 1,
            ];
        }

        return $rows;
    }

    private function validateRegexes(array $conditions): void
    {
        foreach ($conditions as $i => $c) {
            if ($c['match_type'] === CommandRuleCondition::MATCH_REGEX && !ConditionEvaluator::isValidRegex((string) $c['pattern'])) {
                throw new ValidationException(["conditions.{$i}.pattern" => [trans('admin.command_rules_invalid_regex')]]);
            }
        }
    }

    /** Borra y recrea: son pocas filas y asi el orden queda siempre consistente. */
    private function syncConditions(CommandRule $rule, array $conditions): void
    {
        $rule->conditions()->delete();

        foreach (array_values($conditions) as $position => $c) {
            $rule->conditions()->create($c + ['position' => $position]);
        }
    }
}
