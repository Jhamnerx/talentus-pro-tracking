<?php namespace Tobuli\Validation;

class VehicleTypeFormValidator extends Validator
{
    public $rules = [
        'create' => [
            'name'                => 'required|string|max:255',
            'add_state.*'         => 'nullable|string|max:255',
            'add_state_label.*'   => 'nullable|string|max:24',
            'add_state_color.*'   => 'nullable|string|max:9',
        ],
        'update' => [
            'name'                => 'required|string|max:255',
            'edit_state.*'        => 'nullable|string|max:255',
            'edit_state_label.*'  => 'nullable|string|max:24',
            'edit_state_color.*'  => 'nullable|string|max:9',
            'add_state.*'         => 'nullable|string|max:255',
            'add_state_label.*'   => 'nullable|string|max:24',
            'add_state_color.*'   => 'nullable|string|max:9',
        ],
    ];
}
