{{--
    Kebab de acciones (⋮ → Editar / Eliminar) para las tablas del modal
    device. Mismo markup/estilos que tpServices.kebabHtml (.tp-svc-kebab,
    services.css); el toggle lo maneja el delegado global de tp-services.js.
    Parámetros: $edit / $del (nombre del data-attr), $id, $extra (attrs html
    adicionales para el botón editar, opcional, ya escapados).
--}}
<div class="tp-svc-kebab">
    <button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="{{ trans('front.actions') }}">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <circle cx="12" cy="5" r="1.8" />
            <circle cx="12" cy="12" r="1.8" />
            <circle cx="12" cy="19" r="1.8" />
        </svg>
    </button>
    <div class="tp-svc-kebab__menu" role="menu" hidden>
        <button type="button" role="menuitem" {{ $edit }}="{{ $id }}" {!! $extra ?? '' !!}>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" />
            </svg>
            {{ trans('global.edit') }}
        </button>
        <button type="button" role="menuitem" class="is-danger" {{ $del }}="{{ $id }}">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
            </svg>
            {{ trans('global.delete') }}
        </button>
    </div>
</div>
