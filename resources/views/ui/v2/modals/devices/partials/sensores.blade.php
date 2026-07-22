{{--
    Tab Sensores — lista de sensores del device + CRUD nativo.
    La tabla se renderiza server-side ($sensors_list, inyectada por
    Ui\V2\DevicesController::form()) y el JS (device-modal.js) la
    re-hidrata tras cada operación vía GET api.devices.sensors.
    El form de sensor se abre en un modal ANIDADO (tpModal apila
    <dialog>) y reutiliza SensorModalHelper end-to-end.
--}}
@php
    $isEdit = !empty($item->id);
    $sensorsList = $sensors_list ?? [];
@endphp

@if (!$isEdit)
    <div class="tp-form__placeholder">
        Guarda el vehículo primero; después podrás agregar sensores desde esta pestaña.
    </div>
@else
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

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <p class="tp-form__hint" style="margin: 0;">
                Sensores configurados en este equipo.
            </p>
            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-sensor-add>
                + {{ trans('global.add') }}
            </button>
        </div>

        <div style="border: 1px solid rgba(148,163,184,.35); border-radius: 10px; overflow: hidden;">
            <table class="tp-table" style="width: 100%; font-size: 13px;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 8px 12px;">{{ trans('validation.attributes.sensor_name') }}</th>
                        <th style="text-align: left; padding: 8px 12px;">{{ trans('validation.attributes.sensor_type') }}</th>
                        <th style="width: 84px;"></th>
                    </tr>
                </thead>
                <tbody data-sensors-rows>
                    @forelse ($sensorsList as $s)
                        <tr data-sensor-id="{{ $s['id'] }}">
                            <td style="padding: 7px 12px;">{{ $s['name'] }}</td>
                            <td style="padding: 7px 12px;">{{ $s['type'] }}</td>
                            <td style="padding: 4px 8px; text-align: right; white-space: nowrap;">
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
