{{-- Boton "Explicar con el asistente" en reportes HTML abiertos en el navegador.
     No se incluye en archivos descargados ni en correos (ver Reports.partials.layout). --}}
<style>
    #tpai-explain-btn{position:fixed;right:20px;bottom:20px;z-index:1000;display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border:0;border-radius:22px;background:#1f6feb;color:#fff;font:600 14px/1 system-ui,sans-serif;box-shadow:0 8px 24px rgba(15,39,66,.25);cursor:pointer}
    #tpai-explain-btn:disabled{opacity:.6;cursor:default}
    #tpai-explain-box{position:fixed;right:20px;bottom:76px;z-index:1000;width:420px;max-width:calc(100vw - 40px);max-height:70vh;overflow:auto;padding:16px 18px;border:1px solid #dfe3ea;border-radius:14px;background:#fff;color:#1d2433;font:14px/1.55 system-ui,sans-serif;box-shadow:0 12px 32px rgba(15,39,66,.2)}
    #tpai-explain-box[hidden]{display:none}
    #tpai-explain-box h4{margin:0 0 8px;font-size:15px}
    #tpai-explain-box ul{margin:6px 0;padding-left:20px}
    #tpai-explain-box p{margin:0 0 8px}
    #tpai-explain-box .x{float:right;border:0;background:none;font-size:18px;cursor:pointer;color:#6b7280}
    @media print{#tpai-explain-btn,#tpai-explain-box{display:none!important}}
</style>
<button type="button" id="tpai-explain-btn">&#10024; Explicar con {{ \App\Services\Ai\AiSettings::name() }}</button>
<div id="tpai-explain-box" hidden aria-live="polite">
    <button type="button" class="x" aria-label="Cerrar" onclick="this.parentNode.hidden=true">&times;</button>
    <h4>{{ \App\Services\Ai\AiSettings::name() }}</h4>
    <div id="tpai-explain-text"></div>
</div>
<script>
(function () {
    var btn = document.getElementById('tpai-explain-btn');
    var box = document.getElementById('tpai-explain-box');
    var out = document.getElementById('tpai-explain-text');

    function esc(s) { return s.replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function md(text) {
        var html = [], list = [];
        text.split('\n').forEach(function (line) {
            var l = esc(line.trim()).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            if (/^([-*•]|\d+[.)])\s+/.test(line.trim())) { list.push('<li>' + l.replace(/^([-*•]|\d+[.)])\s+/, '') + '</li>'); return; }
            if (list.length) { html.push('<ul>' + list.join('') + '</ul>'); list = []; }
            if (l) html.push('<p>' + l + '</p>');
        });
        if (list.length) html.push('<ul>' + list.join('') + '</ul>');
        return html.join('');
    }

    btn.addEventListener('click', function () {
        var content = document.querySelector('.rep-body, main, .container');
        btn.disabled = true;
        box.hidden = false;
        out.innerHTML = '<p>Analizando el reporte...</p>';

        fetch(@json(route('ai.explain_report')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({title: @json($report->title()), text: (content ? content.innerText : document.body.innerText)})
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                out.innerHTML = r.ok ? md(data.text || '') : '<p>' + esc(data.message || 'No se pudo analizar el reporte.') + '</p>';
            });
        }).catch(function () {
            out.innerHTML = '<p>No se pudo analizar el reporte.</p>';
        }).then(function () { btn.disabled = false; });
    });
})();
</script>
