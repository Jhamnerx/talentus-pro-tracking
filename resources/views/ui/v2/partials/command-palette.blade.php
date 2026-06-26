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
                placeholder="Buscar vehículo, conductor, geocerca, comando…" autocomplete="off" spellcheck="false" />
            <kbd class="tp-kbd">ESC</kbd>
        </div>

        <div class="tp-cmdk__body" id="tp-cmdk-body">
            <div class="tp-cmdk__group" data-group="recents">
                <div class="tp-cmdk__group-title">Recientes</div>
                <div class="tp-cmdk__empty">Sin elementos recientes.</div>
            </div>

            <div class="tp-cmdk__group" data-group="actions">
                <div class="tp-cmdk__group-title">Acciones rápidas</div>

                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/preview">
                    <span class="tp-cmdk__item-icon">??</span>
                    <span class="tp-cmdk__item-label">Ir a Live Ops</span>
                    <span class="tp-cmdk__item-meta">Navegación</span>
                </button>
                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/preview#dash">
                    <span class="tp-cmdk__item-icon">??</span>
                    <span class="tp-cmdk__item-label">Abrir Command Center</span>
                    <span class="tp-cmdk__item-meta">Navegación</span>
                </button>
                <button class="tp-cmdk__item" data-action="theme-toggle">
                    <span class="tp-cmdk__item-icon">??</span>
                    <span class="tp-cmdk__item-label">Cambiar tema (light/dark)</span>
                    <span class="tp-cmdk__item-meta">Preferencias</span>
                </button>
                <button class="tp-cmdk__item" data-action="density-toggle">
                    <span class="tp-cmdk__item-icon">??</span>
                    <span class="tp-cmdk__item-label">Cambiar densidad (compact/comfortable)</span>
                    <span class="tp-cmdk__item-meta">Preferencias</span>
                </button>
                <button class="tp-cmdk__item" data-action="go" data-target="/ui/v2/legacy">
                    <span class="tp-cmdk__item-icon">?</span>
                    <span class="tp-cmdk__item-label">Volver a UI clásica</span>
                    <span class="tp-cmdk__item-meta">Sistema</span>
                </button>
            </div>

            <div class="tp-cmdk__group" data-group="results" hidden>
                <div class="tp-cmdk__group-title" data-group-title>Resultados</div>
                <div data-results-list></div>
            </div>
        </div>

        <div class="tp-cmdk__foot">
            <span class="tp-cmdk__hint"><kbd class="tp-kbd">??</kbd> navegar</span>
            <span class="tp-cmdk__hint"><kbd class="tp-kbd">?</kbd> seleccionar</span>
            <span class="tp-cmdk__hint"><kbd class="tp-kbd">ESC</kbd> cerrar</span>
            <span class="tp-cmdk__spacer"></span>
            <span class="tp-cmdk__hint" id="tp-cmdk-status">—</span>
        </div>
    </div>
</div>
