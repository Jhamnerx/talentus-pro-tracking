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
            'sensorIcons' => route('ui.v2.api.sensors.icons'),
            'calImport'   => route('ui.v2.api.sensors.calibrations.import'),
            'calExample'  => asset('examples/import_sensor_calibrations.csv'),
            'copyTargets' => route('ui.v2.api.devices.sensors.copy_targets', $item->id),
            'copy'        => route('ui.v2.api.devices.sensors.copy', $item->id),
        ];
    @endphp

    <div data-tp-sensors data-device-id="{{ (int) $item->id }}">
        <script type="application/json" data-sensors-cfg>@json($sensorsCfg)</script>

        {{-- Barra de acciones como el legacy (action-block): "+ Agregar sensor"
             a la izquierda; buscador y "Copiar a…" (extras de la v2) a la derecha. --}}
        <div class="tp-devlist__bar">
            <button type="button" class="tp-devlist__add" data-sensor-add>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                {{ trans('front.add_sensor') }}
            </button>
            {{-- Copiar la configuracion de sensores a otros vehiculos: dar de
                 alta una flota igual obligaba a repetirla uno a uno. --}}
            <button type="button" class="tp-devlist__add tp-devlist__add--ghost" data-sensors-copy>{{ trans('ui.copy_sensors_to') }}</button>
            <span class="tp-devlist__search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input aria-label="{{ trans('front.search') }}" type="search" data-sensors-search
                    placeholder="{{ trans('front.search') }}" autocomplete="off">
            </span>
        </div>

        <table class="tp-devlist__table">
            <thead>
                <tr>
                    <th>{{ trans('validation.attributes.sensor_name') }}</th>
                    <th>{{ trans('validation.attributes.sensor_template') }}</th>
                    <th class="tp-devlist__actions-col"></th>
                </tr>
            </thead>
            <tbody data-sensors-rows>
                @forelse ($sensorsList as $s)
                    <tr data-sensor-id="{{ $s['id'] }}">
                        <td>{{ $s['name'] }}</td>
                        <td>{{ $s['type'] }}</td>
                        <td class="tp-devlist__actions">
                            <button type="button" class="tp-devlist__icon" data-sensor-edit="{{ $s['id'] }}"
                                title="{{ trans('global.edit') }}" aria-label="{{ trans('global.edit') }}">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" /></svg>
                            </button>
                            <button type="button" class="tp-devlist__icon tp-devlist__icon--danger" data-sensor-del="{{ $s['id'] }}"
                                title="{{ trans('global.delete') }}" aria-label="{{ trans('global.delete') }}">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr data-sensors-empty>
                        <td colspan="3" class="tp-devlist__empty">{{ trans('front.no_sensors') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
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
