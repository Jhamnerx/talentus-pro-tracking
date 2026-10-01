{{-- Puente de traducciones hacia el JS.

     El legacy hace lo mismo en Frontend/Layouts/partials/trans.blade.php. La
     v2 no lo tenia, y por eso todo el texto que el JS pinta acabo escrito a
     pelo en castellano dentro de las plantillas HTML.

     Se publican dos dominios: "ui" entero (cadenas propias de la v2) y una
     seleccion de "front", para poder REUSAR lo que el legacy ya tiene
     traducido a 40 idiomas en vez de duplicarlo. La lista es explicita: no
     se manda front.php entero porque son ~1.500 claves en cada pagina. --}}
@php
    // Claves del legacy que la v2 reusa desde JS. Lista explicita: mandar
    // front.php entero serian ~1.500 claves en cada pagina.
            $tpFront = [ 'add_schedule', 'add_service', 'address', 'all', 'altitude',
        'attempts', 'auto_send_when_online', 'change_password', 'close',
        'command', 'command_schedule', 'command_sent', 'country', 'daily',
        'date', 'day', 'delivery', 'deselect_all', 'device', 'devices',
        'distance', 'do_delete', 'drive', 'drive_end', 'drive_start',
        'driver', 'drivers', 'email_semicolon', 'end', 'engine',
        'engine_hours', 'event', 'events', 'exact_time', 'expiration_date',
        'export', 'export_active', 'export_inactive', 'export_single',
        'format', 'forwards', 'from', 'fuel_consumption', 'gprs',
        'gprs_templates', 'group', 'groups', 'hour', 'hour_short', 'hourly',
        'idle',
        'import', 'inactive', 'latitude', 'loading', 'location', 'logout',
        'logs', 'longitude', 'map', 'minute', 'minute_short', 'model', 'month', 'monthly',
        'move', 'move_duration', 'new', 'next', 'no_data', 'no_routes',
        'no_services', 'none', 'overspeed', 'playback', 'points', 'preview',
        'previous', 'protocol', 'queued', 'queued_for_online', 'report',
        'response', 'results', 'route', 'route_length', 'routes',
        'rows_per_page', 'schedule', 'schedule_at', 'search', 'second_short', 'select_all',
        'select_date', 'select_device', 'send', 'send_command', 'sent',
        'service', 'services', 'show_traffic', 'sms', 'sms_gateway_disabled',
        'sms_templates', 'speed', 'start', 'status', 'stop', 'stop_duration',
        'stopped', 'street_view', 'time', 'today', 'top_speed', 'trip_end',
        'trip_start', 'type', 'ungrouped', 'vehicle_state', 'vehicle_states',
        'week', 'yesterday',
    ];
            $tpGlobal = [ 'add', 'always', 'apply', 'cancel', 'clear', 'close',
        'confirm', 'delete', 'edit', 'failed', 'no', 'not_found', 'save',
        'to', 'user', 'yes',
    ];
    // OJO: aqui NO se cogen 'title' ni 'name'. Son etiquetas de campo de
    // formulario y en es dicen "Titulo" (sin tilde) y "Nombres" (plural),
    // que no valen como rotulo generico: esos dos van en ui.*.
            $tpAttr = [ 'active', 'adapted', 'buyer', 'current_engine_hours',
        'current_odometer', 'description', 'email', 'expiration_by', 'file',
        'filter', 'inactive', 'interval', 'last_name', 'last_service',
        'message', 'name', 'notes', 'password', 'phone',
        'renew_after_expiration', 'supplier', 'trigger_event_left', 'weekly',
    ];
@endphp
<script>
    window.tpLang = {
        ui:     @json(trans('ui')),
        front:  @json(\Illuminate\Support\Arr::only(trans('front'), $tpFront)),
        global: @json(\Illuminate\Support\Arr::only(trans('global'), $tpGlobal)),
        attr:   @json(\Illuminate\Support\Arr::only(trans('validation.attributes'), $tpAttr)),
    };

    // Mapa PLANO para las llamadas T(clave, "respaldo") que ya existian en
    // seis ficheros (live-routes, history, commands-page, tp-commands,
    // routes-table, tp-services). Esperaban window.tpI18n y nadie lo
    // rellenaba, asi que siempre devolvian el respaldo en castellano.
    // Se mezcla front -> global -> attr -> ui, con ui al final para que las
    // cadenas propias de la v2 manden si coincide el nombre corto.
    window.tpI18n = (function () {
        var plano = {};
        ['front', 'global', 'attr', 'ui'].forEach(function (dom) {
            var tabla = window.tpLang[dom] || {};
            Object.keys(tabla).forEach(function (k) { plano[k] = tabla[k]; });
        });
        return plano;
    })();

    /**
     * Traduce una clave. Sin punto se busca en "ui", que es lo habitual;
     * con punto se indica el dominio: front, global o attr.
     * tpT('front.address'), tpT('global.save'), tpT('attr.phone').
     *
     * Si no existe devuelve la propia clave, igual que trans() en PHP, para
     * que el fallo se vea en pantalla en vez de quedar en blanco.
     *
     *   tpT('vehicle_not_found')
     *   tpT('cp_found', { count: 2, name: 'ABC-123' })
     */
    window.tpT = function (clave, reemplazos) {
        var punto = clave.indexOf('.');
        var dom = punto === -1 ? 'ui' : clave.slice(0, punto);
        var k = punto === -1 ? clave : clave.slice(punto + 1);
        var tabla = window.tpLang && window.tpLang[dom];
        var txt = (tabla && tabla[k]) || clave;
        if (!reemplazos) return txt;
        // de mas largo a mas corto: si no, :name pisaria a :names
        Object.keys(reemplazos)
            .sort(function (a, b) { return b.length - a.length; })
            .forEach(function (k2) {
                txt = txt.split(':' + k2).join(reemplazos[k2]);
            });
        return txt;
    };
</script>
