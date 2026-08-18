@extends('ui.v2.layout')

@section('title', $pageTitle ?? trans('front.send_command'))
@section('rail-active', 'send_command')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/services.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/commands.css') }}?v=1">
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

        {{-- Título --}}
        <div class="flex items-center gap-2.5 mb-4">
            <span class="text-[color:var(--brand-500,#0d9488)]">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 2L11 13" />
                    <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                </svg>
            </span>
            <h2 class="m-0 text-[16px] font-bold text-slate-900 dark:text-white">
                {{ trans('front.send_command') }}</h2>
        </div>

        {{-- Tabs subrayadas a lo ancho (estilo referencia) --}}
        <div class="flex border-0 border-b border-solid border-slate-200 dark:border-slate-600 mb-4"
            role="tablist" id="tp-cmdhub-tabs">
            <button type="button" data-hub-tab="schedule">{{ trans('front.schedule') }}</button>
            <button type="button" data-hub-tab="logs">{{ trans('front.logs') }}</button>
            <button type="button" data-hub-tab="pending">{{ trans('front.queued') }}</button>
            <button type="button" data-hub-tab="gprs">{{ trans('front.gprs_templates') }}</button>
            <button type="button" data-hub-tab="sms">{{ trans('front.sms_templates') }}</button>
        </div>

        {{-- Toolbar: búsqueda + acciones (labels visibles en desktop) --}}
        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div class="flex items-center gap-2.5">
                <input type="text" class="tp-input" style="max-width:260px;" id="tp-cmdhub-search"
                    placeholder="{{ trans('front.search') }}…" autocomplete="off">
                {{-- Filtro por conexión (solo tab Registros) --}}
                <select class="tp-select" id="tp-cmdhub-conn" hidden style="width:130px;">
                    <option value="">{{ trans('front.all') }}</option>
                    <option value="gprs">{{ trans('front.gprs') }}</option>
                    <option value="sms">{{ trans('front.sms') }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2.5">
                <button type="button" id="tp-cmdhub-add" hidden
                    class="appearance-none cursor-pointer h-9 px-4 rounded-lg text-[13px] font-semibold bg-white
                           border border-solid border-[color:var(--brand-500,#0d9488)]
                           text-[color:var(--brand-600,#0d9488)]
                           hover:bg-[color:var(--brand-50,#f0fdfa)]
                           dark:bg-transparent dark:hover:bg-slate-700/40">
                    <span id="tp-cmdhub-add-label"></span>
                </button>
                <button type="button" class="tp-btn tp-btn--accent" data-tp-commands>
                    {{ trans('front.send_command') }}
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
    <script src="{{ asset('assets/ui/v2/js/commands-page.js') }}?v=9" defer></script>
@endpush
