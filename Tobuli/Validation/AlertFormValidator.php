<?php

namespace Tobuli\Validation;

use Illuminate\Validation\Factory as IlluminateValidator;
use Illuminate\Validation\Rule;
use ModalHelpers\AlertModalHelper;
use Tobuli\Entities\Alert;

class AlertFormValidator extends Validator
{

    /**
     * @var array Validation rules for the test form, they can contain in-built Laravel rules or our custom rules
     */
    public $rules = [
        'create' => [
            'name'              => 'required',
            'type'              => 'required',
            //inner validation
            'devices'           => 'array',
            'selected_devices'  => 'array',
            'drivers'           => 'array',
            'events_custom'     => 'array',
            // Una alerta de alarma sin alarmas elegidas no vigilaria nada
            'alarms'            => 'required_if:type,device_alarm|array',
            // Umbral sobre un sensor: sin tipo, lado y valor no hay nada que vigilar
            'sensor_type'       => 'required_if:type,sensor_value|string',
            'sensor_operator'   => 'required_if:type,sensor_value|in:above,below',
            'sensor_value'      => 'required_if:type,sensor_value|numeric',
            'sensor_duration'   => 'nullable|integer|between:0,1440',
            'geofences'         => 'array',
            'pois'              => 'array',
            'zones'             => 'array',

            'zone'              => 'in:0,1,2',

            'schedule'          => 'in:0,1',
            'schedules'         => 'required_if:schedule,1',

            'overspeed'         => 'required_if:type,overspeed|numeric',
            'stop_duration'     => 'required_if:type,stop_duration,poi_stop_duration|numeric',
            'idle_duration'     => 'required_if:type,idle_duration,poi_idle_duration|numeric',
            'ignition_duration' => 'required_if:type,ignition_duration|numeric',
            'offline_duration'  => 'required_if:type,offline_duration|numeric',
            'move_duration'     => 'required_if:type,move_duration|numeric|min:1',
            'min_parking_duration' => 'required_if:type,move_duration|numeric|min:1',
            'time_duration'     => 'required_if:type,time_duration|numeric',
            'distance'          => 'required_if:type,distance|numeric',
            'distance_tolerance' => 'required_if:type,poi_stop_duration,poi_idle_duration|numeric',
            'speed_tolerance'   => 'required_if:type,geofence_overspeed|numeric',
            'min_overspeed_duration' => 'nullable|numeric|min:0',

            'command.active'    => 'in:0,1',
            'command.type'      => 'required_if:command.active,1',
        ],
        'update' => [
            'name'              => 'required',
            'type'              => 'required',
            'case'              => 'required_if:type,device_expiration|in:expired,expiring,expired_sim,expiring_sim',
            'days'              => 'required_if:type,device_expiration|integer|min:0',
            //inner validation
            'devices'           => 'array',
            'selected_devices'  => 'array',
            'drivers'           => 'array',
            'events_custom'     => 'array',
            'geofences'         => 'array',
            'pois'              => 'array',
            'zones'             => 'array',

            'zone'              => 'in:0,1,2',

            'overspeed'         => 'required_if:type,overspeed|numeric',
            'stop_duration'     => 'required_if:type,stop_duration,poi_stop_duration|numeric',
            'idle_duration'     => 'required_if:type,idle_duration,poi_idle_duration|numeric',
            'ignition_duration' => 'required_if:type,ignition_duration|numeric',
            'offline_duration'  => 'required_if:type,offline_duration|numeric',
            'move_duration'     => 'required_if:type,move_duration|numeric',
            'min_parking_duration' => 'required_if:type,move_duration|numeric',
            'distance'          => 'required_if:type,distance|numeric',
            'distance_tolerance' => 'required_if:type,poi_stop_duration,poi_idle_duration|numeric',
            'speed_tolerance'   => 'required_if:type,geofence_overspeed|numeric',
            'min_overspeed_duration' => 'nullable|numeric|min:0',

            'command.active'    => 'in:0,1',
            'command.type'      => 'required_if:command.active,1',
        ],
        'commands' => [
            'devices'           => 'required_without_all:alert_id,selected_devices|array',
            'selected_devices'  => 'required_without_all:alert_id,devices|array',
        ],
        'devices' => [
            'devices'       => 'required|array'
        ],
    ];

    public function __construct(IlluminateValidator $validator)
    {
        $types = collect(AlertModalHelper::getTypes());

        $this->rules['create']['type'] = 'required|in:' . $types->implode('type', ',');
        $this->rules['update']['type'] = 'required|in:' . $types->implode('type', ',');

        $deviceExpirationCase = 'required_if:type,device_expiration|' . Rule::in(Alert::getAvailableCases());

        $this->rules['create']['case'] = $deviceExpirationCase;
        $this->rules['update']['case'] = $deviceExpirationCase;

        parent::__construct($validator);
    }
}   //end of class


//EOF