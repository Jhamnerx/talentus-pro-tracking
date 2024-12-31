<?php namespace Tobuli\Validation;

use Illuminate\Validation\Factory as IlluminateValidator;
use ModalHelpers\AlertModalHelper;

class AlertFormValidator extends Validator {

    /**
     * @var array Validation rules for the test form, they can contain in-built Laravel rules or our custom rules
     */
    public $rules = [
        'create' => [
            'name'              => 'required',
            'type'              => 'required',
            'devices'           => 'required_without:selected_devices|array',
            'selected_devices'  => 'required_without:devices|array',

            'drivers'           => 'required_if:type,driver|array',
            'events_custom'     => 'required_if:type,custom|array',
            'geofences'         => 'required_if:type,geofence_in,geofence_out,geofence_inout|array',
            'pois'              => 'required_if:type,poi_stop_duration,poi_idle_duration|array',

            'zone'              => 'in:0,1,2',
            'zones'             => 'required_if:zone,1,2|array',

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
            'distance_tolerance'=> 'required_if:type,poi_stop_duration,poi_idle_duration|numeric',

            'command.active'    => 'in:0,1',
            'command.type'      => 'required_if:command.active,1',
        ],
        'update' => [
            'name'              => 'required',
            'type'              => 'required',
            'devices'           => 'array',

            'drivers'           => 'required_if:type,driver|array',
            'events_custom'     => 'required_if:type,custom|array',
            'geofences'         => 'required_if:type,geofence_in,geofence_out,geofence_inout|array',
            'pois'              => 'required_if:type,poi_stop_duration,poi_idle_duration|array',

            'zone'              => 'in:0,1,2',
            'zones'             => 'required_if:zone,1,2|array',

            'overspeed'         => 'required_if:type,overspeed|numeric',
            'stop_duration'     => 'required_if:type,stop_duration,poi_stop_duration|numeric',
            'idle_duration'     => 'required_if:type,idle_duration,poi_idle_duration|numeric',
            'ignition_duration' => 'required_if:type,ignition_duration|numeric',
            'offline_duration'  => 'required_if:type,offline_duration|numeric',
            'move_duration'     => 'required_if:type,move_duration|numeric',
            'min_parking_duration' => 'required_if:type,move_duration|numeric',
            'distance'          => 'required_if:type,distance|numeric',
            'distance_tolerance'=> 'required_if:type,poi_stop_duration,poi_idle_duration|numeric',

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

        parent::__construct($validator);
    }

}   //end of class


//EOF