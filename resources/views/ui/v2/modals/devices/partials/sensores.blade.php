{{--
    Tab Sensores — paridad con el legacy (partials/sensors.blade.php +
    sensor_group.blade.php):
    · CREAR: solo el select de grupo de sensores a asignar.
    · EDITAR: buscador + botón añadir + lista con editar/eliminar +
      select de grupo de sensores al final. El sensor_group_id viaja
      con el submit del device (igual que el legacy).
    CRUD del sensor en modal ANIDADO vía SensorModalHelper.
--}}
@php
    $isEdit = !empty($item->id);
    $sensorsList = $sensors_list ?? [];
    $sensorGroups = $sensor_groups ?? [];
@endphp

@if ($isEdit)
    @php
        $sensorsCfg = [
            'list'    => route('ui.v2.api.devices.sensors', $item->id),
            'formNew' => route('ui.v2.api.devices.sensors.form', $item->id),
            'formEdit' => url('/ui/v2/api/sensors') . '/{id}/form',
            'store'   => route('ui.v2.api.devices.sensors.store', $item->id),
            'update'  => url('/ui/v2/api/sensors') . '/{id}',
            'destroy' => url('/ui/v2/api/sensors') . '/{id}',
            'preview' => route('ui.v2.api.sensors.preview'),
        ];
    @endphp

    <div data-tp-sensors data-device-id="{{ (int) $item->id }}">
        <script type="application/json" data-sensors-cfg>@json($sensorsCfg)</script>

        <div class="tp-group-box__head">
            <span class="tp-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input type="search" class="tp-input" data-sensors-search placeholder="{{ trans('front.search') }}"
                    autocomplete="off">
            </span>
            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-sensor-add
                style="margin-left: auto;">＋</button>
        </div>

        <div class="tp-embed-box">
            <table>
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.sensor_name') }}</th>
                        <th>{{ trans('validation.attributes.sensor_type') }}</th>
                        <th style="width: 84px;"></th>
                    </tr>
                </thead>
                <tbody data-sensors-rows>
                    @forelse ($sensorsList as $s)
                        <tr data-sensor-id="{{ $s['id'] }}">
                            <td>{{ $s['name'] }}</td>
                            <td>{{ $s['type'] }}</td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sensor-edit="{{ $s['id'] }}"
                                    title="{{ trans('global.edit') }}">✎</button>
                                <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-sensor-del="{{ $s['id'] }}"
                                    title="{{ trans('global.delete') }}">🗑</button>
                            </td>
                        </tr>
                    @empty
                        <tr data-sensors-empty>
                            <td colspan="3" class="tp-text-muted" style="padding: 14px 12px; font-size: 13px;">
                                {{ trans('front.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if (!empty($sensorGroups))
    <div class="{{ $isEdit ? 'tp-form__section' : '' }}">
        <label class="tp-form__field">
            <span class="tp-form__label">{{ trans('validation.attributes.sensor_group_id') }}</span>
            <select class="tp-input" style="width: 100%" name="sensor_group_id" data-tp-field>
                @foreach ($sensorGroups as $sgId => $sgTitle)
                    <option value="{{ $sgId }}">{{ $sgTitle }}</option>
                @endforeach
            </select>
        </label>
    </div>
@endif
