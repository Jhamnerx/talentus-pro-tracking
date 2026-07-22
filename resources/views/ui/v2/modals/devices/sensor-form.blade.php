{{--
    TalentusPro · Sensor form (modal anidado del tab Sensores)
    -----------------------------------------------------------------
    Render server-side con las MISMAS variables que SensorModalHelper
    createData()/editData(): $sensors (type => título), $types
    ([{type, shown_by, inputs}]), $parameters (tags del device),
    $device_id y $item (solo edición, ya pasado por itemForm()).
    Los name= replican el form legacy (Frontend/Sensors/form.blade.php)
    para que validate()/formatInput() funcionen sin cambios.

    La lógica condicional por tipo (mostrar/ocultar bloques
    [data-sensor-input]) vive en device-modal.js — el HTML inyectado
    con innerHTML no ejecuta <script>, por eso $types viaja en un
    <script type="application/json"> que el JS lee con textContent.

    Nota: el selector de iconos por mapping (legacy icons-modal) no se
    portó; las filas existentes conservan su icono vía hidden input.
--}}
@php
    $item = $item ?? null;
    $isEdit = !empty($item->id);
    $condTypes = [
        '1' => trans('front.event_type_1'),
        '2' => trans('front.event_type_2'),
        '3' => trans('front.event_type_3'),
    ];
    $formulaPlaceholder = \Tobuli\Sensors\Extractions\Formula::PLACEHOLDER;
@endphp

