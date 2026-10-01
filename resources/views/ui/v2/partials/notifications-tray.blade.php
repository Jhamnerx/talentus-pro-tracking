{{-- Notifications tray (right drawer) --}}
<div class="tp-tray" id="tp-notif-tray" role="dialog" aria-label="{{ trans('front.notifications') }}" hidden>
    <div class="tp-tray__scrim" data-tray-close></div>
    <aside class="tp-tray__panel" role="document">
        <header class="tp-tray__head">
            <div>
                <div class="tp-h6">{{ trans('front.notifications') }}</div>
                <div class="tp-text-xs tp-text-muted">{{ trans('ui.recent_events_alerts') }}</div>
            </div>
            <div class="tp-row">
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-notif-mark-all">{{ trans('ui.mark_as_read') }}</button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-tray-close aria-label="{{ trans('front.close') }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="tp-tray__filters">
            <div class="tp-segmented">
                <button class="tp-segmented__item" aria-selected="true" data-notif-filter="all">{{ trans('ui.all_f') }}</button>
                <button class="tp-segmented__item" data-notif-filter="critical">{{ trans('ui.critical_plural_f') }}</button>
                <button class="tp-segmented__item" data-notif-filter="alerts">{{ trans('front.alerts') }}</button>
                <button class="tp-segmented__item" data-notif-filter="system">Informativos</button>
            </div>
        </div>

        <div class="tp-tray__body" id="tp-notif-list">
            <div class="tp-tray__empty">
                <div class="tp-text-muted">{{ trans('ui.no_new_notifications') }}</div>
            </div>
        </div>

        <footer class="tp-tray__foot">
            <a href="{{ url('/ui/v2/events') }}" class="tp-btn tp-btn--ghost tp-btn--block">{{ trans('ui.see_all_events') }}
                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </footer>
    </aside>
</div>
