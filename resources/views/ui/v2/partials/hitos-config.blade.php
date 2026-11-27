{{-- Config de la capa de hitos kilométricos (tp-hitos.js) — se incluye en el
     layout: cualquier página con mapa puede crear su capa con tpHitos.create. --}}
<script type="application/json" id="tp-hitos-config">
{!! json_encode([
    'url'     => route('ui.v2.api.hitos'),
    'nearest' => route('ui.v2.api.hitos.nearest'),
], JSON_UNESCAPED_UNICODE) !!}
</script>
