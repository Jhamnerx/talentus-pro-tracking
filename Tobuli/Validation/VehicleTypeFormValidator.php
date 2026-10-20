<?php namespace Tobuli\Validation;

/**
 * Tipos de vehículo y sus estados. Los estados llegan de dos formas:
 *  - legacy: arrays paralelos add_state[] / add_state_label[] / add_state_color[]
 *    (y edit_state[id] / edit_state_label[id] / edit_state_color[id]);
 *  - ui/v2: un objeto por estado add_state[] = {name, label, color}
 *    (y edit_state[id] = {name, label, color}).
 * Por eso cada entrada de add_state/edit_state puede ser texto O array; las
 * reglas de los subcampos cubren la forma de objeto y las *_label/*_color
 * la forma de arrays paralelos. Normaliza y persiste VehicleTypesController.
 */
class VehicleTypeFormValidator extends Validator
{
    public $rules = [
        'create' => [
            'name'                => 'required|string|max:255',
            'add_state'           => 'nullable|array',
            'add_state.*.name'    => 'nullable|string|max:255',
            'add_state.*.label'   => 'nullable|string|max:24',
            'add_state.*.color'   => 'nullable|string|max:9',
            'add_state_label.*'   => 'nullable|string|max:24',
            'add_state_color.*'   => 'nullable|string|max:9',
        ],
        'update' => [
            'name'                => 'required|string|max:255',
            'edit_state'          => 'nullable|array',
            'edit_state.*.name'   => 'nullable|string|max:255',
            'edit_state.*.label'  => 'nullable|string|max:24',
            'edit_state.*.color'  => 'nullable|string|max:9',
            'edit_state_label.*'  => 'nullable|string|max:24',
            'edit_state_color.*'  => 'nullable|string|max:9',
            'add_state'           => 'nullable|array',
            'add_state.*.name'    => 'nullable|string|max:255',
            'add_state.*.label'   => 'nullable|string|max:24',
            'add_state.*.color'   => 'nullable|string|max:9',
            'add_state_label.*'   => 'nullable|string|max:24',
            'add_state_color.*'   => 'nullable|string|max:9',
        ],
    ];
}