<form class="tp-form tp-sensor-form" autocomplete="off" novalidate>
    <script type="application/json" data-tp-sensor-types>@json($types)</script>

    <input type="hidden" name="device_id" value="{{ (int) $device_id }}">
    @if ($isEdit)
        {{-- 'id' (no sensor_id): preview() lo usa para excluir este sensor
             de la validación de unicidad por tipo --}}
        <input type="hidden" name="id" value="{{ (int) $item->id }}">
    @endif

    <div class="tp-form__grid tp-form__grid--2">

        <label class="tp-form__field tp-form__field--required">
            <span class="tp-form__label">{{ trans('validation.attributes.sensor_name') }}</span>
            <input type="text" name="sensor_name" value="{{ $item->name ?? '' }}" data-tp-field>
        </label>

        <label class="tp-form__field tp-form__field--required">
            <span class="tp-form__label">{{ trans('validation.attributes.sensor_template') }}</span>
            <select name="sensor_type" class="tp-input" data-tp-field>
                @foreach ($sensors as $type => $title)
                    <option value="{{ $type }}" {{ ($item->type ?? null) == $type ? 'selected' : '' }}>{{ $title }}</option>
                @endforeach
            </select>
        </label>

        <div data-sensor-input="shown_value_by">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.shown_value_by') }}</span>
                <select name="shown_value_by" class="tp-input" data-value="{{ $item->shown_value_by ?? '' }}" data-tp-field></select>
            </label>
        </div>

        <div data-sensor-input="tag_name">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.tag_name') }}</span>
                <input type="text" name="tag_name" list="tp-sensor-params" value="{{ $item->tag_name ?? '' }}" data-tp-field>
                <datalist id="tp-sensor-params">
                    @foreach ($parameters ?? [] as $param)
                        <option value="{{ $param }}"></option>
                    @endforeach
                </datalist>
            </label>
        </div>

        {{-- ── Flags simples ─────────────────────────────────── --}}
        <div class="tp-col-2" style="display: flex; flex-wrap: wrap; gap: 6px 22px;">
            <div data-sensor-input="skip_empty">
                <label class="tp-choice">
                    <input type="hidden" name="skip_empty" value="0">
                    <input type="checkbox" class="tp-choice-input" name="skip_empty" value="1" {{ !empty($item->skip_empty) ? 'checked' : '' }}>
                    <span>{{ trans('validation.attributes.skip_empty') }}</span>
                </label>
            </div>
            <div data-sensor-input="bin">
                <label class="tp-choice">
                    <input type="hidden" name="decbin" value="0">
                    <input type="checkbox" class="tp-choice-input" name="decbin" value="1" {{ !empty($item->decbin) ? 'checked' : '' }}>
                    <span>{{ trans('front.convert_dec_bin') }}</span>
                </label>
            </div>
            <div data-sensor-input="bin">
                <label class="tp-choice">
                    <input type="hidden" name="hexbin" value="0">
                    <input type="checkbox" class="tp-choice-input" name="hexbin" value="1" {{ !empty($item->hexbin) ? 'checked' : '' }}>
                    <span>{{ trans('front.convert_hex_bin') }}</span>
                </label>
            </div>
            <div data-sensor-input="ascii">
                <label class="tp-choice">
                    <input type="hidden" name="ascii" value="0">
                    <input type="checkbox" class="tp-choice-input" name="ascii" value="1" {{ !empty($item->ascii) ? 'checked' : '' }}>
                    <span>{{ trans('front.ascii') }}</span>
                </label>
            </div>
            <div data-sensor-input="calibration">
                <label class="tp-choice">
                    <input type="hidden" name="calibration" value="0">
                    <input type="checkbox" class="tp-choice-input" name="calibration" value="1" data-toggle-panel="calibration"
                        {{ !empty($item->calibrations) ? 'checked' : '' }}>
                    <span>{{ trans('front.calibration') }}</span>
                </label>
            </div>
            <div data-sensor-input="mapping">
                <label class="tp-choice">
                    <input type="hidden" name="mapping" value="0">
                    <input type="checkbox" class="tp-choice-input" name="mapping" value="1" data-toggle-panel="mapping"
                        {{ !empty($item->mappings) ? 'checked' : '' }}>
                    <span>{{ trans('front.mapping') }}</span>
                </label>
            </div>
            <div data-sensor-input="add_to_history">
                <label class="tp-choice">
                    <input type="checkbox" class="tp-choice-input" name="add_to_history" value="1" {{ !empty($item->add_to_history) ? 'checked' : '' }}>
                    <span>{{ trans('front.add_to_history') }}</span>
                </label>
            </div>
            <div data-sensor-input="add_to_graph">
                <label class="tp-choice">
                    <input type="checkbox" class="tp-choice-input" name="add_to_graph" value="1" {{ !empty($item->add_to_graph) ? 'checked' : '' }}>
                    <span>{{ trans('front.add_to_graph') }}</span>
                </label>
            </div>
        </div>

        {{-- ── Bitcut ────────────────────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="bitcut">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('front.bitcut') }}</span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="checkbox" class="tp-choice-input" name="bitcut" value="1" id="tp-sen-bitcut"
                        {{ !empty($item->bitcut) ? 'checked' : '' }}>
                    <input type="text" class="tp-input" name="bitcut_start" value="{{ $item->bitcut['start'] ?? '' }}"
                        placeholder="{{ trans('validation.attributes.bitcut_start') }}" data-enable="tp-sen-bitcut" style="flex: 1;">
                    <input type="text" class="tp-input" name="bitcut_count" value="{{ $item->bitcut['count'] ?? '' }}"
                        placeholder="{{ trans('validation.attributes.bitcut_count') }}" data-enable="tp-sen-bitcut" style="flex: 1;">
                    <select name="bitcut_base" class="tp-input" data-enable="tp-sen-bitcut" style="width: 90px;">
                        <option value="10" {{ ($item->bitcut['base'] ?? '') == '10' ? 'selected' : '' }}>DEC</option>
                        <option value="16" {{ ($item->bitcut['base'] ?? '') == '16' ? 'selected' : '' }}>HEX</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- ── Setflag (extracción) ──────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="setflag">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('front.setflag') }}</span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="checkbox" class="tp-choice-input" name="setflag" value="1" id="tp-sen-setflag"
                        {{ !empty($item->setflag_count) ? 'checked' : '' }}>
                    <input type="text" class="tp-input" name="setflag_start" value="{{ $item->setflag_start ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_1') }}" data-enable="tp-sen-setflag" style="flex: 1;">
                    <input type="text" class="tp-input" name="setflag_count" value="{{ $item->setflag_count ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_2') }}" data-enable="tp-sen-setflag" style="flex: 1;">
                </div>
            </div>
        </div>

        {{-- ── Fórmula ───────────────────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="formula">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.formula') }}</span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="checkbox" class="tp-choice-input" value="1" id="tp-sen-formula"
                        {{ !empty($item->formula) && empty($item->setflag_count) ? 'checked' : '' }}>
                    <input type="text" class="tp-input" name="formula"
                        value="{{ empty($item->formula) || !empty($item->setflag_count) ? $formulaPlaceholder : $item->formula }}"
                        data-enable="tp-sen-formula" style="flex: 1;" data-tp-field>
                </div>
                <small class="tp-form__hint">{{ trans('front.formula_example') }}</small>
            </div>
        </div>

        {{-- ── Min / Max ─────────────────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="minmax">
            <div class="tp-form__grid tp-form__grid--2">
                <label class="tp-form__field">
                    <span class="tp-form__label">{{ trans('validation.attributes.min_value') }}</span>
                    <input type="text" name="min_value" value="{{ $item->min_value ?? '' }}" data-tp-field>
                </label>
                <label class="tp-form__field">
                    <span class="tp-form__label">{{ trans('validation.attributes.max_value') }}</span>
                    <input type="text" name="max_value" value="{{ $item->max_value ?? '' }}" data-tp-field>
                </label>
            </div>
        </div>

        {{-- ── Tanque de combustible ─────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="full_tank">
            <div class="tp-form__grid tp-form__grid--2">
                <label class="tp-form__field tp-col-2">
                    <span class="tp-form__label">{{ trans('validation.attributes.fuel_tank_name') }}</span>
                    <input type="text" name="fuel_tank_name" value="{{ $item->fuel_tank_name ?? '' }}" data-tp-field>
                </label>
                <label class="tp-form__field">
                    <span class="tp-form__label">{{ trans('validation.attributes.full_tank') }}</span>
                    <input type="text" name="full_tank" value="{{ $item->full_tank ?? '' }}" data-tp-field>
                </label>
                <label class="tp-form__field">
                    <span class="tp-form__label">{{ trans('validation.attributes.tag_value') }}</span>
                    <input type="text" name="full_tank_value" value="{{ $item->full_tank_value ?? '' }}" data-tp-field>
                </label>
            </div>
        </div>

        {{-- ── Lógica ON / OFF ───────────────────────────────── --}}
        <div class="tp-col-2" data-sensor-input="logic_on">
            <label class="tp-choice" style="margin-bottom: 8px;">
                <input type="checkbox" class="tp-choice-input" name="setflag" value="1" id="tp-sen-logic-setflag"
                    {{ !empty($item->on_tag_count) ? 'checked' : '' }}>
                <span>{{ trans('front.setflag') }}</span>
            </label>

            <div class="tp-form__field" data-sensor-input="logic_on">
                <span class="tp-form__label">{{ trans('validation.attributes.on_value') }}</span>
                <div style="display: flex; gap: 8px;">
                    <select name="on_type" class="tp-input" style="width: 150px;">
                        @foreach ($condTypes as $ct => $ctLabel)
                            <option value="{{ $ct }}" {{ ($item->on_type ?? null) == $ct ? 'selected' : '' }}>{{ $ctLabel }}</option>
                        @endforeach
                    </select>
                    <input type="text" class="tp-input" name="on_tag_start" value="{{ $item->on_tag_start ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_1') }}" data-enable="tp-sen-logic-setflag"
                        data-enable-hide="1" style="flex: 1;">
                    <input type="text" class="tp-input" name="on_tag_count" value="{{ $item->on_tag_count ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_2') }}" data-enable="tp-sen-logic-setflag"
                        data-enable-hide="1" style="flex: 1;">
                    <input type="text" class="tp-input" name="on_tag_value" value="{{ $item->on_tag_value ?? '' }}"
                        placeholder="{{ trans('validation.attributes.tag_value') }}" style="flex: 1;" data-tp-field>
                </div>
            </div>

            <div class="tp-form__field" data-sensor-input="logic_off">
                <span class="tp-form__label">{{ trans('validation.attributes.off_value') }}</span>
                <div style="display: flex; gap: 8px;">
                    <select name="off_type" class="tp-input" style="width: 150px;">
                        @foreach ($condTypes as $ct => $ctLabel)
                            <option value="{{ $ct }}" {{ ($item->off_type ?? null) == $ct ? 'selected' : '' }}>{{ $ctLabel }}</option>
                        @endforeach
                    </select>
                    <input type="text" class="tp-input" name="off_tag_start" value="{{ $item->off_tag_start ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_1') }}" data-enable="tp-sen-logic-setflag"
                        data-enable-hide="1" style="flex: 1;">
                    <input type="text" class="tp-input" name="off_tag_count" value="{{ $item->off_tag_count ?? '' }}"
                        placeholder="{{ trans('validation.attributes.on_setflag_2') }}" data-enable="tp-sen-logic-setflag"
                        data-enable-hide="1" style="flex: 1;">
                    <input type="text" class="tp-input" name="off_tag_value" value="{{ $item->off_tag_value ?? '' }}"
                        placeholder="{{ trans('validation.attributes.tag_value') }}" style="flex: 1;" data-tp-field>
                </div>
            </div>
        </div>

        {{-- ── Unidad / odómetro / valor ─────────────────────── --}}
        <div data-sensor-input="unit">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.unit_of_measurement') }}</span>
                <input type="text" name="unit_of_measurement" maxlength="32" value="{{ $item->unit_of_measurement ?? '' }}" data-tp-field>
            </label>
        </div>

        <div data-sensor-input="odometer_unit">
            <label class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.unit_of_measurement') }}</span>
                <select name="odometer_value_unit" class="tp-input" data-tp-field>
                    <option value="km" {{ ($item->odometer_value_unit ?? 'km') == 'km' ? 'selected' : '' }}>{{ trans('front.km') }}</option>
                    <option value="mi" {{ ($item->odometer_value_unit ?? '') == 'mi' ? 'selected' : '' }}>{{ trans('front.mi') }}</option>
                </select>
            </label>
        </div>

        <div data-sensor-input="value">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('validation.attributes.value') }}</span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="checkbox" class="tp-choice-input" value="1" id="tp-sen-value">
                    <input type="text" class="tp-input" name="value" value="{{ $item->value ?? 0 }}"
                        data-enable="tp-sen-value" style="flex: 1;" data-tp-field>
                </div>
            </div>
        </div>

        {{-- ── Vista previa ──────────────────────────────────── --}}
        <div class="tp-col-2">
            <div class="tp-form__field">
                <span class="tp-form__label">{{ trans('front.preview') }}</span>
                <div style="display: flex; gap: 8px;">
                    <input type="text" class="tp-input" name="input" placeholder="{{ trans('validation.attributes.value') }}" style="flex: 1;">
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sensor-preview>▶</button>
                    <input type="text" class="tp-input" data-sensor-preview-result readonly style="flex: 1;">
                </div>
            </div>
        </div>

        {{-- ── Panel de calibración (visible si el tipo lo permite Y el check está marcado) ── --}}
        <div class="tp-col-2" data-panel="calibration" style="display: none;">
            <div style="border: 1px solid rgba(148,163,184,.35); border-radius: 10px; padding: 12px;">
                <label class="tp-choice" style="margin-bottom: 8px;">
                    <input type="hidden" name="skip_calibration" value="0">
                    <input type="checkbox" class="tp-choice-input" name="skip_calibration" value="1"
                        {{ !empty($item->skip_calibration) ? 'checked' : '' }}>
                    <span>{{ trans('validation.attributes.skip_calibration') }}</span>
                </label>

                <div style="max-height: 220px; overflow-y: auto; border: 1px solid rgba(148,163,184,.25); border-radius: 8px; margin-bottom: 10px;">
                    <table class="tp-table" style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 6px 10px;">{{ trans('validation.attributes.tag_value') }}</th>
                                <th style="text-align: left; padding: 6px 10px;">{{ trans('front.calibrated_value') }}</th>
                                <th style="width: 40px;"></th>
                            </tr>
                        </thead>
                        <tbody data-cal-rows>
                            @foreach ($item->calibrations ?? [] as $x => $y)
                                <tr>
                                    <td style="padding: 5px 10px;">{{ $x }}<input type="hidden" name="calibrations[{{ $x }}]" value="{{ $y }}"></td>
                                    <td style="padding: 5px 10px;">{{ $y }}</td>
                                    <td style="text-align: center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-cal-del>×</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="display: flex; gap: 8px; align-items: flex-end;">
                    <label class="tp-form__field" style="flex: 1;">
                        <span class="tp-form__label">{{ trans('validation.attributes.tag_value') }}</span>
                        <input type="text" class="tp-input" data-cal-x>
                    </label>
                    <label class="tp-form__field" style="flex: 1;">
                        <span class="tp-form__label">{{ trans('front.calibrated_value') }}</span>
                        <input type="text" class="tp-input" data-cal-y>
                    </label>
                    <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-cal-add>+ {{ trans('global.add') }}</button>
                </div>
            </div>
        </div>

        {{-- ── Panel de mapping (visible si el tipo lo permite Y el check está marcado) ── --}}
        <div class="tp-col-2" data-panel="mapping" style="display: none;">
            <div style="border: 1px solid rgba(148,163,184,.35); border-radius: 10px; padding: 12px;">
                <div style="max-height: 220px; overflow-y: auto; border: 1px solid rgba(148,163,184,.25); border-radius: 8px; margin-bottom: 10px;">
                    <table class="tp-table" style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 6px 10px;">{{ trans('validation.attributes.tag_value') }}</th>
                                <th style="text-align: left; padding: 6px 10px;">{{ trans('front.mapped_value') }}</th>
                                <th style="width: 44px;"></th>
                                <th style="width: 40px;"></th>
                            </tr>
                        </thead>
                        <tbody data-map-rows>
                            @foreach ($item->mappings ?? [] as $mi => $mapping)
                                <tr>
                                    <td style="padding: 5px 10px;">
                                        {{ ($mapping['vt'] ?? '') == '2' ? ($mapping['v'] ?? '') . ' .. ' . ($mapping['t'] ?? '') : $mapping['v'] ?? '' }}
                                        <input type="hidden" name="mappings[{{ $mi }}][vt]" value="{{ $mapping['vt'] ?? '' }}">
                                        <input type="hidden" name="mappings[{{ $mi }}][v]" value="{{ $mapping['v'] ?? '' }}">
                                        @if (($mapping['vt'] ?? '') == '2')
                                            <input type="hidden" name="mappings[{{ $mi }}][t]" value="{{ $mapping['t'] ?? '' }}">
                                        @endif
                                    </td>
                                    <td style="padding: 5px 10px;">
                                        {{ ($mapping['mt'] ?? '') == '3' ? '[value]' : $mapping['mv'] ?? '' }}
                                        <input type="hidden" name="mappings[{{ $mi }}][mt]" value="{{ $mapping['mt'] ?? '' }}">
                                        @if (($mapping['mt'] ?? '') != '3')
                                            <input type="hidden" name="mappings[{{ $mi }}][mv]" value="{{ $mapping['mv'] ?? '' }}">
                                        @endif
                                    </td>
                                    <td style="text-align: center;">
                                        @if (!empty($mapping['icon']))
                                            <input type="hidden" name="mappings[{{ $mi }}][icon]" value="{{ $mapping['icon'] }}">
                                            @if (!empty($mapping['url']))
                                                <img src="{{ $mapping['url'] }}" alt="" style="width: 18px; height: 18px;">
                                            @endif
                                        @endif
                                    </td>
                                    <td style="text-align: center;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-map-del>×</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="tp-form__grid tp-form__grid--2">
                    <label class="tp-form__field">
                        <span class="tp-form__label">{{ trans('validation.attributes.tag_value') }}</span>
                        <select class="tp-input" data-map-vt>
                            <option value="1">{{ trans('front.value') }}</option>
                            <option value="2">{{ trans('front.range') }}</option>
                        </select>
                    </label>
                    <div class="tp-form__field">
                        <span class="tp-form__label">&nbsp;</span>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" class="tp-input" data-map-v placeholder="{{ trans('global.from') }}" style="flex: 1;">
                            <input type="text" class="tp-input" data-map-t placeholder="{{ trans('global.to') }}" style="flex: 1; display: none;">
                        </div>
                    </div>
                    <label class="tp-form__field">
                        <span class="tp-form__label">{{ trans('front.mapped_value') }}</span>
                        <select class="tp-input" data-map-mt>
                            <option value="1">{{ trans('front.text') }}</option>
                            <option value="2">{{ trans('front.formula') }}</option>
                            <option value="3">{{ trans('front.value') }}</option>
                        </select>
                    </label>
                    <div class="tp-form__field">
                        <span class="tp-form__label">&nbsp;</span>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" class="tp-input" data-map-mv style="flex: 1;">
                            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-map-add>+ {{ trans('global.add') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>
