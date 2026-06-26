{{--
    UI v2 · Device widget cards (Live drawer)
    Renderizado por Ui\V2\DevicesController::widgets(). Cada bloque solo
    aparece si el widget está activo en la config del usuario (el controller
    solo define la variable cuando corresponde). Reutiliza datos del legacy.
--}}
<div class="tp-dw">

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

    {{-- Servicios --}}
    @isset($services)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Servicios</h4>
            @if (count($services))
                <dl class="tp-defs">
                    @foreach ($services as $service)
                        <dt>{{ $service->name ?? ('#' . $service->id) }}</dt>
                        <dd class="tp-text-muted">{{ $service->expiration_date ?? ($service->expires ?? '—') }}</dd>
                    @endforeach
                </dl>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">Sin servicios.</p>
            @endif
        </section>
    @endisset

    {{-- Eventos recientes --}}
    @isset($recent_events)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Eventos recientes</h4>
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
        </section>
    @endisset

    {{-- Vista de calle --}}
    @isset($streetview)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Vista de calle</h4>
            @if ($streetview)
                <div class="tp-dw__streetview">
                    <img src="{{ $streetview }}" alt="Street View" loading="lazy">
                </div>
            @else
                <p class="tp-text-xs tp-text-muted tp-mb-0">Street View no disponible (falta API key).</p>
            @endif
        </section>
    @endisset

    {{-- Cámara --}}
    @isset($camera_images)
        @if (!empty($camera_images) || !empty($camera_device_image))
            <section class="tp-card tp-card--flat tp-mb-4">
                <h4 class="tp-h6 tp-mb-3">Cámara</h4>
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
            </section>
        @endif
    @endisset

    {{-- Conductor --}}
    @isset($driver)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Conductor</h4>
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
        </section>
    @endisset

    {{-- Video en vivo --}}
    @isset($video_live_url)
        <section class="tp-card tp-card--flat tp-mb-4">
            <h4 class="tp-h6 tp-mb-3">Video en vivo</h4>
            <div class="tp-stack" style="gap:8px;">
                <a class="tp-btn tp-btn--secondary tp-btn--block" href="{{ $video_live_url }}" target="_blank" rel="noopener">En vivo</a>
                <a class="tp-btn tp-btn--ghost tp-btn--block" href="{{ $video_playback_url }}" target="_blank" rel="noopener">Reproducción</a>
            </div>
        </section>
    @endisset

</div>
