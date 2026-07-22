/* =====================================================
 * TalentusPro · tp-drivers — Conductores (UserDrivers)
 * -----------------------------------------------------
 * Página /ui/v2/drivers: tabla (búsqueda + filtro con/sin dispositivos
 * + paginación + kebab Editar/Eliminar) y modal crear/editar estilo
 * referencia: Nombre, Dispositivos (multiselect con chips y panel
 * portal con buscador), "Establecer como actual" (checkbox + select),
 * RFID, Email, Teléfono y Descripción.
 *
 * El backend delega en UserDriverModalHelper legacy: la asignación
 * viaja como devices[] (ints, para el validador) + secuencia
 * selected_devices[] ("s;0;" + "id;1;<id>") que sincroniza el pivot;
 * el vehículo actual con current=1 + device_id (Device::changeDriver).
 * Depende de tpModal; kebab (.tp-svc-kebab) = toggle de tp-services.js.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-drivers-page");
  if (!root) return;

  var URLS = {
    list: root.getAttribute("data-list-url"),
    meta: root.getAttribute("data-meta-url"),
    store: root.getAttribute("data-store-url"),
    item: root.getAttribute("data-item-url"),
  };
  var canEdit = root.getAttribute("data-can-edit") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";

  function esc(s) {
    return tpModal.escapeHtml(s);
  }

  /* ================= Modal crear / editar ================= */

  function openForm(id, onSaved) {
    var isEdit = !!id;

    var dlg = tpModal.open({
      title: isEdit ? "Editar conductor" : "Nuevo conductor",
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
      bodyEl.innerHTML = buildFormHtml(res.data);
      wireForm(dlg, res.data);
    });
  }

  function buildFormHtml(meta) {
    var item = meta.item || {};

    return (
      '<form class="tp-form tp-driver-form">' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      // Nombre
      '<label class="tp-form__field tp-form__field--required tp-col-2">' +
      '<span class="tp-form__label">Nombre</span>' +
      '<input type="text" name="name" maxlength="120" value="' + esc(item.name || "") + '" data-tp-field>' +
      "</label>" +
      // Dispositivos (chips multiselect)
      '<div class="tp-form__field tp-col-2">' +
      '<span class="tp-form__label">Dispositivos</span>' +
      '<div data-drv-chips tabindex="0" class="tp-input" style="height:auto;min-height:36px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:4px 34px 4px 8px;cursor:pointer;position:relative;">' +
      '<span data-drv-placeholder style="color:var(--fg-muted,#94a3b8);font-size:13px;padding-left:4px;">Seleccionar dispositivos…</span>' +
      '<svg style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#64748b;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>' +
      "</div></div>" +
      // Establecer como actual
      '<div class="tp-form__field tp-col-2">' +
      '<span class="tp-form__label">Establecer como actual</span>' +
      '<div style="display:flex;gap:9px;align-items:center;">' +
      '<input type="checkbox" id="tp-drv-current" name="current" value="1"' + (item.device_id ? " checked" : "") + ">" +
      '<select name="device_id" class="tp-input" style="width:100%;flex:1;" ' +
      (item.device_id ? "" : "disabled") + ">" +
      '<option value="">—</option>' +
      (meta.devices || [])
        .map(function (d) {
          return (
            '<option value="' + d.id + '"' + (String(item.device_id) === String(d.id) ? " selected" : "") + ">" +
            esc(d.name) + "</option>"
          );
        })
        .join("") +
      "</select></div>" +
      '<small class="tp-form__hint">Marca la casilla para fijar este conductor como actual del vehículo elegido.</small>' +
      "</div>" +
      // RFID
      '<label class="tp-form__field tp-col-2">' +
      '<span class="tp-form__label">RFID</span>' +
      '<input type="text" name="rfid" maxlength="60" value="' + esc(item.rfid || "") + '" data-tp-field>' +
      "</label>" +
      // Email + Teléfono
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">Email</span>' +
      '<input type="text" name="email" maxlength="120" value="' + esc(item.email || "") + '" data-tp-field>' +
      "</label>" +
      '<label class="tp-form__field">' +
      '<span class="tp-form__label">Teléfono</span>' +
      '<input type="text" name="phone" maxlength="40" value="' + esc(item.phone || "") + '" data-tp-field>' +
      "</label>" +
      // Descripción
      '<label class="tp-form__field tp-col-2">' +
      '<span class="tp-form__label">Descripción</span>' +
      '<textarea name="description" rows="2" maxlength="500" data-tp-field>' + esc(item.description || "") + "</textarea>" +
      "</label>" +
      "</div></form>"
    );
  }

  function wireForm(dlg, meta) {
    var form = dlg.querySelector("form.tp-driver-form");
    if (!form) return;

    var item = meta.item || {};
    var devices = meta.devices || [];
    var byId = {};
    devices.forEach(function (d) {
      byId[d.id] = d.name;
    });

    var selected = {};
    (item.device_ids || []).forEach(function (i) {
      selected[i] = true;
    });
    form._drvSelected = selected;

    // ── Chips + panel portal (mismo enfoque que tp-select: fixed,
    //    colgado del dialog para no ser recortado por overflow) ──
    var chipsEl = form.querySelector("[data-drv-chips]");
    var placeholder = form.querySelector("[data-drv-placeholder]");

    var panel = document.createElement("div");
    panel.className =
      "tp-dd__panel fixed z-[5000] rounded-xl w-max max-w-[30rem] " +
      "border border-solid border-slate-200 bg-white shadow-xl shadow-slate-900/15 " +
      "p-1.5 max-h-64 overflow-y-auto overscroll-contain " +
      "dark:bg-slate-800 dark:border-slate-600 dark:shadow-black/40";
    panel.hidden = true;
    (dlg || document.body).appendChild(panel);

    var groupTitles = {};
    (meta.groups || []).forEach(function (g) {
      groupTitles[g.id] = g.title;
    });
    var byGroup = {};
    devices.forEach(function (d) {
      var gid = d.group_id || 0;
      (byGroup[gid] = byGroup[gid] || []).push(d);
    });

    function renderChips() {
      Array.prototype.forEach.call(chipsEl.querySelectorAll("[data-drv-chip]"), function (c) {
        c.remove();
      });
      var ids = Object.keys(selected);
      placeholder.style.display = ids.length ? "none" : "";
      ids.forEach(function (i) {
        var chip = document.createElement("span");
        chip.setAttribute("data-drv-chip", i);
        // Píldora bien delimitada: borde + fondo sutil de marca
        chip.style.cssText =
          "display:inline-flex;align-items:center;gap:6px;" +
          "background:var(--brand-bg-subtle,#eef3fb);border:1px solid var(--border-default,#cbd5e1);" +
          "border-radius:999px;padding:3px 10px;font-size:12px;font-weight:500;" +
          "color:var(--fg-primary,#0f172a);line-height:1.3;";
        chip.innerHTML =
          esc(byId[i] || "#" + i) +
          '<button type="button" data-drv-unchip="' + i + '" title="Quitar" ' +
          'style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);padding:0;line-height:1;font-size:13px;">×</button>';
        chipsEl.insertBefore(chip, chipsEl.lastElementChild);
      });
    }

    // Panel agrupado por grupo (checkbox de grupo + 2 columnas) con pie
    // "Seleccionar todo" + Cerrar — estilo referencia.
    function renderPanel(q) {
      q = (q || "").toLowerCase();
      var sections = "";
      Object.keys(byGroup).forEach(function (gid) {
        var rows = byGroup[gid].filter(function (d) {
          return !q || d.name.toLowerCase().indexOf(q) >= 0;
        });
        if (!rows.length) return;
        sections +=
          '<div data-drv-gsec="' + gid + '" style="border:1px solid var(--border-default,#eef2f7);border-radius:10px;margin:0 4px 8px;overflow:hidden;">' +
          '<label style="display:flex;align-items:center;gap:9px;padding:7px 10px;background:var(--surface-sunken,#f8fafc);cursor:pointer;">' +
          '<input type="checkbox" class="tp-choice-input" data-drv-gchk="' + gid + '">' +
          '<span style="font-size:13px;font-weight:600;color:var(--fg-secondary,#475569);">' +
          esc(groupTitles[gid] || "Sin grupo") + "</span>" +
          '<span style="font-size:11px;color:var(--fg-muted,#94a3b8);">(' + byGroup[gid].length + ")</span>" +
          "</label>" +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;padding:6px 10px;">' +
          rows
            .map(function (d) {
              return (
                '<label style="display:flex;align-items:center;gap:9px;padding:5px 2px;border-radius:6px;cursor:pointer;font-size:13px;">' +
                '<input type="checkbox" class="tp-choice-input" data-drv-opt="' + d.id + '" data-gid="' + gid + '"' +
                (selected[d.id] ? " checked" : "") + ">" +
                "<span>" + esc(d.name) + "</span></label>"
              );
            })
            .join("") +
          "</div></div>";
      });

      panel.innerHTML =
        '<div style="position:sticky;top:0;background:inherit;padding:2px 4px 6px;z-index:1;">' +
        '<input type="search" data-drv-panel-search placeholder="Búsqueda" autocomplete="off" ' +
        'style="width:100%;height:30px;padding:0 10px;border:1px solid var(--border-default,#e2e8f0);border-radius:8px;font-size:13px;background:var(--surface-sunken,#f8fafc);" value="' + esc(q) + '">' +
        "</div>" +
        (sections || '<div style="padding:8px 10px;font-size:13px;color:var(--fg-muted,#94a3b8);">Sin resultados.</div>') +
        '<div style="position:sticky;bottom:0;background:inherit;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 6px 4px;border-top:1px solid var(--border-default,#eef2f7);z-index:1;">' +
        '<label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600;cursor:pointer;">' +
        '<input type="checkbox" class="tp-choice-input" data-drv-selall> Seleccionar todo</label>' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-drv-close>Cerrar</button>' +
        "</div>";

      syncPanelChecks();
    }

    // Estados de los checkbox de grupo y del "Seleccionar todo"
    function syncPanelChecks() {
      Array.prototype.forEach.call(panel.querySelectorAll("[data-drv-gchk]"), function (g) {
        var gid = g.getAttribute("data-drv-gchk");
        var all = byGroup[gid] || [];
        var on = all.filter(function (d) {
          return selected[d.id];
        }).length;
        g.checked = on > 0 && on === all.length;
        g.indeterminate = on > 0 && on < all.length;
      });
      var selAll = panel.querySelector("[data-drv-selall]");
      if (selAll) {
        var total = devices.length;
        var on = Object.keys(selected).length;
        selAll.checked = total > 0 && on === total;
        selAll.indeterminate = on > 0 && on < total;
      }
    }

    function positionPanel() {
      var r = chipsEl.getBoundingClientRect();
      panel.style.minWidth = r.width + "px";
      panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - panel.offsetWidth - 8)) + "px";
      var below = r.bottom + 4;
      if (below + panel.offsetHeight > window.innerHeight - 8 && r.top - panel.offsetHeight - 4 > 8) {
        panel.style.top = r.top - panel.offsetHeight - 4 + "px";
      } else {
        panel.style.top = below + "px";
      }
    }

    function openPanel() {
      renderPanel("");
      panel.hidden = false;
      positionPanel();
      var s = panel.querySelector("[data-drv-panel-search]");
      if (s) s.focus();
    }

    function closePanel() {
      panel.hidden = true;
    }

    chipsEl.addEventListener("click", function (e) {
      var un = e.target.closest("[data-drv-unchip]");
      if (un) {
        delete selected[un.getAttribute("data-drv-unchip")];
        renderChips();
        e.stopPropagation();
        return;
      }
      panel.hidden ? openPanel() : closePanel();
    });

    panel.addEventListener("change", function (e) {
      var opt = e.target.closest("[data-drv-opt]");
      if (opt) {
        var did = opt.getAttribute("data-drv-opt");
        if (opt.checked) selected[did] = true;
        else delete selected[did];
        renderChips();
        syncPanelChecks();
        return;
      }
      var gchk = e.target.closest("[data-drv-gchk]");
      if (gchk) {
        var gid = gchk.getAttribute("data-drv-gchk");
        (byGroup[gid] || []).forEach(function (d) {
          if (gchk.checked) selected[d.id] = true;
          else delete selected[d.id];
        });
        Array.prototype.forEach.call(panel.querySelectorAll('[data-drv-opt][data-gid="' + gid + '"]'), function (c) {
          c.checked = gchk.checked;
        });
        renderChips();
        syncPanelChecks();
        return;
      }
      var selAll = e.target.closest("[data-drv-selall]");
      if (selAll) {
        devices.forEach(function (d) {
          if (selAll.checked) selected[d.id] = true;
          else delete selected[d.id];
        });
        Array.prototype.forEach.call(panel.querySelectorAll("[data-drv-opt]"), function (c) {
          c.checked = selAll.checked;
        });
        renderChips();
        syncPanelChecks();
      }
    });

    panel.addEventListener("click", function (e) {
      if (e.target.closest("[data-drv-close]")) closePanel();
    });

    panel.addEventListener("input", function (e) {
      if (e.target.matches("[data-drv-panel-search]")) {
        var q = e.target.value;
        renderPanel(q);
        var s = panel.querySelector("[data-drv-panel-search]");
        s.focus();
        s.setSelectionRange(q.length, q.length);
      }
    });

    document.addEventListener("mousedown", function (e) {
      if (panel.hidden) return;
      if (panel.contains(e.target) || chipsEl.contains(e.target)) return;
      closePanel();
    });

    dlg.addEventListener("close", closePanel);

    renderChips();

    // ── Establecer como actual: habilitador + refresh tp-select ──
    form.addEventListener("change", function (e) {
      if (e.target.id === "tp-drv-current") {
        var sel = form.querySelector('select[name="device_id"]');
        if (sel) {
          sel.disabled = !e.target.checked;
          if (sel._tpdd && window.tpSelect && tpSelect.refresh) {
            try {
              tpSelect.refresh(sel);
            } catch (err) {}
          }
        }
      }
    });
  }

  function submitForm(dialog, id, onSaved) {
    var form = dialog.querySelector("form.tp-driver-form");
    if (!form) return;

    var selected = form._drvSelected || {};
    var ids = Object.keys(selected);

    var payload = {
      name: form.querySelector('input[name="name"]').value.trim(),
      rfid: form.querySelector('input[name="rfid"]').value.trim(),
      email: form.querySelector('input[name="email"]').value.trim(),
      phone: form.querySelector('input[name="phone"]').value.trim(),
      description: form.querySelector('textarea[name="description"]').value.trim(),
      // devices[]: ints (regla del validador) + secuencia EntityLoader
      // que sincroniza el pivot (selección exacta)
      devices: ids.map(Number),
      selected_devices: ["s;0;"].concat(
        ids.map(function (i) {
          return "id;1;" + i;
        }),
      ),
    };

    var currentChk = form.querySelector("#tp-drv-current");
    if (currentChk && currentChk.checked) {
      payload.current = 1;
      payload.device_id = form.querySelector('select[name="device_id"]').value || "";
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
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || "No se pudo guardar.", "error");
          return;
        }
        tpModal.toast(id ? "Conductor actualizado." : "Conductor creado.", "success");
        tpModal.close(dialog);
        if (typeof onSaved === "function") onSaved();
      })
      .catch(function () {
        tpModal.setBusy(dialog, false);
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ================= Página ================= */

  var rowsEl = document.getElementById("tp-drivers-rows");
  var searchEl = document.getElementById("tp-drivers-search");
  var assignedSel = document.getElementById("tp-drivers-assigned");
  var limitSel = document.getElementById("tp-drivers-limit");
  var rangeEl = document.getElementById("tp-drivers-range");
  var prevBtn = document.getElementById("tp-drivers-prev");
  var nextBtn = document.getElementById("tp-drivers-next");
  var addBtn = document.getElementById("tp-drivers-add");

  var state = { page: 1, per: 20, s: "", assigned: "", last: 1, total: 0 };
  var timer = null;

  function kebab(row) {
    var items = "";
    if (canEdit)
      items +=
        '<button type="button" role="menuitem" data-drv-edit="' + row.id + '">' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> Editar</button>';
    if (canRemove)
      items +=
        '<button type="button" role="menuitem" class="is-danger" data-drv-del="' + row.id + '">' +
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
      "&assigned=" + encodeURIComponent(state.assigned) +
      "&page=" + state.page +
      "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        rowsEl.innerHTML = '<tr><td colspan="8" class="tp-svc-table__empty">No se pudo cargar.</td></tr>';
        return;
      }
      state.last = res.data.last || 1;
      state.total = res.data.total || 0;
      var items = res.data.items || [];
      rowsEl.innerHTML = items.length
        ? items
            .map(function (r) {
              return (
                "<tr>" +
                "<td>" + esc(r.name) + "</td>" +
                "<td>" + esc(r.current_device || "–") + "</td>" +
                "<td>" + esc(r.rfid || "–") + "</td>" +
                "<td>" + esc(r.phone || "–") + "</td>" +
                "<td>" + esc(r.email || "–") + "</td>" +
                "<td>" + esc(r.description || "–") + "</td>" +
                "<td>" + esc(r.devices || "–") + "</td>" +
                '<td class="tp-svc-table__actions">' + kebab(r) + "</td>" +
                "</tr>"
              );
            })
            .join("")
        : '<tr><td colspan="8" class="tp-svc-table__empty">Sin datos</td></tr>';
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
  assignedSel.addEventListener("change", function () {
    state.assigned = assignedSel.value;
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
    var edit = e.target.closest("[data-drv-edit]");
    if (edit) {
      openForm(edit.getAttribute("data-drv-edit"), load);
      return;
    }
    var del = e.target.closest("[data-drv-del]");
    if (del) {
      var did = del.getAttribute("data-drv-del");
      tpModal
        .confirm({
          title: "Eliminar conductor",
          message: "¿Eliminar este conductor?",
          danger: true,
          confirmLabel: "Eliminar",
        })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.item.replace("{id}", did), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Conductor eliminado.", "success");
              load();
            } else {
              tpModal.toast("No se pudo eliminar.", "error");
            }
          });
        });
    }
  });

  load();
})();
