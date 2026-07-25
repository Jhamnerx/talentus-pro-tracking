/* =====================================================
 * TalentusPro · tp-call-actions — Acciones de llamada
 * -----------------------------------------------------
 * Página /ui/v2/call-actions: tabla (búsqueda notas + filtros vehículo/
 * respuesta + paginación + kebab Editar/Eliminar) y modal crear/editar
 * (Vehículo → carga sus eventos recientes → Evento, Tipo de respuesta,
 * Fecha de llamada, Notas). CRUD sobre CallAction legacy; el alert_id se
 * deriva del evento y called_at se convierte a la zona almacenada en el
 * backend. Depende de tpModal; kebab = tp-services.js.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-callactions-page");
  if (!root) return;

  var URLS = {
    list: root.getAttribute("data-list-url"),
    meta: root.getAttribute("data-meta-url"),
    events: root.getAttribute("data-events-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";
  var META = null;

  function esc(s) { return tpModal.escapeHtml(s); }
  function options(list, sel, ph) {
    return (ph ? '<option value="">' + esc(ph) + "</option>" : "") +
      (list || []).map(function (o) {
        return '<option value="' + esc(o.id) + '"' + (String(sel) === String(o.id) ? " selected" : "") + ">" + esc(o.label || o.name) + "</option>";
      }).join("");
  }
  function nowLocalInput() {
    var d = new Date();
    function p(n) { return ("0" + n).slice(-2); }
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes());
  }

  /* ================= Modal ================= */

  function openForm(id, onSaved) {
    var isEdit = !!id;
    var dlg = tpModal.open({
      title: isEdit ? "Editar acción de llamada" : "Nueva acción de llamada",
      size: "md",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        { label: "Guardar", variant: "primary", close: false, onClick: function (d) { submit(d, id, onSaved); return false; } },
      ],
    });

    tpModal.fetchJSON(URLS.meta + (isEdit ? "?id=" + id : ""), { method: "GET" }).then(function (res) {
      var body = dlg.querySelector(".tp-modal__body");
      if (!res.ok || !res.data || !res.data.ok) { body.innerHTML = '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar.</div>'; return; }
      var m = res.data, item = m.item || {};
      body.innerHTML =
        '<form class="tp-form tp-ca-form"><div class="tp-form__grid">' +
        '<div class="tp-form__field tp-form__field--required"><span class="tp-form__label">Vehículo</span>' +
        '<select class="tp-input" name="device_id"' + (isEdit ? " disabled" : "") + ">" + options(m.devices, item.device_id, "Seleccionar vehículo…") + "</select></div>" +
        '<div class="tp-form__field tp-form__field--required"><span class="tp-form__label">Evento</span>' +
        '<select class="tp-input" name="event_id">' + options(item.events || [], item.event_id, "Selecciona un vehículo primero…") + "</select>" +
        '<small class="tp-form__hint">Solo eventos con alerta pueden registrarse.</small></div>' +
        '<div class="tp-form__field tp-form__field--required"><span class="tp-form__label">Tipo de respuesta</span>' +
        '<select class="tp-input" name="response_type">' + options(m.responseTypes, item.response_type) + "</select></div>" +
        '<label class="tp-form__field tp-form__field--required"><span class="tp-form__label">Fecha de llamada</span>' +
        '<input type="datetime-local" name="called_at" value="' + esc(toLocal(item.called_at) || nowLocalInput()) + '"></label>' +
        '<label class="tp-form__field tp-form__field--required"><span class="tp-form__label">Notas</span>' +
        '<textarea name="remarks" rows="2" data-tp-field>' + esc(item.remarks || "") + "</textarea></label>" +
        "</div></form>";

      // Al cambiar vehículo, cargar sus eventos recientes
      var devSel = dlg.querySelector('[name="device_id"]');
      devSel.addEventListener("change", function () {
        var evSel = dlg.querySelector('[name="event_id"]');
        evSel.innerHTML = '<option value="">Cargando…</option>';
        if (window.tpSelect && tpSelect.refresh) try { tpSelect.refresh(evSel); } catch (e) {}
        tpModal.fetchJSON(URLS.events + "?device_id=" + devSel.value, { method: "GET" }).then(function (r) {
          var evs = r.ok && r.data && r.data.ok ? r.data.events : [];
          evSel.innerHTML = options(evs, "", evs.length ? "Seleccionar evento…" : "Sin eventos recientes");
          if (window.tpSelect && tpSelect.refresh) try { tpSelect.refresh(evSel); } catch (e) {}
        });
      });
    });
  }

  // "YYYY-MM-DD HH:MM..." (humano/convertido) → value de datetime-local
  function toLocal(s) {
    if (!s) return "";
    var m = String(s).match(/(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    return m ? m[1] + "T" + m[2] : "";
  }

  function submit(dlg, id, onSaved) {
    var form = dlg.querySelector("form.tp-ca-form");
    var payload = {
      device_id: form.querySelector('[name="device_id"]').value,
      event_id: form.querySelector('[name="event_id"]').value,
      response_type: form.querySelector('[name="response_type"]').value,
      called_at: (form.querySelector('[name="called_at"]').value || "").replace("T", " "),
      remarks: form.querySelector('[name="remarks"]').value.trim(),
    };
    if (!payload.event_id) { tpModal.toast("Selecciona un evento.", "warning"); return; }
    if (!payload.remarks) { tpModal.toast("Las notas son obligatorias.", "warning"); return; }

    var url = id ? URLS.item.replace("{id}", id) : URLS.store;
    tpModal.setBusy(dlg, true);
    tpModal.fetchJSON(url, { method: id ? "PUT" : "POST", json: payload }).then(function (res) {
      tpModal.setBusy(dlg, false);
      if (res.status === 422) {
        var errors = (res.data && res.data.errors) || {};
        var first = Object.keys(errors)[0];
        tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
        return;
      }
      if (!res.ok || !res.data || !res.data.ok) { tpModal.toast("No se pudo guardar.", "error"); return; }
      tpModal.toast(id ? "Actualizado." : "Registrado.", "success");
      tpModal.close(dlg);
      if (onSaved) onSaved();
    }).catch(function () { tpModal.setBusy(dlg, false); tpModal.toast("Error de red.", "error"); });
  }

  /* ================= Página ================= */

  var rowsEl = document.getElementById("tp-ca-rows");
  var searchEl = document.getElementById("tp-ca-search");
  var deviceSel = document.getElementById("tp-ca-device");
  var respSel = document.getElementById("tp-ca-response");
  var limitSel = document.getElementById("tp-ca-limit");
  var rangeEl = document.getElementById("tp-ca-range");
  var prevBtn = document.getElementById("tp-ca-prev");
  var nextBtn = document.getElementById("tp-ca-next");
  var addBtn = document.getElementById("tp-ca-add");

  var state = { page: 1, per: 20, s: "", device_id: "", response_type: "", last: 1, total: 0 };
  var timer = null;

  function kebab(r) {
    var items = "";
    if (canEdit) items += '<button type="button" role="menuitem" data-ca-edit="' + r.id + '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> Editar</button>';
    if (canRemove) items += '<button type="button" role="menuitem" class="is-danger" data-ca-del="' + r.id + '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> Eliminar</button>';
    if (!items) return "";
    return '<div class="tp-svc-kebab"><button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></button><div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>";
  }

  var RESP_STYLE = { answer: "background:#dcfce7;color:#15803d;", no_answer: "background:#fee2e2;color:#b91c1c;", no_response: "background:#f1f5f9;color:#475569;" };
  function respChip(r) {
    return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;' + (RESP_STYLE[r.response_type] || RESP_STYLE.no_response) + '">' + esc(r.response) + "</span>";
  }

  function load() {
    var q = URLS.list + "?s=" + encodeURIComponent(state.s) + "&device_id=" + encodeURIComponent(state.device_id) +
      "&response_type=" + encodeURIComponent(state.response_type) + "&page=" + state.page + "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) { rowsEl.innerHTML = '<tr><td colspan="7" class="tp-svc-table__empty">No se pudo cargar.</td></tr>'; return; }
      state.last = res.data.last || 1; state.total = res.data.total || 0;
      var items = res.data.items || [];
      rowsEl.innerHTML = items.length ? items.map(function (r) {
        return "<tr><td>" + esc(r.device || "–") + "</td>" +
          '<td title="' + esc(r.event) + '" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(r.event || "–") + "</td>" +
          "<td>" + respChip(r) + "</td><td>" + esc(r.called_at || "–") + "</td>" +
          '<td title="' + esc(r.remarks) + '" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(r.remarks || "–") + "</td>" +
          "<td>" + esc(r.user || "–") + "</td>" +
          '<td class="tp-svc-table__actions">' + kebab(r) + "</td></tr>";
      }).join("") : '<tr><td colspan="7" class="tp-svc-table__empty">Sin datos</td></tr>';
      var from = state.total ? (state.page - 1) * state.per + 1 : 0, to = Math.min(state.page * state.per, state.total);
      rangeEl.textContent = from + "–" + to + " / " + state.total;
      prevBtn.disabled = state.page <= 1; nextBtn.disabled = state.page >= state.last;
    });
  }

  searchEl.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(function () { state.s = searchEl.value.trim(); state.page = 1; load(); }, 300); });
  deviceSel.addEventListener("change", function () { state.device_id = deviceSel.value; state.page = 1; load(); });
  respSel.addEventListener("change", function () { state.response_type = respSel.value; state.page = 1; load(); });
  limitSel.addEventListener("change", function () { state.per = parseInt(limitSel.value, 10) || 20; state.page = 1; load(); });
  prevBtn.addEventListener("click", function () { if (state.page > 1) { state.page--; load(); } });
  nextBtn.addEventListener("click", function () { if (state.page < state.last) { state.page++; load(); } });
  addBtn.addEventListener("click", function () { openForm(null, load); });

  rowsEl.addEventListener("click", function (e) {
    var ed = e.target.closest("[data-ca-edit]");
    if (ed) { openForm(ed.getAttribute("data-ca-edit"), load); return; }
    var del = e.target.closest("[data-ca-del]");
    if (del) {
      tpModal.confirm({ title: "Eliminar", message: "¿Eliminar esta acción de llamada?", danger: true, confirmLabel: "Eliminar" }).then(function (go) {
        if (!go) return;
        tpModal.fetchJSON(URLS.item.replace("{id}", del.getAttribute("data-ca-del")), { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) { tpModal.toast("Eliminado.", "success"); load(); }
          else tpModal.toast("No se pudo eliminar.", "error");
        });
      });
    }
  });

  // Poblar filtros de vehículo + respuesta desde meta
  tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
    if (!res.ok || !res.data || !res.data.ok) return;
    META = res.data;
    deviceSel.innerHTML = '<option value="">Todos los vehículos</option>' + options(META.devices, "");
    respSel.innerHTML = '<option value="">Toda respuesta</option>' + options(META.responseTypes, "");
    ["tp-ca-device", "tp-ca-response"].forEach(function (idc) {
      var el = document.getElementById(idc);
      if (window.tpSelect && tpSelect.refresh) try { tpSelect.refresh(el); } catch (e) {}
    });
  });

  load();
})();
