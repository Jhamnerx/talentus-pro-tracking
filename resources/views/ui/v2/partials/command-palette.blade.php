{{-- Command Palette (?K / Ctrl+K) --}}
<div class="tp-cmdk" id="tp-cmdk" role="dialog" aria-modal="true" aria-label="Command Palette" hidden>
    <div class="tp-cmdk__scrim" data-cmdk-close></div>
    <div class="tp-cmdk__panel" role="document">
        <div class="tp-cmdk__head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8" />
                <path d="m21 21-4.35-4.35" />
            </svg>
            <input type="text" id="tp-cmdk-input" class="tp-cmdk__input"
                placeholder="{{ trans('ui.search_placeholder') }}" autocomplete="off" spellcheck="false" />
            <kbd class="tp-kbd">ESC</kbd>
        </div>

        <div class="tp-cmdk__body" id="tp-cmdk-body">
            <div class="tp-cmdk__group" data-group="recents">
                <div class="tp-cmdk__group-title">Recientes</div>
                <div class="tp-cmdk__empty">{{ trans('ui.no_recent_items') }}</div>
            </div>

            <div class="tp-cmdk__group" data-group="actions">
                <div class="tp-cmdk__group-title">{{ trans('ui.quick_actions') }}</div>

                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/live">
                    <span class="tp-cmdk__item-icon"><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                    <span class="tp-cmdk__item-label">{{ trans('ui.go_to_map_live') }}</span>
                    <span class="tp-cmdk__item-meta">{{ trans('ui.navigation') }}</span>
                </button>
                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/dashboard">
                    <span class="tp-cmdk__item-icon"><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg></span>
                    <span class="tp-cmdk__item-label">{{ trans('ui.go_to_dashboard') }}</span>
                    <span class="tp-cmdk__item-meta">{{ trans('ui.navigation') }}</span>
                </button>
                <button class="tp-cmdk__item" data-action="theme-toggle">
                    <span class="tp-cmdk__item-icon"><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
                    <span class="tp-cmdk__item-label">Cambiar tema (claro/oscuro)</span>
                    <span class="tp-cmdk__item-meta">Preferencias</span>
                </button>
                <button class="tp-cmdk__item" data-action="density-toggle">
                    <span class="tp-cmdk__item-icon"><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></span>
                    <span class="tp-cmdk__item-label">{{ trans('ui.toggle_density') }}</span>
                    <span class="tp-cmdk__item-meta">Preferencias</span>
                </button>
                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/legacy">
                    <span class="tp-cmdk__item-icon"><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></span>
                    <span class="tp-cmdk__item-label">{{ trans('ui.back_to_classic_ui') }}</span>
                    <span class="tp-cmdk__item-meta">Sistema</span>
                </button>
            </div>

            <div class="tp-cmdk__group" data-group="results" hidden>
                <div class="tp-cmdk__group-title" data-group-title>{{ trans('front.results') }}</div>
                <div data-results-list></div>
            </div>
        </div>

        <div class="tp-cmdk__foot">
            <span class="tp-cmdk__hint"><kbd class="tp-kbd"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;"><path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/></svg></kbd> navegar</span>
            <span class="tp-cmdk__hint"><kbd class="tp-kbd"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;"><polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/></svg></kbd> seleccionar</span>
            <span class="tp-cmdk__hint"><kbd class="tp-kbd">ESC</kbd> cerrar</span>
            <span class="tp-cmdk__spacer"></span>
            <span class="tp-cmdk__hint" id="tp-cmdk-status">—</span>
        </div>
    </div>
</div>
