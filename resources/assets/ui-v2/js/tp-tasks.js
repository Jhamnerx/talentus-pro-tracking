/* =====================================================
 * TalentusPro · tp-tasks — Tareas (pickup / delivery)
 * -----------------------------------------------------
 * Página /ui/v2/tasks: tabla (búsqueda título/factura + filtros
 * vehículo/estado + paginación + kebab Editar/Firma/Eliminar) y modal
 * crear/editar estilo referencia: Vehículo, Título, N° factura,
 * Prioridad, (edición: Estado; Conjunto de tareas si hay permiso),
 * secciones Recogida y Entrega (dirección con picker de mapa +
 * rango Desde/Hasta con fecha + hora en pasos de 5 min) y Comentario.
 *
 * El backend delega en TaskUserService legacy — el validador exige
 * dirección + lat/lng + rango de tiempos en AMBAS secciones, por eso
 * la dirección se fija con el modal "Dirección" (buscador vía
 * address/search, click/drag en el mapa con address/reverse, Aplicar
 * copia address+lat+lng al formulario). Los tiempos viajan como
 * "YYYY-MM-DD HH:MM:00" (mismo formato que el legacy).
 * Depende de tpModal; kebab (.tp-svc-kebab) = toggle de tp-services.js;
 * Leaflet lo carga la página (unpkg).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-tasks-page");
  if (!root) return;

  var URLS = {
    list: root.getAttribute("data-list-url"),
    meta: root.getAttribute("data-meta-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
    adrSearch: root.getAttribute("data-address-search-url"),
    adrReverse: root.getAttribute("data-address-reverse-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";

  var DEFAULT_CENTER = [-12.0464, -77.0428]; // Lima

  function esc(s) {
    return tpModal.escapeHtml(s);
  }
  function p2(n) {
    return ("0" + n).slice(-2);
  }
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + "-" + p2(d.getMonth() + 1) + "-" + p2(d.getDate());
  }

  function timeOptionsHtml(selected) {
    var opts = [];
    for (var m = 0; m < 1440; m += 5) opts.push(p2(Math.floor(m / 60)) + ":" + p2(m % 60));
    opts.push("23:59");
    if (selected && opts.indexOf(selected) === -1) {
      opts.push(selected);
      opts.sort();
    }
    return opts
      .map(function (o) {
        return '<option value="' + o + '"' + (o === selected ? " selected" : "") + ">" + o + "</option>";
      })
      .join("");
  }

  // "YYYY-MM-DD HH:MM[:SS]" → { date, time } (con defaults del legacy)
  function dtParts(v, defTime) {
    var m = String(v || "").match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    if (m) return { date: m[1], time: m[2] };
    return { date: todayISO(), time: defTime };
  }

  /* ================= Form ================= */

  var PIN_SVG =
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';

  function selectHtml(name, options, selectedId, placeholder) {
    return (
      '<select class="tp-input" name="' + name + '" style="width:100%;">' +
      (placeholder ? '<option value="">' + esc(placeholder) + "</option>" : "") +
      (options || [])
        .map(function (o) {
          return (
            '<option value="' + o.id + '"' + (String(selectedId) === String(o.id) ? " selected" : "") + ">" +
            esc(o.title || o.name || "") + "</option>"
          );
        })
        .join("") +
      "</select>"
    );
  }

  function dtFieldHtml(key, label, value, defTime) {
    var p = dtParts(value, defTime);
    return (
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">' + label + "</span>" +
      '<div style="display:flex;gap:8px;">' +
      '<input type="date" name="' + key + '_date" value="' + p.date + '" style="flex:1;min-width:0;">' +
      '<select class="tp-input" name="' + key + '_time" style="width:92px;flex:0 0 92px;">' + timeOptionsHtml(p.time) + "</select>" +
      "</div></div>"
    );
  }

  function addressFieldHtml(kind, label, item) {
    var lat = item[kind + "_address_lat"];
    var lng = item[kind + "_address_lng"];
    return (
      '<div class="tp-form__field tp-form__field--required tp-col-2">' +
      '<span class="tp-form__label">' + label + "</span>" +
      '<div class="tp-input-group">' +
      '<input type="text" name="' + kind + '_address" maxlength="255" value="' + esc(item[kind + "_address"] || "") +
      '" placeholder="Buscar dirección…" autocomplete="off" data-tp-field>' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm" data-tsk-map="' + kind +
      '" title="Elegir en el mapa">' + PIN_SVG + "</button>" +
      "</div>" +
      '<input type="hidden" name="' + kind + '_address_lat" value="' + (lat != null ? lat : "") + '">' +
      '<input type="hidden" name="' + kind + '_address_lng" value="' + (lng != null ? lng : "") + '">' +
      "</div>"
    );
  }

  function sectionTitleHtml(t) {
    return (
      '<div class="tp-col-2" style="margin-top:4px;padding-bottom:6px;border-bottom:1px solid var(--border-default,#e9eef5);' +
      'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--fg-muted,#94a3b8);">' +
      t + "</div>"
    );
  }

  // Cambio automático de estado por posición (plugin opcional del legacy)
  function acBlockHtml(kind, item, meta) {
    if (!meta.ac_enabled) return "";
    var vals = meta.ac_values || {};
    var pick = function (field, def) {
      var v = item[kind + "_ac_" + field];
      if (v != null && v !== "") return v;
      return vals[kind + "_" + field] != null ? vals[kind + "_" + field] : def;
    };
    return (
      '<div class="tp-form__field tp-col-2">' +
      '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:6px;">' +
      '<input type="checkbox" class="tp-choice-input" name="' + kind + '_ac" value="1"' +
      (item[kind + "_ac"] ? " checked" : "") + "> Cambio automático de estado</label>" +
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">' +
      '<label class="tp-form__field"><span class="tp-form__label">Radio (m)</span>' +
      '<input type="text" name="' + kind + '_ac_radius" value="' + esc(String(pick("radius", ""))) + '" data-tp-field></label>' +
      '<label class="tp-form__field"><span class="tp-form__label">Duración (s)</span>' +
      '<input type="text" name="' + kind + '_ac_duration" value="' + esc(String(pick("duration", ""))) + '" data-tp-field></label>' +
      '<div class="tp-form__field"><span class="tp-form__label">Estado</span>' +
      selectHtml(kind + "_ac_status", meta.statuses || [], pick("status", "")) + "</div>" +
      "</div></div>"
    );
  }

  function buildFormHtml(meta, isEdit) {
    var item = meta.item || {};

    var extra = "";
    if (isEdit) {
      extra +=
        '<div class="tp-form__field">' +
        '<span class="tp-form__label">Estado</span>' +
        selectHtml("status", meta.statuses || [], item.status) +
        "</div>";
    }
    if (meta.task_sets) {
      extra +=
        '<div class="tp-form__field' + (isEdit ? "" : " tp-col-2") + '">' +
        '<span class="tp-form__label">Conjunto de tareas</span>' +
        selectHtml("task_set_id", meta.task_sets, item.task_set_id, "—") +
        "</div>";
    } else if (isEdit) {
      extra += '<div class="tp-form__field"></div>';
    }

    return (
      '<form class="tp-form tp-task-form">' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      // Vehículo
      '<div class="tp-form__field tp-form__field--required tp-col-2">' +
      '<span class="tp-form__label">Vehículo</span>' +
      selectHtml(
        "device_id",
        (meta.devices || []).map(function (d) {
          return { id: d.id, title: d.name };
        }),
        item.device_id,
        "Seleccionar vehículo…",
      ) +
      "</div>" +
      // Título
      '<label class="tp-form__field tp-form__field--required tp-col-2">' +
      '<span class="tp-form__label">Título</span>' +
      '<input type="text" name="title" maxlength="255" value="' + esc(item.title || "") +
      '" placeholder="Título de la tarea…" data-tp-field>' +
      "</label>" +
      // N° factura | Prioridad
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">N° de factura</span>' +
      '<input type="text" name="invoice_number" maxlength="100" value="' + esc(item.invoice_number || "") + '" data-tp-field>' +
      "</label>" +
      '<div class="tp-form__field">' +
      '<span class="tp-form__label">Prioridad</span>' +
      selectHtml("priority", meta.priorities || [], item.priority != null ? item.priority : 1) +
      "</div>" +
      extra +
      sectionTitleHtml("Recogida") +
      addressFieldHtml("pickup", "Dirección de recogida", item) +
      dtFieldHtml("pickup_from", "Desde", item.pickup_time_from, "08:00") +
      dtFieldHtml("pickup_to", "Hasta", item.pickup_time_to, "12:00") +
      acBlockHtml("pickup", item, meta) +
      sectionTitleHtml("Entrega") +
      addressFieldHtml("delivery", "Dirección de entrega", item) +
      dtFieldHtml("delivery_from", "Desde", item.delivery_time_from, "12:00") +
      dtFieldHtml("delivery_to", "Hasta", item.delivery_time_to, "17:00") +
      acBlockHtml("delivery", item, meta) +
      // Comentario
      '<label class="tp-form__field tp-col-2">' +
      '<span class="tp-form__label">Comentario</span>' +
      '<textarea name="comment" rows="2" maxlength="1000" data-tp-field>' + esc(item.comment || "") + "</textarea>" +
      "</label>" +
      "</div></form>"
    );
  }

  function openForm(id, onSaved) {
    var isEdit = !!id;

    var dlg = tpModal.open({
      title: isEdit ? "Editar tarea" : "Nueva tarea",
      size: "lg",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            submitForm(dialog, id, onSaved);
            return false;
          },
        },
      ],
    });

    tpModal.fetchJSON(URLS.meta + (isEdit ? "?id=" + id : ""), { method: "GET" }).then(function (res) {
      var bodyEl = dlg.querySelector(".tp-modal__body");
      if (!bodyEl) return;
      if (!res.ok || !res.data || !res.data.ok) {
        bodyEl.innerHTML =
          '<div class="tp-text-center tp-text-error" style="padding:24px;">No se pudo cargar el formulario.</div>';
        return;
      }
      bodyEl.innerHTML = buildFormHtml(res.data, isEdit);
      var form = dlg.querySelector("form.tp-task-form");
      form._tskMeta = res.data;
      form.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-tsk-map]");
        if (btn) openAddressPicker(form, btn.getAttribute("data-tsk-map"));
      });
    });
  }

  /* ================= Picker de dirección (mapa) ================= */

  function openAddressPicker(form, kind) {
    var addrInput = form.querySelector('input[name="' + kind + '_address"]');
    var latInput = form.querySelector('input[name="' + kind + '_address_lat"]');
    var lngInput = form.querySelector('input[name="' + kind + '_address_lng"]');

    var lat = parseFloat(latInput.value);
    var lng = parseFloat(lngInput.value);
    var has = isFinite(lat) && isFinite(lng);
    var dirty = has;
    var map = null;
    var marker = null;

    var dlg = tpModal.open({
      title: "Dirección",
      size: "lg",
      static: true,
      body:
        '<div class="tp-form">' +
        '<div class="tp-form__field" style="margin-bottom:10px;">' +
        '<input type="text" data-adr-q maxlength="255" placeholder="Buscar dirección… (Enter)" autocomplete="off" value="' +
        esc(addrInput.value || "") + '" data-tp-field>' +
        "</div>" +
        '<div data-adr-map style="height:360px;border-radius:12px;overflow:hidden;border:1px solid var(--border-default,#e2e8f0);"></div>' +
        '<small class="tp-form__hint" style="display:block;margin-top:8px;">Busca una dirección o haz click en el mapa para ubicar el punto; luego pulsa Aplicar.</small>' +
        "</div>",
      buttons: [
        { label: "Cerrar", variant: "ghost", close: true },
        {
          label: "Aplicar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            if (!dirty || !marker) {
              tpModal.toast("Ubica el punto en el mapa antes de aplicar.", "warning");
              return false;
            }
            var pos = marker.getLatLng();
            var q = dialog.querySelector("[data-adr-q]").value.trim();
            if (q) addrInput.value = q;
            latInput.value = pos.lat.toFixed(6);
            lngInput.value = pos.lng.toFixed(6);
            tpModal.close(dialog);
            return false;
          },
        },
      ],
    });

    var qInput = dlg.querySelector("[data-adr-q]");
    var mapEl = dlg.querySelector("[data-adr-map]");

    function search(q) {
      tpModal.fetchJSON(URLS.adrSearch + "?q=" + encodeURIComponent(q), { method: "GET" }).then(function (res) {
        var loc = res.data && res.data.status == 1 ? res.data.location : null;
        if (!loc || loc.lat == null) {
          tpModal.toast((res.data && res.data.error) || "No se encontró la dirección.", "warning");
          return;
        }
        dirty = true;
        var ll = [parseFloat(loc.lat), parseFloat(loc.lng)];
        marker.setLatLng(ll);
        map.setView(ll, 16);
        if (loc.address) qInput.value = loc.address;
      });
    }

    function reverse(ll) {
      tpModal.fetchJSON(URLS.adrReverse + "?lat=" + ll.lat + "&lng=" + ll.lng, { method: "GET" }).then(function (res) {
        var loc = res.data && res.data.status == 1 ? res.data.location : null;
        if (loc && loc.address) qInput.value = loc.address;
      });
    }

    if (!window.L) {
      mapEl.innerHTML = '<div class="tp-text-center tp-text-muted" style="padding:24px;">No se pudo cargar el mapa.</div>';
    } else {
      setTimeout(function () {
        map = L.map(mapEl).setView(has ? [lat, lng] : DEFAULT_CENTER, has ? 16 : 11);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
          maxZoom: 19,
          attribution: "&copy; OpenStreetMap",
        }).addTo(map);
        marker = L.marker(has ? [lat, lng] : map.getCenter(), { draggable: true }).addTo(map);

        marker.on("dragend", function () {
          dirty = true;
          reverse(marker.getLatLng());
        });
        map.on("click", function (e) {
          dirty = true;
          marker.setLatLng(e.latlng);
          reverse(e.latlng);
        });

        setTimeout(function () {
          map.invalidateSize();
        }, 150);

        // Dirección escrita a mano sin coordenadas → geocodificar de entrada
        if (!has && qInput.value.trim()) search(qInput.value.trim());
      }, 60);
    }

    qInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        if (qInput.value.trim() && marker) search(qInput.value.trim());
      }
    });

    dlg.addEventListener("close", function () {
      if (map) {
        try {
          map.remove();
        } catch (err) {}
      }
    });
  }

  /* ================= Submit ================= */

  function val(form, name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? el.value : "";
  }

  function dt(form, key) {
    var d = val(form, key + "_date");
    var t = val(form, key + "_time") || "00:00";
    return d ? d + " " + t + ":00" : "";
  }

  function submitForm(dialog, id, onSaved) {
    var form = dialog.querySelector("form.tp-task-form");
    if (!form) return;
    var meta = form._tskMeta || {};

    // El validador exige lat/lng: obligar a fijar la dirección con el mapa
    var kinds = ["pickup", "delivery"];
    for (var i = 0; i < kinds.length; i++) {
      if (val(form, kinds[i] + "_address") && !val(form, kinds[i] + "_address_lat")) {
        tpModal.toast(
          "Ubica la dirección de " + (kinds[i] === "pickup" ? "recogida" : "entrega") + " con el botón del mapa.",
          "warning",
        );
        return;
      }
    }

    var payload = {
      device_id: val(form, "device_id"),
      title: val(form, "title").trim(),
      invoice_number: val(form, "invoice_number").trim(),
      priority: val(form, "priority"),
      comment: val(form, "comment").trim(),
      pickup_address: val(form, "pickup_address").trim(),
      pickup_address_lat: val(form, "pickup_address_lat"),
      pickup_address_lng: val(form, "pickup_address_lng"),
      pickup_time_from: dt(form, "pickup_from"),
      pickup_time_to: dt(form, "pickup_to"),
      delivery_address: val(form, "delivery_address").trim(),
      delivery_address_lat: val(form, "delivery_address_lat"),
      delivery_address_lng: val(form, "delivery_address_lng"),
      delivery_time_from: dt(form, "delivery_from"),
      delivery_time_to: dt(form, "delivery_to"),
    };

    if (id) payload.status = val(form, "status");
    if (meta.task_sets) payload.task_set_id = val(form, "task_set_id") || "";
    if (meta.ac_enabled) {
      kinds.forEach(function (k) {
        var chk = form.querySelector('input[name="' + k + '_ac"]');
        payload[k + "_ac"] = chk && chk.checked ? 1 : 0;
        payload[k + "_ac_radius"] = val(form, k + "_ac_radius");
        payload[k + "_ac_duration"] = val(form, k + "_ac_duration");
        payload[k + "_ac_status"] = val(form, k + "_ac_status");
      });
    }

    var url = id ? URLS.item.replace("{id}", id) : URLS.store;
    tpModal.setBusy(dialog, true);
    tpModal
      .fetchJSON(url, { method: id ? "PUT" : "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dialog, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          tpModal.showFieldErrors(dialog, errors);
          var first = Object.keys(errors)[0];
          tpModal.toast(
            first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.",
            "warning",
          );
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast(id ? "Tarea actualizada." : "Tarea creada.", "success");
        tpModal.close(dialog);
        if (typeof onSaved === "function") onSaved();
      })
      .catch(function () {
        tpModal.setBusy(dialog, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ================= Página ================= */

  var rowsEl = document.getElementById("tp-tasks-rows");
  var searchEl = document.getElementById("tp-tasks-search");
  var deviceSel = document.getElementById("tp-tasks-device");
  var statusSel = document.getElementById("tp-tasks-status");
  var limitSel = document.getElementById("tp-tasks-limit");
  var rangeEl = document.getElementById("tp-tasks-range");
  var prevBtn = document.getElementById("tp-tasks-prev");
  var nextBtn = document.getElementById("tp-tasks-next");
  var addBtn = document.getElementById("tp-tasks-add");

  var state = { page: 1, per: 20, s: "", device_id: "", status: "", last: 1, total: 0 };
  var timer = null;

  var STATUS_STYLE = {
    1: "background:#f1f5f9;color:#475569;", // nueva
    2: "background:#dbeafe;color:#1d4ed8;", // en curso
    3: "background:#fee2e2;color:#b91c1c;", // fallida
    9: "background:#dcfce7;color:#15803d;", // completada
  };

  function statusChip(r) {
    return (
      '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;' +
      (STATUS_STYLE[r.status] || STATUS_STYLE[1]) + '">' + esc(r.status_name) + "</span>"
    );
  }

  function kebab(row) {
    var items = "";
    if (canEdit)
      items +=
        '<button type="button" role="menuitem" data-tsk-edit="' + row.id + '">' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> Editar</button>';
    if (row.signature_url)
      items +=
        '<button type="button" role="menuitem" data-tsk-sig="' + esc(row.signature_url) + '">' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg> Firma</button>';
    if (canRemove)
      items +=
        '<button type="button" role="menuitem" class="is-danger" data-tsk-del="' + row.id + '">' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> Eliminar</button>';
    if (!items) return "";
    return (
      '<div class="tp-svc-kebab">' +
      '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
      "</button>" +
      '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>"
    );
  }

  function load() {
    var q =
      URLS.list +
      "?s=" + encodeURIComponent(state.s) +
      "&device_id=" + encodeURIComponent(state.device_id) +
      "&status=" + encodeURIComponent(state.status) +
      "&page=" + state.page +
      "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        rowsEl.innerHTML = '<tr><td colspan="6" class="tp-svc-table__empty">No se pudo cargar.</td></tr>';
        return;
      }
      state.last = res.data.last || 1;
      state.total = res.data.total || 0;
      var items = res.data.items || [];
      rowsEl.innerHTML = items.length
        ? items
            .map(function (r) {
              var addrs = [r.pickup_address, r.delivery_address].filter(Boolean).join(" → ");
              return (
                "<tr>" +
                '<td title="' + esc(addrs) + '">' + esc(r.title) + "</td>" +
                "<td>" + esc(r.device || "–") + "</td>" +
                "<td>" + statusChip(r) + "</td>" +
                "<td>" + esc(r.priority_name || "–") + "</td>" +
                "<td>" + esc(r.invoice || "–") + "</td>" +
                '<td class="tp-svc-table__actions">' + kebab(r) + "</td>" +
                "</tr>"
              );
            })
            .join("")
        : '<tr><td colspan="6" class="tp-svc-table__empty">Sin datos</td></tr>';
      var from = state.total ? (state.page - 1) * state.per + 1 : 0;
      var to = Math.min(state.page * state.per, state.total);
      rangeEl.textContent = from + "–" + to + " / " + state.total;
      prevBtn.disabled = state.page <= 1;
      nextBtn.disabled = state.page >= state.last;
    });
  }

  searchEl.addEventListener("input", function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      state.s = searchEl.value.trim();
      state.page = 1;
      load();
    }, 300);
  });
  deviceSel.addEventListener("change", function () {
    state.device_id = deviceSel.value;
    state.page = 1;
    load();
  });
  statusSel.addEventListener("change", function () {
    state.status = statusSel.value;
    state.page = 1;
    load();
  });
  limitSel.addEventListener("change", function () {
    state.per = parseInt(limitSel.value, 10) || 20;
    state.page = 1;
    load();
  });
  prevBtn.addEventListener("click", function () {
    if (state.page > 1) {
      state.page--;
      load();
    }
  });
  nextBtn.addEventListener("click", function () {
    if (state.page < state.last) {
      state.page++;
      load();
    }
  });
  addBtn.addEventListener("click", function () {
    openForm(null, load);
  });

  rowsEl.addEventListener("click", function (e) {
    var edit = e.target.closest("[data-tsk-edit]");
    if (edit) {
      openForm(edit.getAttribute("data-tsk-edit"), load);
      return;
    }
    var sig = e.target.closest("[data-tsk-sig]");
    if (sig) {
      window.open(sig.getAttribute("data-tsk-sig"), "_blank");
      return;
    }
    var del = e.target.closest("[data-tsk-del]");
    if (del) {
      var tid = del.getAttribute("data-tsk-del");
      tpModal
        .confirm({
          title: "Eliminar tarea",
          message: "¿Eliminar esta tarea?",
          danger: true,
          confirmLabel: "Eliminar",
        })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.item.replace("{id}", tid), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Tarea eliminada.", "success");
              load();
            } else {
              tpModal.toast("No se pudo eliminar.", "error");
            }
          });
        });
    }
  });

  // Filtro de vehículos: catálogo desde meta (con buscador del tp-select)
  tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
    if (!res.ok || !res.data || !res.data.ok) return;
    deviceSel.innerHTML =
      '<option value="">Todos los vehículos</option>' +
      (res.data.devices || [])
        .map(function (d) {
          return '<option value="' + d.id + '">' + esc(d.name) + "</option>";
        })
        .join("");
    if (window.tpSelect && tpSelect.refresh) {
      try {
        tpSelect.refresh(deviceSel);
      } catch (err) {}
    }
  });

  load();
})();
