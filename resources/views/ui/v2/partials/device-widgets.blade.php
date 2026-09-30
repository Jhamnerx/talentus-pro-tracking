{{--
    UI v2 · Device widgets (Live)
    Renderizado por Ui\V2\DevicesController::widgets(). Devuelve DOS grupos:
      .tp-dw--side   → drawer derecho (objeto, ubicación, sensores, imagen)
      .tp-dw--bottom → barra inferior de cards (servicios, eventos, vista de
                       calle, cámara, conductor, combustible, comando GPRS,
                       video en vivo)
    live-ops.js separa cada grupo y lo coloca en su contenedor. Cada bloque
    solo aparece si el widget está activo en la config del usuario.
--}}

{{-- ════════ GRUPO LATERAL (drawer) ════════ --}}
<div class="tp-dw tp-dw--side">

    {{-- Objeto (ficha unificada: fusiona la antigua Telemetría). Velocidad/Rumbo/
         Detenido/Distancia están arriba en las KPI del drawer (no se repiten aquí). --}}
    @isset($device_info)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">{{ trans('front.object') }}</h4>
            <dl class="tp-defs">
                <dt>{{ trans('front.state') }}</dt><dd>{{ $device_info['status_text'] ?? '—' }}</dd>
                <dt>{{ trans('front.address') }}</dt><dd>{{ $device_info['address'] ?? '—' }}</dd>
                <dt>{{ trans('ui.last_signal') }}</dt><dd>{{ $device_info['time'] ?? '—' }}</dd>
                <dt>{{ trans('front.coordinates') }}</dt><dd class="tp-text-mono">{{ $device_info['coords'] ?? '—' }}</dd>
                <dt>{{ trans('front.altitude') }}</dt><dd>{{ $device_info['altitude'] ?? '—' }}</dd>
                <dt>{{ trans('front.engine') }}</dt><dd>{{ $device_info['engine'] ?? '—' }}</dd>
                <dt>{{ trans('front.driver') }}</dt><dd>{{ $device_info['driver'] ?? '—' }}</dd>
                @if (!empty($device_info['is_admin']))
                    <dt>IMEI</dt><dd class="tp-text-mono">{{ $device_info['imei'] ?? '—' }}</dd>
                    <dt>{{ trans('front.model') }}</dt><dd>{{ $device_info['model'] ?? '—' }}</dd>
                    <dt>SIM</dt><dd class="tp-text-mono">{{ $device_info['sim'] ?? '—' }}</dd>
                @endif
            </dl>
        </section>
    @endisset

    {{-- Conductor (va en el drawer lateral, no en el bottombar) --}}
    @isset($driver)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">{{ trans('front.driver') }}</h4>
            @if ($driver)
                <dl class="tp-defs">
                    <dt>{{ trans('ui.name') }}</dt><dd>{{ $driver->name ?? '—' }}</dd>
                    <dt>RFID</dt><dd>{{ $driver->rfid ?? '—' }}</dd>
                    <dt>{{ trans('validation.attributes.phone') }}</dt><dd>{{ $driver->phone ?? '—' }}</dd>
                    <dt>Email</dt><dd>{{ $driver->email ?? '—' }}</dd>
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_driver_assigned') }}</p>
            @endif
        </section>
    @endisset

    {{-- Ubicación --}}
    @isset($location)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">{{ trans('front.location') }}</h4>
            @if ($location)
                <dl class="tp-defs">
                    <dt>{{ trans('front.city') }}</dt><dd>{{ $location['city'] ?? '—' }}</dd>
                    <dt>{{ trans('ui.street') }}</dt><dd>{{ $location['road'] ?? '—' }}</dd>
                    <dt>{{ trans('front.country') }}</dt><dd>{{ $location['country'] ?? '—' }}</dd>
                    <dt>{{ trans('front.address') }}</dt><dd>{{ $location['address'] ?? '—' }}</dd>
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_address_available') }}</p>
            @endif
        </section>
    @endisset

    {{-- Sensores --}}
    @isset($sensors)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">{{ trans('front.sensors') }}</h4>
            @if (count($sensors))
                <dl class="tp-defs tp-defs--sensors">
                    @foreach ($sensors as $sensor)
                        {{-- Icono: personalizado del mapeo de valores (admin/sensor_icons)
                             o el SVG del tipo, igual que el legacy --}}
                        <dt>
                            {{-- Icono personalizado del mapeo de valores (admin →
                                 sensor_icons) si lo hay; si no, el icono de trazo
                                 del tipo, que hereda el color del texto. Los SVG
                                 del tema legacy traen colores fijos y desentonaban. --}}
                            @if (!empty($sensor['custom_icon']))
                                <img class="tp-sensor-icon" src="{{ $sensor['custom_icon'] }}"
                                    alt="" loading="lazy" aria-hidden="true">
                            @else
                                @include('ui.v2.partials.sensor-icon', ['type' => $sensor['type'] ?? ''])
                            @endif
                            <span>{{ $sensor['name'] }}</span>
                        </dt>
                        <dd>{{ $sensor['formated'] }}</dd>
                    @endforeach
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_sensors_configured') }}</p>
            @endif
        </section>
    @endisset

    {{-- Imagen del vehículo. El legacy permite subirla y borrarla desde el
         propio widget si el usuario puede editar el objeto; la v2 solo la
         mostraba. Los hooks (data-dw-image-*) los recoge live.js. --}}
    @isset($image)
        @php
            $imgUrl = $image['url'] ?? null;
            $canEditImage = !empty($image['can_edit']);
        @endphp
        <section class="tp-card tp-card--flat tp-mb-4" data-dw-image
            data-store="{{ $image['store'] ?? '' }}" data-destroy="{{ $image['destroy'] ?? '' }}">
            <div class="tp-dw__head">
                <h4 class="tp-h6 tp-mb-0">{{ trans('front.device_image') }}</h4>
                @if ($canEditImage)
                    <div class="tp-dw__actions">
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--xs" data-dw-image-upload
                            title="{{ trans('front.upload') }}">{{ trans('front.upload') }}</button>
                        @if ($imgUrl)
                            <button type="button" class="tp-btn tp-btn--ghost tp-btn--xs" data-dw-image-delete
                                title="{{ trans('global.delete') }}">{{ trans('global.delete') }}</button>
                        @endif
                        <input type="file" accept="image/*" hidden data-dw-image-file>
                    </div>
                @endif
            </div>
            <div data-dw-image-body>
                @if ($imgUrl)
                    <a href="{{ $imgUrl }}" target="_blank" rel="noopener" class="tp-dw__image">
                        <img src="{{ $imgUrl }}" alt="{{ trans('front.device_image') }}" loading="lazy">
                    </a>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_image') }}</p>
                @endif
            </div>
        </section>
    @endisset

    {{-- Plantillas webhook (addon.widget_template). El gate lo aplica el
         controlador: sin el addon la clave ni llega. --}}
    @isset($template_webhook)
        <section class="tp-card tp-card--flat tp-mb-4" data-dw-webhook
            data-url="{{ $template_webhook['send_url'] ?? '' }}">
            <h4 class="tp-h6 tp-mb-3">{{ trans('front.template_webhook') }}</h4>
            @if (!empty($template_webhook['templates']))
                <div class="tp-dw__webhook">
                    <select class="tp-input tp-input--sm" data-dw-webhook-select>
                        <option value="0">{{ trans('front.nothing_selected') }}</option>
                        @foreach ($template_webhook['templates'] as $tid => $tname)
                            <option value="{{ $tid }}">{{ $tname }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="tp-btn tp-btn--primary tp-btn--sm" data-dw-webhook-send
                        hidden>{{ trans('global.apply') }}</button>
                </div>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_templates_plain') }}</p>
            @endif
        </section>
    @endisset

</div>

{{-- ════════ GRUPO INFERIOR (bottombar) ════════ --}}
<div class="tp-dw tp-dw--bottom">

    {{-- Servicios --}}
    @isset($services)
        <section class="tp-card tp-card--flat">
            {{-- Header: título + icono "3 rayitas" que abre la LISTA de servicios.
                 Los modales (lista + formulario + eliminar) son los COMPARTIDOS de
                 tp-services.js (mismos que /ui/v2/maintenance); backend
                 Ui\V2\ServicesController → ServiceModalHelper legacy. --}}
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2.7-.7-.7-2.7z" />
                    </svg>
                    <span>{{ trans('front.services') }}</span>
                </span>
                {{-- Icono "3 rayitas" → LISTA de servicios (el widget ya está gateado
                     por la config de widgets + acceso al device, igual que el legacy). --}}
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-tp-service="list"
                    data-device-id="{{ $device->id }}"
                    title="{{ trans('front.services') }}" aria-label="{{ trans('front.services') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M3 12h18M3 18h18" />
                    </svg>
                </button>
            </h4>
            <div class="tp-dw__card-body">
                @if (count($services))
                    <dl class="tp-defs">
                        @foreach ($services as $service)
                            <dt>{{ $service->name ?? ('#' . $service->id) }}</dt>
                            <dd class="tp-text-muted">
                                {{ $service instanceof \Tobuli\Entities\DeviceService ? $service->expiration() : $service->expiration_date ?? ($service->expires ?? '—') }}
                            </dd>
                        @endforeach
                    </dl>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-2">{{ trans('front.no_services') }}</p>
                @endif
                <button type="button" class="tp-btn tp-btn--sm tp-full tp-mt-2 tp-dw__add" data-tp-service="add"
                    data-device-id="{{ $device->id }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    {{ trans('front.add_service') }}
                </button>
            </div>
        </section>
    @endisset

    {{-- Eventos recientes --}}
    @isset($recent_events)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                    </svg>
                    <span>{{ trans('front.recent_events') }}</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                @if (count($recent_events))
                    <ul class="tp-dw__events">
                        @foreach ($recent_events as $event)
                            <li>
                                <span class="tp-text-xs tp-text-muted">{{ $event->timePassed ?? '' }}</span>
                                <span>{{ $event->title ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_recent_events') }}</p>
                @endif
            </div>
        </section>
    @endisset

    {{-- Vista de calle --}}
    @isset($streetview)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11z" />
                        <circle cx="12" cy="10" r="2.5" />
                    </svg>
                    <span>{{ trans('ui.street_view') }}</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                @if (!empty($streetview_frame))
                    {{-- Embed interactivo (encuentra la panorámica más cercana, igual que el legacy). --}}
                    <iframe src="{{ $streetview_frame }}" title="Street View" loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade" allowfullscreen
                        style="width:100%;height:130px;border:0;border-radius:8px;display:block;"></iframe>
                @elseif ($streetview)
                    <div class="tp-dw__streetview">
                        <img src="{{ $streetview }}" alt="Street View" loading="lazy">
                    </div>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.street_view_unavailable') }}</p>
                @endif
            </div>
        </section>
    @endisset

    {{-- Cámara --}}
    @isset($camera_images)
        @if (!empty($camera_images) || !empty($camera_device_image))
            <section class="tp-card tp-card--flat">
                <h4 class="tp-h6 tp-dw__card-head">
                    <span class="tp-dw__card-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z" />
                            <circle cx="12" cy="13" r="3" />
                        </svg>
                        <span>{{ trans('ui.camera') }}</span>
                    </span>
                </h4>
                <div class="tp-dw__card-body">
                <div class="tp-dw__cameras">
                    @if (!empty($camera_device_image))
                        <a class="tp-dw__cam" target="_blank" rel="noopener"
                            href="{{ route('device_media.display_image', [$device->id, $camera_device_image->name]) }}">
                            <img src="{{ route('device_media.display_image', [$device->id, $camera_device_image->name]) }}" loading="lazy">
                        </a>
                    @endif
                    @foreach ($camera_images as $camera_id => $cam)
                        <a class="tp-dw__cam" target="_blank" rel="noopener"
                            href="{{ route('device_media.display_camera_image', [$camera_id, $cam['image']->name]) }}">
                            <span class="tp-text-xs tp-text-muted">{{ $cam['camera_name'] }}</span>
                            <img src="{{ route('device_media.display_camera_image', [$camera_id, $cam['image']->name]) }}" loading="lazy">
                        </a>
                    @endforeach
                </div>
                </div>
            </section>
        @endif
    @endisset

    {{-- Combustible (valor actual de sensores fuel_tank) --}}
    @isset($fuel)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 22h12V4a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v18Z" />
                        <path d="M15 8h2a2 2 0 0 1 2 2v6a2 2 0 0 0 2 2 2 2 0 0 0 2-2V8l-3-3" />
                        <path d="M7 7h4" />
                    </svg>
                    <span>{{ trans('front.fuel') }}</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                @if (count($fuel))
                    @foreach ($fuel as $f)
                        <div class="tp-fuel-w">
                            <div class="tp-fuel-w__head">
                                <span class="tp-fuel-w__name">{{ $f['name'] }}</span>
                                @if (!is_null($f['last']))
                                    <span class="tp-fuel-w__val">{{ round($f['last'], 1) }}{{ $f['unit'] }}</span>
                                @endif
                            </div>
                            @if (!empty($f['values']))
                                <canvas class="tp-fuel-chart" data-fuel-chart height="80"></canvas>
                                <script type="application/json" data-fuel-data>{!! json_encode([
                                    'values' => $f['values'],
                                    'unit'   => $f['unit'],
                                    'max'    => $f['max'] ?? null,
                                ]) !!}</script>
                            @else
                                <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_data_last_24h') }}</p>
                            @endif
                        </div>
                    @endforeach
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">{{ trans('ui.no_fuel_sensors') }}</p>
                @endif
            </div>
        </section>
    @endisset

    {{-- Comando GPRS (abre el modal de envío para este vehículo) --}}
    @isset($gprs_command)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m22 2-7 20-4-9-9-4Z" />
                        <path d="M22 2 11 13" />
                    </svg>
                    <span>{{ trans('front.gprs_command') }}</span>
                </span>
            </h4>
            <div class="tp-dw__card-body tp-dw__card-body--center">
                <button type="button" class="tp-btn tp-btn--sm tp-full tp-dw__add" data-tp-commands
                    data-device-id="{{ $device->id }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m22 2-7 20-4-9-9-4Z" />
                    </svg>
                    {{ trans('front.send_command') }}
                </button>
            </div>
        </section>
    @endisset

    {{-- Bloqueo de motor (plugin locking_widget) --}}
    @isset($locking)
        @php
            $lockSt = $locking['status'];
            $lockLabel = is_null($lockSt) ? trans('front.unknown') : ($lockSt ? trans('front.locked') : trans('front.unlocked'));
            $lockTone = is_null($lockSt) ? 'unknown' : ($lockSt ? 'on' : 'off');
        @endphp
        <section class="tp-card tp-card--flat" data-tp-locking data-device-id="{{ $device->id }}"
            data-command-url="{{ $locking['command_url'] }}" data-history-url="{{ $locking['history_url'] }}"
            data-templates-url="{{ $locking['templates_url'] }}" data-can-sms="{{ $locking['can_sms'] ? '1' : '' }}">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" />
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                    </svg>
                    <span>{{ trans('ui.engine_lock') }}</span>
                </span>
                <span class="tp-lock-badge" data-lock-badge data-tone="{{ $lockTone }}">{{ $lockLabel }}</span>
            </h4>
            <div class="tp-dw__card-body tp-dw__card-body--center">
                <div style="display:flex;gap:8px;">
                    <button type="button" class="tp-btn tp-btn--sm tp-btn--danger" style="flex:1;" data-lock-action="on">
                        Cortar
                    </button>
                    <button type="button" class="tp-btn tp-btn--sm tp-btn--secondary" style="flex:1;" data-lock-action="off">
                        Restablecer
                    </button>
                </div>
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-full tp-mt-2" data-lock-history>
                    {{ trans('front.history') }}
                </button>
            </div>
        </section>
    @endisset

    {{-- Video en vivo --}}
    @isset($video_live_url)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m22 8-6 4 6 4V8Z" />
                        <rect x="2" y="6" width="14" height="12" rx="2" />
                    </svg>
                    <span>{{ trans('ui.live_video') }}</span>
                </span>
            </h4>
            <div class="tp-dw__card-body tp-dw__card-body--center">
                <div class="tp-stack" style="gap:8px;">
                    <a class="tp-btn tp-btn--secondary tp-btn--block" href="{{ $video_live_url }}" target="_blank" rel="noopener">{{ trans('ui.live') }}</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--block" href="{{ $video_playback_url }}" target="_blank" rel="noopener">{{ trans('front.playback') }}</a>
                </div>
            </div>
        </section>
    @endisset

</div>
