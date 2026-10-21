<?php

namespace Tobuli\Validation;

class AdminCommandRuleValidator extends Validator
{
    public $rules = [
        'save' => [
            'name'                        => 'required|string|max:100',
            'description'                 => 'nullable|string|max:255',
            'active'                      => 'required|boolean',
            'query_command'               => 'required|string|max:255',
            'interval_minutes'            => 'required|integer|min:1|max:1440',
            'only_connected'              => 'required|boolean',
            'evaluate_any_response'       => 'required|boolean',
            'cooldown_minutes'            => 'required|integer|min:0|max:10080',
            'conditions'                  => 'required|array|min:1',
            'conditions.*.match_type'     => 'required|in:contains,regex,compare',
            'conditions.*.pattern'        => 'required_if:conditions.*.match_type,contains,regex|nullable|string|max:255',
            'conditions.*.field'          => 'required_if:conditions.*.match_type,compare|nullable|string|max:50',
            'conditions.*.operator'       => 'required_if:conditions.*.match_type,compare|nullable|in:=,!=,>,>=,<,<=',
            'conditions.*.value'          => 'required_if:conditions.*.match_type,compare|nullable|numeric',
            'conditions.*.action_command' => 'required|string|max:255',
            'conditions.*.stop_on_match'  => 'nullable|boolean',
        ],
    ];
}
