{{--
    Tab Cámaras — paridad con Frontend/Devices/partials/cameras.blade.php:
    botón añadir + tabla Nombre|Widget|FTP usuario con editar/eliminar.
    El form legacy solo pide name + show_widget (las credenciales FTP las
    genera el job DeviceCameraCreate). CRUD nativo ui.v2.api.devices.cameras*.
--}}
@php
    $isEdit = !empty($item->id);
    $camerasList = $cameras_list ?? [];
@endphp

@if (!$isEdit)
    <div class="tp-form__placeholder">
        {{ trans('ui.save_vehicle_first_cameras') }}
    </div>
@else
    @php
        $camerasCfg = [
            'list'    => route('ui.v2.api.devices.cameras', $item->id),
            'store'   => route('ui.v2.api.devices.cameras.store', $item->id),
            'update'  => url('/ui/v2/api/cameras') . '/{id}',
            'destroy' => url('/ui/v2/api/cameras') . '/{id}',
        ];
    @endphp

    <div data-tp-cameras data-device-id="{{ (int) $item->id }}">
        <script type="application/json" data-cameras-cfg>@json($camerasCfg)</script>

        <div class="tp-group-box__head">
            <button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-camera-add
                style="margin-left: auto;">＋ {{ trans('front.add_camera') }}</button>
        </div>

        <div class="tp-embed-box">
            <table>
                <thead>
                    <tr>
                        <th>{{ trans('validation.attributes.name') }}</th>
                        <th>{{ trans('front.show_widget') }}</th>
                        <th>FTP</th>
                        <th style="width: 84px;"></th>
                    </tr>
                </thead>
                <tbody data-cameras-rows>
                    @forelse ($camerasList as $c)
                        <tr data-camera-id="{{ $c['id'] }}">
                            <td>{{ $c['name'] }}</td>
                            <td>{{ $c['show_widget'] ? trans('global.yes') : trans('global.no') }}</td>
                            <td>{{ $c['ftp_username'] }}</td>
                            <td style="text-align: right;">
                                @include('ui.v2.modals.devices.partials.kebab', [
                                    'edit' => 'data-camera-edit',
                                    'del' => 'data-camera-del',
                                    'id' => $c['id'],
                                    'extra' => 'data-name="' . e($c['name']) . '" data-widget="' . ($c['show_widget'] ? 1 : 0) . '"',
                                ])
                            </td>
                        </tr>
                    @empty
                        <tr data-cameras-empty>
                            <td colspan="4" class="tp-text-muted" style="padding: 14px 12px; font-size: 13px;">
                                {{ trans('front.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
