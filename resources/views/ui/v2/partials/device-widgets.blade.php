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

    {{-- Objeto (ficha básica del vehículo) --}}
    @isset($device_info)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Objeto</h4>
            <dl class="tp-defs">
                <dt>Estado</dt><dd>{{ $device_info['status_text'] ?? '—' }}</dd>
                <dt>Dirección</dt><dd>{{ $device_info['address'] ?? '—' }}</dd>
                <dt>Hora</dt><dd>{{ $device_info['time'] ?? '—' }}</dd>
                <dt>Duración de parada</dt><dd>{{ $device_info['stop'] ?? '—' }}</dd>
                @if (!empty($device_info['is_admin']))
                    <dt>IMEI</dt><dd>{{ $device_info['imei'] ?? '—' }}</dd>
                    <dt>SIM</dt><dd>{{ $device_info['sim'] ?? '—' }}</dd>
                @endif
                <dt>Conductor</dt><dd>{{ $device_info['driver'] ?? '—' }}</dd>
            </dl>
        </section>
    @endisset

    {{-- Ubicación --}}
    @isset($location)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Ubicación</h4>
            @if ($location)
                <dl class="tp-defs">
                    <dt>Ciudad</dt><dd>{{ $location['city'] ?? '—' }}</dd>
                    <dt>Vía</dt><dd>{{ $location['road'] ?? '—' }}</dd>
                    <dt>País</dt><dd>{{ $location['country'] ?? '—' }}</dd>
                    <dt>Dirección</dt><dd>{{ $location['address'] ?? '—' }}</dd>
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">Sin dirección disponible.</p>
            @endif
        </section>
    @endisset

    {{-- Sensores --}}
    @isset($sensors)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Sensores</h4>
            @if (count($sensors))
                <dl class="tp-defs">
                    @foreach ($sensors as $sensor)
                        <dt>{{ $sensor['name'] }}</dt>
                        <dd>{{ $sensor['formated'] }}</dd>
                    @endforeach
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">Sin sensores configurados.</p>
            @endif
        </section>
    @endisset

    {{-- Imagen del vehículo --}}
    @isset($image)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Imagen</h4>
            @if ($image)
                <a href="{{ $image }}" target="_blank" rel="noopener" class="tp-dw__image">
                    <img src="{{ $image }}" alt="Imagen del vehículo" loading="lazy">
                </a>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">Sin imagen.</p>
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
                    <span>Eventos recientes</span>
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
                    <p class="tp-text-xs tp-text-muted tp-mb-0">Sin eventos recientes.</p>
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
                    <span>Vista de calle</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                @if ($streetview)
                    <div class="tp-dw__streetview">
                        <img src="{{ $streetview }}" alt="Street View" loading="lazy">
                    </div>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">Street View no disponible (falta API key).</p>
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
                        <span>Cámara</span>
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

    {{-- Conductor --}}
    @isset($driver)
        <section class="tp-card tp-card--flat">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                    <span>Conductor</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                @if ($driver)
                    <dl class="tp-defs">
                        <dt>Nombre</dt><dd>{{ $driver->name ?? '—' }}</dd>
                        <dt>RFID</dt><dd>{{ $driver->rfid ?? '—' }}</dd>
                        <dt>Teléfono</dt><dd>{{ $driver->phone ?? '—' }}</dd>
                        <dt>Email</dt><dd>{{ $driver->email ?? '—' }}</dd>
                    </dl>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">Sin conductor asignado.</p>
                @endif
            </div>
        </section>
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
                    <dl class="tp-defs">
                        @foreach ($fuel as $f)
                            <dt>{{ $f['name'] }}</dt><dd>{{ $f['value'] }}</dd>
                        @endforeach
                    </dl>
                @else
                    <p class="tp-text-xs tp-text-muted tp-mb-0">Sin sensores de combustible.</p>
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
            <div class="tp-dw__card-body">
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
                    <span>Video en vivo</span>
                </span>
            </h4>
            <div class="tp-dw__card-body">
                <div class="tp-stack" style="gap:8px;">
                    <a class="tp-btn tp-btn--secondary tp-btn--block" href="{{ $video_live_url }}" target="_blank" rel="noopener">En vivo</a>
                    <a class="tp-btn tp-btn--ghost tp-btn--block" href="{{ $video_playback_url }}" target="_blank" rel="noopener">Reproducción</a>
                </div>
            </div>
        </section>
    @endisset

</div>
