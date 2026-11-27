<?php namespace Tobuli\Validation;

class RouteScheduleFormValidator extends Validator
{
    public $rules = [
        'create' => [
            'route_id'          => 'required|integer',
            'name'              => 'required|string|max:255',
            'weekdays'          => 'required|array|min:1',
            'weekdays.*'        => 'integer|between:1,7',
            'start_time'        => 'required|date_format:H:i',
            'return_start_time' => 'nullable|date_format:H:i|after:start_time',
            'tolerance_early'   => 'required|integer|between:0,1440',
            'tolerance_late'    => 'required|integer|between:0,1440',
            'devices'           => 'array',
            'devices.*'         => 'integer',
        ],
        'update' => [
            'route_id'          => 'required|integer',
            'name'              => 'required|string|max:255',
            'weekdays'          => 'required|array|min:1',
            'weekdays.*'        => 'integer|between:1,7',
            'start_time'        => 'required|date_format:H:i',
            'return_start_time' => 'nullable|date_format:H:i|after:start_time',
            'tolerance_early'   => 'required|integer|between:0,1440',
            'tolerance_late'    => 'required|integer|between:0,1440',
            'devices'           => 'array',
            'devices.*'         => 'integer',
        ],
    ];
}
