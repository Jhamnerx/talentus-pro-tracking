<?php

namespace Tobuli\Validation;

use Illuminate\Validation\Factory as IlluminateValidator;
use Tobuli\Services\RequiredFields\DeviceRequiredFieldsService;

class DeviceFormValidator extends Validator
{

    /**
     * @var array Validation rules for the test form, they can contain in-built Laravel rules or our custom rules
     */
    public $rules = [
        'create' => [
            'imei'                => 'required|imei|unique:devices,imei,%s',
            'fuel_type'           => 'in:petrol,diesel,biodiesel,biogas,hydrogen,electric,ethanol,petrol_electric,diesel_electric,propane,methanol',
            'fuel_emissions'      => 'numeric|min:0',
            'name'                => 'required|string|between:1,255',
            'icon_id'             => 'exists:device_icons,id',
            'model_id'            => 'exists:device_models,id',
            'fuel_quantity'       => 'numeric',
            'fuel_price'          => 'numeric',
            'fuel_measurement_id' => 'in:1,2,3,4,5,6',
            'tail_color'          => 'css_color',
            'tail_length'         => 'numeric|min:0|max:10',
            // Columnas nuevas (colores de recorrido/marcador, costo por km):
            // las dos interfaces comparten este validador. Sin reglas,
            // "abc" o -5 en cost_per_km y un route_color_type inventado
            // entraban tal cual (QA legacy /objects, fallo 8).
            'cost_per_km'                => 'nullable|numeric|min:0',
            'route_color_type'           => 'nullable|in:trips,single,speed,sensor',
            'route_color'                => 'nullable|css_color',
            'route_sensor_id'            => 'nullable|integer',
            'route_speed_ranges'         => 'nullable|json',
            'marker_colors_enabled'      => 'nullable|boolean',
            'marker_speed_ranges'        => 'nullable|json',
            'marker_geofence_margin'     => 'nullable|numeric|min:0|max:1000',
            'marker_geofence_near_color' => 'nullable|css_color',
            'marker_geofence_over_color' => 'nullable|css_color',
            'max_speed'           => 'numeric|min:1',
            'video_channels'      => 'nullable|integer|between:0,6',
            'min_moving_speed'    => 'numeric|min:1|max:50',
            'min_fuel_fillings'   => 'numeric|min:1|max:1000',
            'min_fuel_thefts'     => 'numeric|min:1|max:1000',
            'sim_number'          => 'required|unique:devices,sim_number',
            //'expiration_date'     => 'required_if:enable_expiration_date,1|date',
            'installation_date'   => 'date',
            'sim_activation_date' => 'date',
            'sim_expiration_date' => 'date',
            'forward.protocol'    => 'required_if:forward.active,1|in:TCP,UDP',
            'msisdn'              => 'sometimes|regex:/^\d{6,20}$/',
            'lbs'                 => 'nullable|boolean',
            'fuel_detect_sec_after_stop' => 'nullable|numeric|min:60|max:300',

            'mtc' => 'boolean',
            'osinergmin' => 'boolean',
            'mininter' => 'boolean',
            'consatel' => 'boolean',
            'plate_number' => 'required_if:mtc,1|required_if:osinergmin,1|required_if:mininter,1|required_if:consatel,1|required_if:wisetrack,1|required_if:sidersales,1|regex:/^\S+$/',
            'device_type_id' => 'nullable|exists:device_types,id',
            // vehicle_type_id / vehicle_state_id no llegan aqui: DeviceModalHelper los
            // saca antes y los valida DeviceClassificationService (clasificacion por usuario).
            'object_owner' => 'required_if:wisetrack,1',
        ],
        'update' => [
            'imei'                => 'sometimes|required|imei|unique:devices,imei,%s',
            'fuel_type'           => 'in:petrol,diesel,biodiesel,biogas,hydrogen,electric,ethanol,petrol_electric,diesel_electric,propane,methanol',
            'fuel_emissions'      => 'numeric|min:0',
            'name'                => 'sometimes|required|string|between:1,255',
            'icon_id'             => 'exists:device_icons,id',
            'model_id'            => 'exists:device_models,id',
            'fuel_quantity'       => 'numeric',
            'fuel_price'          => 'numeric',
            'fuel_measurement_id' => 'in:1,2,3,4,5,6',
            'tail_color'          => 'css_color',
            'tail_length'         => 'numeric|min:0|max:10',
            // Columnas nuevas (colores de recorrido/marcador, costo por km):
            // las dos interfaces comparten este validador. Sin reglas,
            // "abc" o -5 en cost_per_km y un route_color_type inventado
            // entraban tal cual (QA legacy /objects, fallo 8).
            'cost_per_km'                => 'nullable|numeric|min:0',
            'route_color_type'           => 'nullable|in:trips,single,speed,sensor',
            'route_color'                => 'nullable|css_color',
            'route_sensor_id'            => 'nullable|integer',
            'route_speed_ranges'         => 'nullable|json',
            'marker_colors_enabled'      => 'nullable|boolean',
            'marker_speed_ranges'        => 'nullable|json',
            'marker_geofence_margin'     => 'nullable|numeric|min:0|max:1000',
            'marker_geofence_near_color' => 'nullable|css_color',
            'marker_geofence_over_color' => 'nullable|css_color',
            'max_speed'           => 'numeric|min:1',
            'video_channels'      => 'nullable|integer|between:0,6',
            'min_moving_speed'    => 'numeric|min:1|max:50',
            'min_fuel_fillings'   => 'numeric|min:1|max:1000',
            'min_fuel_thefts'     => 'numeric|min:1|max:1000',
            // sometimes: el campo no viaja cuando el usuario no tiene permiso
            // device.sim_number (onlyEditables lo quita antes de validar) y con
            // 'required' a secas el guardado fallaba con "Necesario".
            'sim_number'          => 'sometimes|required|unique:devices,sim_number,%s',
            //'expiration_date'     => 'required_if:enable_expiration_date,1|date',
            'installation_date'   => 'date',
            'sim_activation_date' => 'date',
            'sim_expiration_date' => 'date',
            'forward.protocol'    => 'required_if:forward.active,1|in:TCP,UDP',
            'msisdn'              => 'sometimes|regex:/^\d{6,20}$/',
            'lbs'                 => 'nullable|boolean',
            'fuel_detect_sec_after_stop' => 'nullable|numeric|min:60|max:300',

            'mtc' => 'boolean',
            'osinergmin' => 'boolean',
            'mininter' => 'boolean',
            'consatel' => 'boolean',
            'plate_number' => 'required_if:mtc,1|required_if:osinergmin,1|required_if:mininter,1|required_if:consatel,1|required_if:wisetrack,1|required_if:sidersales,1|regex:/^\S+$/',
            'device_type_id' => 'nullable|exists:device_types,id',
            // vehicle_type_id / vehicle_state_id no llegan aqui: DeviceModalHelper los
            // saca antes y los valida DeviceClassificationService (clasificacion por usuario).
            'object_owner' => 'required_if:wisetrack,1',
        ],
    ];

    public function __construct(IlluminateValidator $validator)
    {
        $this->_validator = $validator;

        $this->rules['create']['group_id'] = 'exists_or_empty:device_groups,id,user_id,' . auth()->user()->id;
        $this->rules['update']['group_id'] = 'exists_or_empty:device_groups,id,user_id,' . auth()->user()->id;

        $maxIps = config('tobuli.limits.forward_ips');
        $this->rules['create']['forward.ip'] = "required_if:forward.active,1|semicolon_array:array_max:$maxIps|semicolon_element:host_port";
        $this->rules['update']['forward.ip'] = "required_if:forward.active,1|semicolon_array:array_max:$maxIps|semicolon_element:host_port";

        $extraRules = (new DeviceRequiredFieldsService())->getRules();

        appendRulesArray($this->rules['create'], $extraRules);
        appendRulesArray($this->rules['update'], $extraRules);
    }
}   //end of class

//EOF