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

    {{-- Objeto (ficha unificada). Velocidad/Rumbo/Detenido/Distancia van arriba
         en el tablero (no se repiten aqui). Rediseño: estado con icono de color,
         etiqueta de motor y datos en cajitas. --}}
    @isset($device_info)
        @php
            // Color del estado: Device::getStatusColor() (los icon_colors del
            // vehiculo), con los mismos tokens --sc-* que la lista y el
            // marcador. Un hex propio se usa tal cual.
            $tpSc = strtolower(trim($device_info['status_color'] ?? ''));
            $tpScNombres = ['green', 'yellow', 'orange', 'red', 'blue', 'cyan', 'gray', 'black', 'white'];
            if (in_array($tpSc, $tpScNombres, true)) {
                $tpEstEstilo = 'background:var(--sc-' . $tpSc . ');color:var(--sc-' . $tpSc . '-on)';
            } elseif (preg_match('/^#[0-9a-f]{3,8}$/', $tpSc)) {
                $tpEstEstilo = 'background:' . $tpSc . ';color:#fff';
            } else {
                $tpEstEstilo = '';
            }
            $tpMotor = $device_info['engine'] ?? null;
        @endphp
        <section class="tp-card tp-card--flat tp-mb-4 tp-info">
            <h4 class="tp-info__ttl">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5"/><path d="M3 16h18v3H3z"/></svg>
                {{ trans('front.object') }}
            </h4>
            <div class="tp-info__state">
                <span class="tp-info__ic" style="{{ $tpEstEstilo }}" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </span>
                <div class="tp-info__state-txt">
                    <strong>{{ $device_info['status_text'] ?? '—' }}</strong>
                    <span>{{ trans('ui.last_signal') }} {{ $device_info['time'] ?? '—' }}</span>
                </div>
                @if ($tpMotor)
                    <span class="tp-info__chip tp-info__chip--{{ $tpMotor === 'Encendido' ? 'on' : 'off' }}" title="{{ trans('front.engine') }}: {{ $tpMotor }}">
                        <span class="tp-info__dot"></span>{{ trans('front.engine') }}
                    </span>
                @endif
            </div>
            <div class="tp-info__facts">
                <div class="tp-info__fact"><span>{{ trans('front.speed') }}</span><b>{{ $device_info['speed'] ?? '—' }}</b></div>
                <div class="tp-info__fact"><span>{{ trans('front.altitude') }}</span><b>{{ $device_info['altitude'] ?? '—' }}</b></div>
                @if (!empty($device_info['is_admin']))
                    <div class="tp-info__fact"><span>{{ trans('front.model') }}</span><b>{{ $device_info['model'] ?: '—' }}</b></div>
                    <div class="tp-info__fact"><span>SIM</span><b class="tp-text-mono">{{ $device_info['sim'] ?: '—' }}</b></div>
                    <div class="tp-info__fact tp-info__fact--wide"><span>IMEI</span><b class="tp-text-mono">{{ $device_info['imei'] ?? '—' }}</b></div>
                @endif
            </div>
            <div class="tp-info__driver">
                <span class="tp-info__avatar{{ $device_info['driver'] ? '' : ' tp-info__avatar--none' }}" aria-hidden="true">
                    @if ($device_info['driver'])
                        {{ mb_strtoupper(mb_substr($device_info['driver'], 0, 1)) }}
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    @endif
                </span>
                <div>
                    <span>{{ trans('front.driver') }}</span>
                    <b>{{ $device_info['driver'] ?? trans('ui.no_driver_assigned') }}</b>
                </div>
            </div>
        </section>

        {{-- Ubicación: tarjeta propia SIEMPRE (como la propuesta), no mezclada
             con Objeto. Via destacada, la direccion completa debajo y, con el
             widget Ubicación activo, las etiquetas de ciudad y pais. Los
             data-tp-addr los rellena live.js con devices/{id}/address. --}}
        <section class="tp-card tp-card--flat tp-mb-4 tp-info">
            <h4 class="tp-info__ttl">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                {{ trans('front.location') }}
            </h4>
            <div class="tp-info__addr">
                <span class="tp-info__ic tp-info__ic--pin" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                </span>
                <div class="tp-info__addr-txt">
                    <strong data-tp-addr="road">…</strong>
                    <span data-tp-addr="address">…</span>
                    @isset($location)
                        <div class="tp-info__crumbs">
                            <span data-tp-addr="city"></span>
                            <span data-tp-addr="country"></span>
                        </div>
                    @endisset
                </div>
            </div>
            @if (!empty($device_info['coords']))
                @php $tpCoords = str_replace(' ', '', $device_info['coords']); @endphp
                <div class="tp-info__coords">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                    <span class="tp-text-mono">{{ $device_info['coords'] }}</span>
                    <button type="button" class="tp-info__copy" data-tp-copy="{{ $tpCoords }}"
                        title="{{ trans('ui.copy_coordinates') }}" aria-label="{{ trans('ui.copy_coordinates') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    </button>
                    <a href="https://www.google.com/maps/search/?api=1&query={{ $tpCoords }}" target="_blank" rel="noopener"
                        class="tp-info__copy" title="{{ trans('ui.open_in_map') }}" aria-label="{{ trans('ui.open_in_map') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
                    </a>
                </div>
            @endif
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

    {{-- Sensores en mosaico, agrupados por tipo. TODOS los tipos del sistema
         (Tobuli/Sensors/Types) tienen grupo y color; uno nuevo cae en «Otros».
         Los logicos se ven como etiqueta ON/OFF; los de % llevan barra. --}}
    @isset($sensors)
        @php
            // tipo => [grupo, color del icono]
            $tpSensorMeta = [
                // Estado (encendido/apagado)
                'acc' => ['state', '#16a34a'], 'ignition' => ['state', '#16a34a'], 'engine' => ['state', '#16a34a'],
                'blocked' => ['state', '#dc2626'], 'door' => ['state', '#0891b2'], 'seatbelt' => ['state', '#0e7490'],
                'plugged' => ['state', '#16a34a'], 'anonymizer' => ['state', '#64748b'], 'logical' => ['state', '#0891b2'],
                'drive_business' => ['state', '#2563eb'], 'drive_private' => ['state', '#7c3aed'],
                'harsh_acceleration' => ['state', '#dc2626'], 'harsh_breaking' => ['state', '#dc2626'], 'harsh_turning' => ['state', '#dc2626'],
                // Energía
                'battery' => ['energy', '#7c3aed'], 'battery_external' => ['energy', '#16a34a'],
                // Motor y recorrido
                'odometer' => ['engine', '#2563eb'], 'engine_hours' => ['engine', '#7c3aed'],
                'speed_ecm' => ['engine', '#0ea5e9'], 'tachometer' => ['engine', '#0ea5e9'],
                // Combustible y medidas
                'fuel_tank' => ['measures', '#d97706'], 'fuel_consumption' => ['measures', '#d97706'],
                'temperature' => ['measures', '#ea580c'], 'load' => ['measures', '#a16207'],
                'counter' => ['measures', '#0e2157'], 'numerical' => ['measures', '#0e2157'],
                // Comunicación
                'gsm' => ['comms', '#db2777'], 'satellites' => ['comms', '#0891b2'],
                // Identificación
                'rfid' => ['ident', '#4f46e5'], 'vin' => ['ident', '#334155'], 'textual' => ['ident', '#334155'],
                'datetime' => ['ident', '#475569'],
                'route_color' => ['ident', '#9333ea'], 'route_color_2' => ['ident', '#9333ea'], 'route_color_3' => ['ident', '#9333ea'],
            ];
            // Variantes con calibracion: mismo grupo que su base
            foreach (['fuel_tank_calibration' => 'fuel_tank', 'temperature_calibration' => 'temperature', 'load_calibration' => 'load'] as $k => $base) {
                $tpSensorMeta[$k] = $tpSensorMeta[$base];
            }
            $tpGrupos = ['state', 'energy', 'engine', 'measures', 'comms', 'ident', 'other'];
            $tpPorGrupo = array_fill_keys($tpGrupos, []);
            foreach ($sensors as $sn) {
                $meta = $tpSensorMeta[$sn['type'] ?? ''] ?? ['other', '#64748b'];
                $sn['color'] = $meta[1];
                $txt = trim((string) ($sn['formated'] ?? ''));
                // Numero y unidad separados ("287196 Km" → "287196" + "Km")
                $sn['num'] = $txt; $sn['unit'] = '';
                if (preg_match('/^(-?[\d.,]+)\s*(\S.*)?$/u', $txt, $mm)) {
                    $sn['num'] = $mm[1];
                    $sn['unit'] = $mm[2] ?? '';
                }
                // Logicos: etiqueta encendido/apagado; los de alerta en rojo
                $sn['chip'] = null;
                if ($meta[0] === 'state') {
                    $on = is_bool($sn['raw'] ?? null) ? $sn['raw'] : (in_array(mb_strtolower($txt), ['on', 'si', 'sí', 'yes', 'true', '1']));
                    $alerta = in_array($sn['type'], ['blocked', 'harsh_acceleration', 'harsh_breaking', 'harsh_turning']);
                    $sn['chip'] = $on ? ($alerta ? 'alert' : 'on') : 'off';
                }
                // Barra de nivel para los porcentajes
                $sn['bar'] = null;
                if ($sn['chip'] === null && trim($sn['unit']) === '%' && is_numeric(str_replace(',', '.', $sn['num']))) {
                    $sn['bar'] = max(0, min(100, (float) str_replace(',', '.', $sn['num'])));
                } elseif (($sn['type'] ?? '') === 'gsm' && is_numeric($sn['raw'] ?? null)) {
                    // Señal GSM: el legacy pinta el escalon con valor/20 (0-100)
                    $sn['bar'] = max(0, min(100, (float) $sn['raw']));
                }
                $tpPorGrupo[$meta[0]][] = $sn;
            }
        @endphp
        <section class="tp-card tp-card--flat tp-mb-4 tp-info">
            <h4 class="tp-info__ttl">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 20h2M6 20v-4M10 20v-8M14 20V8M18 20V4"/></svg>
                {{ trans('front.sensors') }}
                @if (count($sensors))<span class="tp-info__count">{{ count($sensors) }}</span>@endif
            </h4>
            @if (count($sensors))
                @foreach ($tpPorGrupo as $grupo => $lista)
                    @if (count($lista))
                        <div class="tp-sens__grp">{{ trans('ui.sensors_group_' . $grupo) }}</div>
                        <div class="tp-sens">
                            @foreach ($lista as $sn)
                                <div class="tp-sens__tile">
                                    @if (!empty($sn['custom_icon']))
                                        <span class="tp-sens__ic tp-sens__ic--img" aria-hidden="true">
                                            <img src="{{ $sn['custom_icon'] }}" alt="" loading="lazy">
                                        </span>
                                    @else
                                        <span class="tp-sens__ic" style="background:{{ $sn['color'] }}" aria-hidden="true">
                                            @include('ui.v2.partials.sensor-icon', ['type' => $sn['type'] ?? ''])
                                        </span>
                                    @endif
                                    <div class="tp-sens__body">
                                        <div class="tp-sens__name" title="{{ $sn['name'] }}">{{ $sn['name'] }}</div>
                                        @if ($sn['chip'])
                                            <span class="tp-info__chip tp-info__chip--{{ $sn['chip'] }}">{{ $sn['formated'] }}</span>
                                        @else
                                            <div class="tp-sens__val">{{ $sn['num'] }}@if ($sn['unit'] !== '')<small>{{ $sn['unit'] }}</small>@endif</div>
                                        @endif
                                        @if ($sn['bar'] !== null)
                                            <div class="tp-sens__bar"><i style="width:{{ $sn['bar'] }}%;background:{{ $sn['color'] }}"></i></div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
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
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#2563eb">
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
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M3 12h18M3 18h18" />
                    </svg>
                </button>
            </h4>
            <div class="tp-dw__card-body">
                @if (count($services))
                    {{-- Cada servicio con lo que falta en una etiqueta de color
                         (vencido / por vencer / al dia) y la barra de lo que queda
                         del intervalo (DeviceService::getPercentage). --}}
                    <div class="tp-svcw">
                        @foreach ($services as $service)
                            @php
                                $esDs = $service instanceof \Tobuli\Entities\DeviceService;
                                $tono = 'ok';
                                if ($esDs) {
                                    try {
                                        $tono = $service->isExpired() ? 'bad' : ($service->isExpiring() ? 'warn' : 'ok');
                                    } catch (\Throwable $e) { $tono = 'ok'; }
                                }
                                try { $resto = $esDs ? $service->left_formated() : null; } catch (\Throwable $e) { $resto = null; }
                                try { $pct = $esDs ? $service->getPercentage() : null; } catch (\Throwable $e) { $pct = null; }
                                $detalle = $esDs ? $service->expiration() : ($service->expiration_date ?? ($service->expires ?? '—'));
                            @endphp
                            <div class="tp-svcw__item">
                                <div class="tp-svcw__top">
                                    <span class="tp-svcw__name">{{ $service->name ?? ('#' . $service->id) }}</span>
                                    @if ($resto !== null && $resto !== '')
                                        <span class="tp-wbadge tp-wbadge--{{ $tono }}">{{ $resto }}</span>
                                    @endif
                                </div>
                                <div class="tp-svcw__det">{{ $detalle }}</div>
                                @if ($pct !== null)
                                    <div class="tp-wbar"><i class="tp-wbar--{{ $tono }}" style="width:{{ max(2, (int) $pct) }}%"></i></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    {{-- Estado vacío. El botón vive AQUÍ, como el .widget-empty del
                         legacy, que se esconde en cuanto la tabla tiene filas. Con
                         servicios ya cargados se añade desde el icono de la cabecera,
                         que abre la lista y allí tiene su propio "+ Añadir servicio". --}}
                    <p class="tp-text-xs tp-text-muted tp-mb-2">{{ trans('front.no_services') }}</p>
                    <button type="button" class="tp-btn tp-btn--sm tp-full tp-mt-2 tp-dw__add" data-tp-service="add"
                        data-device-id="{{ $device->id }}">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        {{ trans('front.add_service') }}
                    </button>
                @endif
            </div>
        </section>
    @endisset

    {{-- Eventos recientes --}}
    @isset($recent_events)
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#d97706">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                    </svg>
                    <span>{{ trans('front.recent_events') }}</span>
                </span>
                <a href="{{ url('/ui/v2/events') }}" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-wcard__act"
                    title="{{ trans('front.events') }}" aria-label="{{ trans('front.events') }}">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
                </a>
            </h4>
            <div class="tp-dw__card-body">
                @if (count($recent_events))
                    {{-- Linea de tiempo: punto de color por tipo de evento --}}
                    <ul class="tp-dw__events tp-evtl">
                        @foreach ($recent_events as $event)
                            @php
                                $tipo = (string) ($event->type ?? '');
                                $tonoEv = str_contains($tipo, 'zone') || str_contains($tipo, 'geofence') && !str_contains($tipo, 'overspeed') ? 'zone'
                                    : (str_contains($tipo, 'overspeed') || str_contains($tipo, 'harsh') || in_array($tipo, ['sos', 'accident', 'power_cut', 'jamming', 'fuel_theft', 'driver_unauthorized']) ? 'bad'
                                    : (in_array($tipo, ['move_start', 'move_start_filtered', 'ignition_on', 'fuel_fill', 'driver_authorized']) ? 'ok'
                                    : (str_contains($tipo, 'duration') || str_contains($tipo, 'stop') || str_contains($tipo, 'idle') ? 'mute' : 'info')));
                            @endphp
                            <li class="tp-evtl__item" data-tone="{{ $tonoEv }}">
                                <span class="tp-evtl__title">{{ $event->title ?? '' }}</span>
                                <span class="tp-evtl__meta">{{ $event->timePassed ?? '' }}@if (!empty($event->time)) · {{ \Formatter::time()->human($event->time) }}@endif</span>
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
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#dc2626">
            <h4 class="tp-h6 tp-dw__card-head">
                <span class="tp-dw__card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11z" />
                        <circle cx="12" cy="10" r="2.5" />
                    </svg>
                    <span>{{ trans('ui.street_view') }}</span>
                </span>
                @if ($device->lat && $device->lng)
                    <a href="https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={{ $device->lat }},{{ $device->lng }}" target="_blank" rel="noopener"
                        class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm tp-wcard__act" title="{{ trans('ui.open_in_map') }}" aria-label="{{ trans('ui.open_in_map') }}">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    </a>
                @endif
            </h4>
            <div class="tp-dw__card-body">
                @if (!empty($streetview_frame))
                    {{-- Embed interactivo (encuentra la panorámica más cercana, igual que el legacy). --}}
                    <iframe src="{{ $streetview_frame }}" title="Street View" loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade" allowfullscreen
                        class="tp-svw"></iframe>
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
            <section class="tp-card tp-card--flat tp-wcard" style="--wc:#0891b2">
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
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#d97706">
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
                                    <span class="tp-fuel-w__val"><b>{{ round($f['last'], 1) }}</b>{{ $f['unit'] }}</span>
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
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#7c3aed">
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
                <p class="tp-wcard__hint">{{ trans('ui.gprs_command_hint') }}</p>
                <button type="button" class="tp-btn tp-btn--sm tp-full tp-dw__add tp-wcard__main" data-tp-commands
                    data-device-id="{{ $device->id }}">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
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
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#0e2157" data-tp-locking data-device-id="{{ $device->id }}"
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
            <div class="tp-dw__card-body">
                {{-- Estado grande con candado (cortado en rojo, habilitado en verde) --}}
                <div class="tp-lockw" data-tone="{{ $lockTone }}">
                    <span class="tp-lockw__ic" aria-hidden="true">
                        @if ($lockTone === 'on')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                        @endif
                    </span>
                    <b>{{ $lockLabel }}</b>
                </div>
                <div class="tp-lockw__btns">
                    <button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-lock-action="on">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        {{ trans('ui.cut_engine') }}
                    </button>
                    <button type="button" class="tp-btn tp-btn--sm tp-btn--secondary" data-lock-action="off">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                        {{ trans('ui.restore_engine') }}
                    </button>
                </div>
                <button type="button" class="tp-wcard__link" data-lock-history>{{ trans('front.history') }} ↗</button>
            </div>
        </section>
    @endisset

    {{-- Video en vivo: JT1078 con el reproductor de la v2 (tp-device-video.js). --}}
    @isset($video_channels)
        <section class="tp-card tp-card--flat tp-wcard" style="--wc:#db2777">
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
                        @if (count($video_channels) > 1)
                            <div class="tp-row" style="gap:6px;flex-wrap:wrap;justify-content:center;">
                                @foreach ($video_channels as $vch)
                                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tp-video="live"
                                        data-device-id="{{ $device->id }}" data-channel="{{ $vch }}"
                                        title="{{ trans('front.channel') }} {{ $vch }}{{ !empty($video_roles[$vch]) ? ' · ' . trans('front.video_role_' . $video_roles[$vch]) : '' }}">{{ $vch }}</button>
                                @endforeach
                            </div>
                        @endif
                        <button type="button" class="tp-btn tp-btn--secondary tp-btn--block" data-tp-video="live"
                            data-device-id="{{ $device->id }}">{{ trans('ui.live') }}</button>
                        <button type="button" class="tp-btn tp-btn--ghost tp-btn--block" data-tp-video="recordings"
                            data-device-id="{{ $device->id }}">{{ trans('front.video_recordings') }}</button>
                </div>
            </div>
        </section>
    @endisset

</div>
