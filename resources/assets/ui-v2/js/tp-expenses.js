/* =====================================================
 * TalentusPro · tp-expenses — Gastos de flota (fleet-wide)
 * -----------------------------------------------------
 * Tabla + filtros (búsqueda, vehículo, rango de fechas) + modal CRUD.
 * Reutiliza el backend ExpensesController (DeviceExpense, total=qty*cost).
 * ===================================================== */
(function () {
  "use strict";
  var root = document.getElementById("tp-expenses-page");
  if (!root || !window.tpModal) return;

  var URLS = {
    data: root.getAttribute("data-url"),
    meta: root.getAttribute("data-meta-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
  };
  var state = { page: 1, s: "", device: "", from: "", to: "", devices: [], types: [], byId: {} };

  function $(id) { return document.getElementById(id); }
  function esc(s) { return tpModal.escapeHtml(s == null ? "" : String(s)); }
  function num(n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString(); }

  /* ---------- Meta (dropdowns) ---------- */
  tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
    if (!res.data || !res.data.ok) return;
    state.devices = res.data.devices || [];
    state.types = res.data.types || [];
    var sel = $("tp-exp-device");
    state.devices.forEach(function (d) {
      var o = document.createElement("option");
      o.value = d.id;
      o.textContent = d.name;
      sel.appendChild(o);
    });
    // Re-lee las opciones en el componente tp-select (se poblaron por AJAX).
    if (window.tpSelect && tpSelect.refresh) tpSelect.refresh(sel);
  });

  /* ---------- Tabla ---------- */
  function load() {
    var qs =
      "?page=" + state.page +
      "&s=" + encodeURIComponent(state.s) +
      "&device_id=" + encodeURIComponent(state.device) +
      "&date_from=" + encodeURIComponent(state.from) +
      "&date_to=" + encodeURIComponent(state.to);
    tpModal.fetchJSON(URLS.data + qs, { method: "GET" }).then(function (res) {
      var d = res.data || {};
      var items = d.items || [];
      state.byId = {};
      items.forEach(function (e) { state.byId[e.id] = e; });
      $("tp-exp-rows").innerHTML = items.length
        ? items.map(rowHtml).join("")
        : '<tr><td colspan="8" class="tp-svc-table__empty">' + tpT('ui.no_expenses') + '</td></tr>';
      $("tp-exp-range").textContent = tpT('ui.page_short') + (d.page || 1) + " / " + (d.pages || 1);
      $("tp-exp-total").textContent = (d.total || 0) + " gastos";
    });
  }

  function rowHtml(e) {
    return (
      "<tr>" +
      "<td>" + esc(e.device_name) + "</td>" +
      "<td>" + esc(e.name) + "</td>" +
      "<td>" + esc(e.type || "—") + "</td>" +
      '<td style="text-align:right;">' + num(e.quantity) + "</td>" +
      '<td style="text-align:right;">' + num(e.unit_cost) + "</td>" +
      '<td style="text-align:right;font-weight:600;">' + num(e.total) + "</td>" +
      "<td>" + esc((e.date || "").slice(0, 16)) + "</td>" +
      '<td class="tp-svc-table__actions" style="white-space:nowrap;text-align:right;">' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-exp-edit="' + e.id + '">' + tpT('global.edit') + '</button> ' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-exp-del="' + e.id + '">' + tpT('global.delete') + '</button></td></tr>'
    );
  }

  /* ---------- Modal ---------- */
  function field(label, input, full) {
    return '<div class="tp-field"' + (full ? ' style="grid-column:1/-1;"' : "") + '><label class="tp-field__label">' + label + "</label>" + input + "</div>";
  }

  function openForm(item) {
    var devOpts = '<option value="">' + tpT('ui.select_ellipsis') + '</option>' + state.devices.map(function (d) {
      return '<option value="' + d.id + '"' + (item && item.device_id === d.id ? " selected" : "") + ">" + esc(d.name) + "</option>";
    }).join("");
    var typeOpts = '<option value="">—</option>' + state.types.map(function (t) {
      return '<option value="' + t.id + '"' + (item && item.type_id === t.id ? " selected" : "") + ">" + esc(t.name) + "</option>";
    }).join("");
    // Fecha custom (tp-datepicker = solo date) + Hora aparte para conservar la hora.
    var dPart = item && item.date ? item.date.slice(0, 10) : "";
    var tPart = item && item.date ? item.date.slice(11, 16) : "";

    var body =
      '<div class="tp-scope" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
      field(tpT('ui.vehicle_required'), '<select class="tp-input" data-x-device style="width:100%;">' + devOpts + "</select>") +
      field(tpT('ui.name_required_field'), '<input type="text" class="tp-input" data-x-name value="' + (item ? esc(item.name) : "") + '" style="width:100%;">') +
      field(tpT('ui.date_required_field'), '<input type="date" class="tp-input" data-x-date value="' + dPart + '" style="width:100%;">') +
      field(tpT('front.hour'), '<input type="time" class="tp-input" data-x-time value="' + tPart + '" style="width:100%;">') +
      field(tpT('front.type'), '<select class="tp-input" data-x-type style="width:100%;">' + typeOpts + "</select>") +
      field("Cantidad *", '<input type="number" step="any" class="tp-input" data-x-qty value="' + (item ? esc(item.quantity) : "") + '" style="width:100%;">') +
      field("Costo unitario *", '<input type="number" step="any" class="tp-input" data-x-cost value="' + (item ? esc(item.unit_cost) : "") + '" style="width:100%;">') +
      field("Proveedor", '<input type="text" class="tp-input" data-x-supplier value="' + (item ? esc(item.supplier) : "") + '" style="width:100%;">') +
      field("Comprador", '<input type="text" class="tp-input" data-x-buyer value="' + (item ? esc(item.buyer) : "") + '" style="width:100%;">') +
      field("Notas", '<textarea class="tp-input" data-x-notes rows="2" style="width:100%;">' + (item ? esc(item.additional) : "") + "</textarea>", true) +
      '<div data-x-err style="grid-column:1/-1;display:none;color:#ef4444;font-size:13px;"></div></div>';

    tpModal.open({
      title: item ? tpT('ui.edit_expense') : tpT('ui.new_expense'),
      size: "md",
      body: body,
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        { label: tpT('global.save'), variant: "primary", close: false, onClick: function (d) { save(d, item); return false; } },
      ],
    });
  }

  function save(d, item) {
    var err = d.querySelector("[data-x-err]");
    function showErr(m) { if (err) { err.textContent = m; err.style.display = "block"; } }
    if (err) err.style.display = "none";
    function v(sel) { var el = d.querySelector(sel); return el ? el.value : ""; }
    // tpDate.value: el input visible muestra dd/mm/aaaa, el ISO va aparte
    var dateV = window.tpDate ? tpDate.value(d.querySelector("[data-x-date]")) : v("[data-x-date]");
    var timeV = v("[data-x-time]") || "00:00";
    var payload = {
      device_id: v("[data-x-device]"),
      name: v("[data-x-name]").trim(),
      date: dateV ? dateV + "T" + timeV : "",
      type_id: v("[data-x-type]"),
      quantity: v("[data-x-qty]"),
      unit_cost: v("[data-x-cost]"),
      supplier: v("[data-x-supplier]"),
      buyer: v("[data-x-buyer]"),
      additional: v("[data-x-notes]"),
    };
    if (!payload.device_id) return showErr(tpT('ui.select_a_vehicle_dot'));
    if (!payload.name) return showErr(tpT('ui.name_required'));
    if (!payload.date) return showErr(tpT('ui.date_is_required'));
    if (payload.quantity === "" || payload.unit_cost === "") return showErr(tpT('ui.qty_and_unit_cost_required'));

    tpModal.setBusy(d, true);
    var url = item ? URLS.item.replace("{id}", item.id) : URLS.store;
    tpModal
      .fetchJSON(url, { method: item ? "PUT" : "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(d, false);
        if (res.status === 422) return showErr(tpT('ui.check_required_fields'));
        if (!res.ok || !res.data || !res.data.ok) return showErr((res.data && res.data.message) || tpT('ui.save_failed'));
        tpModal.close(d);
        load();
      })
      .catch(function () {
        tpModal.setBusy(d, false);
        showErr(tpT('ui.network_error'));
      });
  }

  function del(id) {
    tpModal
      .confirm({ title: tpT('ui.delete_expense'), message: tpT('ui.confirm_delete_expense'), confirmLabel: tpT('global.delete'), danger: true })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON(URLS.item.replace("{id}", id), { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) load();
          else tpModal.toast(tpT('ui.delete_failed'), "error");
        });
      });
  }

  /* ---------- Wiring ---------- */
  var searchTimer = null;
  $("tp-exp-search").addEventListener("input", function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { state.s = $("tp-exp-search").value.trim(); state.page = 1; load(); }, 300);
  });
  $("tp-exp-device").addEventListener("change", function () { state.device = this.value; state.page = 1; load(); });
  $("tp-exp-from").addEventListener("change", function () { state.from = this.value; state.page = 1; load(); });
  $("tp-exp-to").addEventListener("change", function () { state.to = this.value; state.page = 1; load(); });
  $("tp-exp-add").addEventListener("click", function () { openForm(null); });
  $("tp-exp-prev").addEventListener("click", function () { if (state.page > 1) { state.page--; load(); } });
  $("tp-exp-next").addEventListener("click", function () { state.page++; load(); });
  $("tp-exp-rows").addEventListener("click", function (e) {
    var ed = e.target.closest("[data-exp-edit]");
    if (ed) return openForm(state.byId[ed.getAttribute("data-exp-edit")]);
    var d = e.target.closest("[data-exp-del]");
    if (d) return del(d.getAttribute("data-exp-del"));
  });

  load();
})();
