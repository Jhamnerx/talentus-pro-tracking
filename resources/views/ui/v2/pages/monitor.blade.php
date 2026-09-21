@extends('ui.v2.layout')

@section('title', $pageTitle ?? 'Monitoreo de grupo')
@section('rail-active', 'monitor')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/monitor.css') }}?v=4">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-mon" id="tp-monitor" data-devices-url="{{ route('ui.v2.api.devices') }}"
        data-updates-url="{{ route('ui.v2.api.devices.updates') }}">

        <script type="application/json" id="tp-monitor-map-config">@json($mapConfig)</script>

        <header class="tp-mon__head">
            <h1 class="tp-h5 tp-mb-0">Monitoreo de grupo</h1>
            <span class="tp-text-muted tp-text-sm" id="tp-mon-hint"></span>

            {{-- Los botones los pinta monitor.js desde su tabla LAYOUTS, para
                 que no haya dos listas de tamaños que puedan discrepar. --}}
            <div class="tp-mon__sizes">
                <span class="tp-mon__sizeslabel">Celdas</span>
                <div class="tp-segmented" id="tp-mon-sizes" role="tablist"
                    aria-label="Tamaño de la cuadrícula"></div>
            </div>
        </header>

        {{-- Las celdas las renderiza monitor.js (vacía = botón +, llena = mini-mapa) --}}
        <div class="tp-mon__grid" id="tp-mon-grid"></div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('assets/ui/v2/js/tp-basemaps.js') }}?v=1"></script>
    <script src="{{ asset('assets/ui/v2/js/monitor.js') }}?v=2" defer></script>
@endpush
