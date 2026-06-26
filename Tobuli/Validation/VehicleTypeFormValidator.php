<?php namespace Tobuli\Validation;

class VehicleTypeFormValidator extends Validator
{
    public $rules = [
        'create' => [
            'name' => 'required|string|max:255',
        ],
        'update' => [
            'name' => 'required|string|max:255',
        ],
    ];
}
