@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.send_command'))
@section('rail-active', 'send_command')

@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/services.css') }}">
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/commands.css') }}">
@endpush

@section('workspace-class', 'tp-workspace--full')

@section('workspace')
    <div class="tp-maint" id="tp-cmdhub">
        {{-- Config del hub (urls + i18n extra sobre tp-commands-config) --}}
        <script type="application/json" id="tp-cmdhub-config">
        {!! json_encode([
            'urls' => [
                'schedules' => route('ui.v2.api.commands.schedules'),
                'logs'      => route('ui.v2.api.commands.logs'),
                'pendings'  => route('ui.v2.api.commands.pendings'),
                'templates' => route('ui.v2.api.commands.templates'),
                'meta'      => route('ui.v2.api.commands.meta'),
                'list'      => route('ui.v2.api.commands.list'),
            ],
            'i18n' => [
                'schedule'       => trans('front.schedule'),
                'logs'           => trans('front.logs'),
                'gprs_templates' => trans('front.gprs_templates'),
                'sms_templates'  => trans('front.sms_templates'),
                'command_schedule' => trans('front.command_schedule'),
                'connection'     => trans('front.connection'),
                'command'        => trans('front.command'),
                'parameters'     => trans('validation.attributes.parameters'),
                'schedule_at'    => trans('front.schedule_at'),
                'type'           => trans('validation.attributes.type'),
                'device'         => trans('validation.attributes.device_id'),
                'status'         => trans('front.status'),
                'date'           => trans('front.date'),
                'title'          => trans('validation.attributes.title'),
                'adapted'        => trans('validation.attributes.adapted'),
                'protocol'       => trans('validation.attributes.device_protocol'),
                'device_types'   => trans('validation.attributes.device_type_id'),
                'message'        => trans('validation.attributes.message'),
                'devices'        => trans('validation.attributes.devices'),
                'add_template'   => trans('front.add_template'),
                'add_schedule'   => trans('front.add_schedule'),
                'raw_hint'       => trans('front.raw_command_supports'),
                'variables_hint' => trans('front.gprs_template_variables'),
                'exact_time'     => trans('front.exact_time'),
                'hourly'         => trans('front.hourly'),
                'daily'          => trans('front.daily'),
                'weekly'         => trans('validation.weekly'),
                'monthly'        => trans('front.monthly'),
                'minute'         => trans('front.minute'),
                'time'           => trans('front.time'),
                'day'            => trans('global.days'),
                'save'           => trans('global.save'),
                'cancel'         => trans('global.cancel'),
                'edit'           => trans('global.edit'),
                'delete'         => trans('global.delete'),
                'search'         => trans('front.search'),
                'select_all'     => trans('front.select_all'),
                'deselect_all'   => trans('front.deselect_all'),
                'are_you_sure'   => trans('front.are_you_sure'),
                'changes_saved'  => trans('front.successfully_saved'),
                'loading'        => trans('front.loading'),
                'error'          => trans('front.unexpected_error'),
                'rows_per_page'  => trans('front.rows_per_page'),
                'previous'       => trans('front.previous'),
                'next'           => trans('front.next'),
                'not_found'      => trans('global.not_found'),
                'active'         => trans('validation.attributes.active'),
                'sms_gateway_disabled' => trans('front.sms_gateway_disabled'),
                'user'           => trans('global.user'),
                'all'            => trans('front.all'),
                'sent'           => trans('front.sent'),
                'failed'         => trans('global.failed'),
                'response'       => trans('front.response'),
                'attempts'       => trans('front.attempts'),
                'queued'         => trans('front.queued'),
                'queued_for_online' => trans('front.queued_for_online'),
                'auto_send_when_online' => trans('front.auto_send_when_online'),
            ],
        ], JSON_UNESCAPED_UNICODE) !!}
        </script>

        {{-- Cabecera común (QA V1): icono en cuadro + título; la acción
             principal «Enviar comando» a la derecha (QA V2). --}}
        <header class="tp-maint__head">
            <h2 class="tp-maint__title">
                <span class="tp-maint__title-ico">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 2L11 13" />
                        <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                    </svg>
                </span>
                {{ trans('front.send_command') }}
            </h2>
            <div class="tp-maint__tools">
                <button type="button" class="tp-btn tp-btn--primary" data-tp-commands>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 2L11 13" />
                        <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                    </svg>
                    {{ trans('front.send_command') }}
                </button>
            </div>
        </header>

        {{-- Pestañas: el subrayado común (.tp-tabs__nav, QA V5) --}}
        <div class="tp-tabs__nav tp-tabs__nav--page" role="tablist" id="tp-cmdhub-tabs">
            <button type="button" class="tp-tabs__tab" role="tab" data-hub-tab="schedule">{{ trans('front.schedule') }}</button>
            <button type="button" class="tp-tabs__tab" role="tab" data-hub-tab="logs">{{ trans('front.logs') }}</button>
            <button type="button" class="tp-tabs__tab" role="tab" data-hub-tab="pending">{{ trans('front.queued') }}</button>
            <button type="button" class="tp-tabs__tab" role="tab" data-hub-tab="gprs">{{ trans('front.gprs_templates') }}</button>
            <button type="button" class="tp-tabs__tab" role="tab" data-hub-tab="sms">{{ trans('front.sms_templates') }}</button>
        </div>

        {{-- Toolbar: búsqueda + alta de la pestaña (secundaria: la principal
             de la página es «Enviar comando», QA V3) --}}
        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div class="flex items-center gap-2.5">
                <input type="text" class="tp-input" style="max-width:260px;" id="tp-cmdhub-search" aria-label="{{ trans('front.search') }}"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                {{-- Filtro por conexión (solo tab Registros) --}}
                <select class="tp-select" id="tp-cmdhub-conn" hidden style="width:130px;">
                    <option value="">{{ trans('front.all') }}</option>
                    <option value="gprs">{{ trans('front.gprs') }}</option>
                    <option value="sms">{{ trans('front.sms') }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2.5">
                <button type="button" id="tp-cmdhub-add" hidden class="tp-btn tp-btn--secondary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    <span id="tp-cmdhub-add-label"></span>
                </button>
            </div>
        </div>

        <div class="tp-maint__card">
            <div class="tp-maint__wrap">
                <table class="tp-svc-table" id="tp-cmdhub-table">
                    <thead id="tp-cmdhub-thead"></thead>
                    <tbody id="tp-cmdhub-rows">
                        <tr>
                            <td colspan="9" class="tp-svc-table__empty">{{ trans('front.loading') }}…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="tp-maint__foot">
                <label class="tp-svc-list__limit">{{ trans('front.rows_per_page') }}
                    <select class="tp-select" id="tp-cmdhub-limit">
                        <option>20</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                </label>
                <div class="tp-svc-list__pager">
                    <span id="tp-cmdhub-range">—</span>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-cmdhub-prev"
                        aria-label="{{ trans('front.previous') }}">‹</button>
                    <button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" id="tp-cmdhub-next"
                        aria-label="{{ trans('front.next') }}">›</button>
                </div>
            </footer>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset_resource('assets/ui/v2/js/commands-page.js') }}" defer></script>
@endpush
