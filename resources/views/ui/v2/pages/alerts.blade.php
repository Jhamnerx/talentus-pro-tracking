@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.alerts'))
@section('rail-active', 'alerts')

@push('head')
    {{-- Tabla índice + kebab compartidos (mismo estilo que call-actions / maintenance) --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/services.css') }}">
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/alerts.css') }}">
    {{-- Formularios del modal de la regla (.tp-form* / inputs) --}}
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/device-modal.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-alerts" data-data-url="{{ route('ui.v2.api.alerts') }}"
        data-meta-url="{{ route('ui.v2.api.alerts.meta') }}" data-store-url="{{ route('ui.v2.api.alerts.store') }}"
        data-commands-url="{{ route('ui.v2.api.alerts.commands') }}" data-toggle-url="{{ url('/ui/v2/api/alerts') }}"
        data-can-edit="{{ $canEdit ? 1 : 0 }}" data-can-create="{{ $canCreate ? 1 : 0 }}"
        data-can-remove="{{ !empty($canRemove) ? 1 : 0 }}"
        data-is-admin="{{ isAdmin() ? 1 : 0 }}">

        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 01-3.46 0" />
                    </svg>
                </span>
                {{ trans('front.alerts') }}
                <span class="tp-text-muted tp-text-sm" id="tp-alerts-count"
                    style="margin-left:8px; font-weight:400;">—</span>
            </h2>
            <div class="tp-maint__tools" style="display:flex; gap:10px; align-items:center;">
                <input aria-label="{{ trans('ui.search_rule') }}" type="search" class="tp-input" id="tp-alerts-search" placeholder="{{ trans('ui.search_rule') }}"
                    autocomplete="off" style="min-width:220px;">
                <button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-alerts-refresh" title="Actualizar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 11-3-6.7L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                </button>
                @if ($canCreate)
                    {{-- Las plantillas son cosa del administrador: sirven para sembrar
                         alertas a los clientes al darlos de alta. --}}
                    @if (isAdmin())
                        <button type="button" class="tp-btn tp-btn--ghost" id="tp-alerts-templates">{{ trans('front.template_webhook') }}</button>
                    @endif
                    <button type="button" class="tp-btn tp-btn--accent" id="tp-alerts-new">{{ trans('ui.new_rule_plus') }}</button>
                @endif
            </div>
        </header>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-alerts-table">
                    <thead>
                        <tr>
                            <th style="width:66px;">{{ trans('front.state') }}</th>
                            <th>{{ trans('ui.name') }}</th>
                            <th>{{ trans('front.type') }}</th>
                            <th>Alcance</th>
                            <th>{{ trans('front.notifications') }}</th>
                            <th>{{ trans('front.schedule') }}</th>
                            <th class="tp-svc-table__actions"></th>
                        </tr>
                    </thead>
                    <tbody id="tp-alerts-rows">
                        <tr>
                            <td colspan="7" class="tp-svc-table__empty">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset_resource('assets/ui/v2/js/alerts.js') }}" defer></script>
@endpush
