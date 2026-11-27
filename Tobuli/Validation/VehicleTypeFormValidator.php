<?php namespace Tobuli\Validation;

/**
 * Tipos de vehículo y sus estados. Un solo formato para legacy, ui/v2 y API:
 *   edit_state[id] = {name, label, color}   (estados existentes; los que no
 *                                            vengan se borran)
 *   add_state[]    = {name, label, color}   (estados nuevos)
 * Normaliza y persiste VehicleTypesController (legacy y v2) con las mismas
 * reglas: name ≤ 255, label ≤ 24 o null, color #rrggbb[aa] o null.
 */
class VehicleTypeFormValidator extends Validator
{
    public $rules = [
        'create' => [
            'name'                => 'required|string|max:255',
            'add_state'           => 'nullable|array',
            'add_state.*'         => 'array',
            'add_state.*.name'    => 'nullable|string|max:255',
            'add_state.*.label'   => 'nullable|string|max:24',
            'add_state.*.color'   => 'nullable|string|max:9',
        ],
        'update' => [
            'name'                => 'required|string|max:255',
            'edit_state'          => 'nullable|array',
            'edit_state.*'        => 'array',
            'edit_state.*.name'   => 'nullable|string|max:255',
            'edit_state.*.label'  => 'nullable|string|max:24',
            'edit_state.*.color'  => 'nullable|string|max:9',
            'add_state'           => 'nullable|array',
            'add_state.*'         => 'array',
            'add_state.*.name'    => 'nullable|string|max:255',
            'add_state.*.label'   => 'nullable|string|max:24',
            'add_state.*.color'   => 'nullable|string|max:9',
        ],
    ];
}
