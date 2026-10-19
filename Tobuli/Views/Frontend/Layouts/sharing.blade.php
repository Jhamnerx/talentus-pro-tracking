<!DOCTYPE html>
<html lang="{{ Language::iso() }}">

<head>
    @include('Frontend.Layouts.partials.head')
    {{-- Identidad v2 sobre el mapa público compartido: solo repinta los
         controles del legacy, sin tocar su marcado ni sus hooks. --}}
    <link rel="preconnect" href="https://rsms.me/" crossorigin>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    {{-- Tokens de la v2 (colores, radios, sombras, claro/oscuro) + piel de esta página. --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/sharing-public.css') }}">
    <script>
        // Tema como en la v2: la preferencia guardada (tp_theme) y, si no hay,
        // la del sistema. Antes de pintar, para no parpadear.
        (function () {
            var t = null;
            try { t = localStorage.getItem('tp_theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    @yield('styles')
</head>

<body style="overflow: hidden;">

    @include('Frontend.Layouts.partials.loading')

    <div id="mapWrap">
        <div id="map"></div>
        {{-- Controles del mapa: mismo bloque, iconos y estados que la v2
             (.tp-live__map-controls). Se conservan los ids y los onclick/onchange
             que usa app.js (sharingInit / changeSetting / toggleFitBounds). --}}
        <div id="map-controls" class="tp-sh-controls">
            <button type="button" class="tp-sh-btn" onclick="app.mapFull();" title="{{ trans('front.fullscreen') }}"
                aria-label="{{ trans('front.fullscreen') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                    </svg>
            </button>
            <div id="map-controls-tile">
            <button type="button" class="tp-sh-btn" onclick="$('.leaflet-control-layers').toggleClass('leaflet-control-layers-expanded');" title="{{ trans('ui.change_map_layer') }}"
                aria-label="{{ trans('ui.change_map_layer') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polygon points="12 2 22 8.5 12 15 2 8.5 12 2" />
                        <polyline points="2 15.5 12 22 22 15.5" />
                    </svg>
            </button>
            </div>
            <div class="tp-sh-divider" aria-hidden="true"></div>
            <div>
            <button type="button" class="tp-sh-btn" onclick="app.zoomIn();" title="{{ trans('front.zoom_in') }}"
                aria-label="{{ trans('front.zoom_in') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
            </button>
            <button type="button" class="tp-sh-btn" onclick="app.zoomOut();" title="{{ trans('front.zoom_out') }}"
                aria-label="{{ trans('front.zoom_out') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14" />
                    </svg>
            </button>
            </div>
            <div class="tp-sh-divider" aria-hidden="true"></div>
            <div id="map-controls-layers">
                <div class="tp-sh-group" data-toggle="buttons">
                    <label class="btn tp-sh-btn tp-sh-toggle" title="{{ trans('ui.cluster_nearby_vehicles') }}"
                        aria-label="{{ trans('ui.cluster_nearby_vehicles') }}">
                        <input id="clusterDevice" type="checkbox" autocomplete="off"
                            onchange="app.changeSetting('clusterDevice', this.checked);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="9" cy="9" r="3" />
                        <circle cx="16" cy="14" r="3" />
                        <circle cx="8" cy="16" r="2.5" />
                    </svg>
                    </label>
                    <label class="btn tp-sh-btn tp-sh-toggle" title="{!! trans('front.fit_objects') !!}"
                        aria-label="{!! trans('front.fit_objects') !!}">
                        <input id="fitBounds" type="checkbox" autocomplete="off"
                            onchange="app.devices.toggleFitBounds(this.checked);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 8V6a2 2 0 0 1 2-2h2" />
                        <path d="M16 4h2a2 2 0 0 1 2 2v2" />
                        <path d="M20 16v2a2 2 0 0 1-2 2h-2" />
                        <path d="M8 20H6a2 2 0 0 1-2-2v-2" />
                    </svg>
                    </label>
                    <label class="btn tp-sh-btn tp-sh-toggle" title="{!! trans('front.show_names') !!}"
                        aria-label="{!! trans('front.show_names') !!}">
                        <input id="showNames" type="checkbox" autocomplete="off"
                            onchange="app.changeSetting('showNames', this.checked);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 7h16" />
                        <path d="M4 12h10" />
                        <path d="M4 17h16" />
                    </svg>
                    </label>
                    <label class="btn tp-sh-btn tp-sh-toggle" title="{!! trans('front.show_tails') !!}"
                        aria-label="{!! trans('front.show_tails') !!}">
                        <input id="showTail" type="checkbox" autocomplete="off"
                            onchange="app.changeSetting('showTail', this.checked);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 19c4 0 4-6 8-6s4 6 8 6" />
                        <circle cx="20" cy="13" r="1.5" fill="currentColor" />
                    </svg>
                    </label>
                    <label class="btn tp-sh-btn tp-sh-toggle" title="{!! trans('front.live_traffic') !!}"
                        aria-label="{!! trans('front.live_traffic') !!}">
                        <input id="showTraffic" type="checkbox" autocomplete="off"
                            onchange="app.changeSetting('showTraffic', this.checked);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="8" y="2" width="8" height="20" rx="3" />
                        <circle cx="12" cy="7" r="1.2" fill="currentColor" />
                        <circle cx="12" cy="12" r="1.2" fill="currentColor" />
                        <circle cx="12" cy="17" r="1.2" fill="currentColor" />
                    </svg>
                    </label>
                </div>
            </div>
        </div>
    </div>

    @include('Frontend.Layouts.partials.trans')

    @yield('self-scripts')

    <script src="{{ asset_resource('assets/js/core.js') }}" type="text/javascript"></script>
    <script src="{{ asset_resource('assets/js/app.js') }}" type="text/javascript"></script>

    @if (file_exists(storage_path('custom/js.js')))
        <script src="{{ asset_resource('assets/js/custom.js', storage_path('custom/js.js')) }}" type="text/javascript"></script>
    @endif

    @yield('scripts')
    @include('Frontend.Layouts.partials.app')

    <script type="text/javascript">
        $(window).on("load", function() {
            app.sharingInit({!! json_encode($devices) !!});
        });
    </script>
</body>

</html>
