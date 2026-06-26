{{-- Notifications tray (right drawer) --}}
<div class="tp-tray" id="tp-notif-tray" role="dialog" aria-label="Notificaciones" hidden>
    <div class="tp-tray__scrim" data-tray-close></div>
    <aside class="tp-tray__panel" role="document">
        <header class="tp-tray__head">
            <div>
                <div class="tp-h6">Notificaciones</div>
                <div class="tp-text-xs tp-text-muted">Eventos y alertas recientes</div>
            </div>
            <div class="tp-row">
                <button class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-notif-mark-all">Marcar leídas</button>
                <button class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-tray-close aria-label="Cerrar">
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
                <button class="tp-segmented__item" aria-selected="true" data-notif-filter="all">Todas</button>
                <button class="tp-segmented__item" data-notif-filter="critical">Críticas</button>
                <button class="tp-segmented__item" data-notif-filter="alerts">Alertas</button>
                <button class="tp-segmented__item" data-notif-filter="system">Sistema</button>
            </div>
        </div>

        <div class="tp-tray__body" id="tp-notif-list">
            <div class="tp-tray__empty">
                <div class="tp-text-muted">No hay notificaciones nuevas.</div>
            </div>
        </div>

        <footer class="tp-tray__foot">
            <a href="{{ url('/ui/v2/preview#alerts') }}" class="tp-btn tp-btn--ghost tp-btn--block">Ver todas las
                alertas ?</a>
        </footer>
    </aside>
</div>
