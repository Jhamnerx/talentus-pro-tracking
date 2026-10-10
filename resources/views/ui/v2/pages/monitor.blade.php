@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('ui.group_monitor'))
@section('rail-active', 'monitor')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/monitor.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-mon" id="tp-monitor" data-devices-url="{{ route('ui.v2.api.devices') }}"
        data-updates-url="{{ route('ui.v2.api.devices.updates') }}"
        data-zones-url="{{ route('ui.v2.api.zones') }}" data-pois-url="{{ route('ui.v2.api.pois') }}"
        data-routes-url="{{ route('ui.v2.api.routes') }}">

        <script type="application/json" id="tp-monitor-map-config">@json($mapConfig)</script>

        <header class="tp-mon__head">
            <h1 class="tp-h5 tp-mb-0">{{ trans('ui.group_monitor') }}</h1>
            <span class="tp-text-muted tp-text-sm" id="tp-mon-hint"></span>

            {{-- Capas del mapa: las mismas que ofrece Live, aplicadas a todas
                 las celdas a la vez. --}}
            <div class="tp-mon__layers" role="group" aria-label="{{ trans('front.map') }}">
                <button type="button" class="tp-mon__layer" data-mon-layer="zones"
                    aria-pressed="false">{{ trans('front.geofences') }}</button>
                <button type="button" class="tp-mon__layer" data-mon-layer="pois"
                    aria-pressed="false">{{ trans('front.poi') }}</button>
                <button type="button" class="tp-mon__layer" data-mon-layer="routes"
                    aria-pressed="false">{{ trans('front.routes') }}</button>
                <button type="button" class="tp-mon__layer" data-mon-layer="tails"
                    aria-pressed="false">{{ trans('front.tail') }}</button>
            </div>

            {{-- Los botones los pinta monitor.js desde su tabla LAYOUTS, para
                 que no haya dos listas de tamaños que puedan discrepar. --}}
            <div class="tp-mon__sizes">
                <span class="tp-mon__sizeslabel">Celdas</span>
                <div class="tp-segmented" id="tp-mon-sizes" role="tablist"
                    aria-label="{{ trans('ui.grid_size') }}"></div>
            </div>
        </header>

        {{-- Las celdas las renderiza monitor.js (vacía = botón +, llena = mini-mapa) --}}
        <div class="tp-mon__grid" id="tp-mon-grid"></div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-basemaps.js') }}"></script>
    {{-- Animacion de movimiento del marcador: el mismo plugin que el legacy --}}
    <script src="{{ asset_resource('assets/ui/v2/js/lib/Leaflet.Marker.SlideTo.js') }}"></script>
    {{-- Geocercas, POIs y rutas sobre varios mapas a la vez --}}
    <script src="{{ asset_resource('assets/ui/v2/js/tp-map-layers.js') }}"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/monitor.js') }}" defer></script>
@endpush
