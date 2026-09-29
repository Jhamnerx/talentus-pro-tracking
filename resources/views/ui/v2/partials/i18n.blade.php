{{-- Puente de traducciones hacia el JS.

     El legacy hace lo mismo en Frontend/Layouts/partials/trans.blade.php. La
     v2 no lo tenia, y por eso todo el texto que el JS pinta acabo escrito a
     pelo en castellano dentro de las plantillas HTML.

     Va en un <script> normal (sin defer) para que window.tpLang exista antes
     de que corra cualquiera de los ficheros de abajo, que si van con defer. --}}
<script>
    window.tpLang = @json(trans('ui'));

    /**
     * Traduce una clave del dominio "ui". Si no existe devuelve la propia
     * clave, igual que hace trans() en PHP, para que el fallo se vea.
     *
     *   tpT('vehicle_not_found')
     *   tpT('cp_found', { count: 2, name: 'ABC-123' })
     */
    window.tpT = function (clave, reemplazos) {
        var txt = (window.tpLang && window.tpLang[clave]) || clave;
        if (!reemplazos) return txt;
        // de mas largo a mas corto: si no, :name pisaria a :names
        Object.keys(reemplazos)
            .sort(function (a, b) { return b.length - a.length; })
            .forEach(function (k) {
                txt = txt.split(':' + k).join(reemplazos[k]);
            });
        return txt;
    };
</script>
