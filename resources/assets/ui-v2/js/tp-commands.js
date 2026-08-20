/* =====================================================
 * TalentusPro · tp-commands · Enviar comando (GPRS / SMS)
 * Modal compartido sobre tpModal, abierto desde el rail o el
 * kebab de Live:  tpCommands.open(deviceId?)
 * Backend: Ui\V2\CommandsController → CommandService legacy
 * (catálogo dinámico por protocolo + plantillas GPRS/SMS) y
 * SendCommandModalHelper (validación + envío).
 * Config JSON en #tp-commands-config (urls + i18n).
 * ===================================================== */
(function () {
  "use strict";

  var CFG = null;

  function cfg() {
    if (CFG) return CFG;
    var el = document.getElementById("tp-commands-config");
    if (!el) return null;
    try {
      CFG = JSON.parse(el.textContent);
    } catch (e) {
      CFG = null;
    }
    return CFG;
  }
  function T(k, fb) {
    var c = cfg();
    return (c && c.i18n && c.i18n[k]) || fb || k;
  }
  function esc(s) {
    return window.tpModal ? tpModal.escapeHtml(s) : String(s == null ? "" : s);
  }

  /* Clases Tailwind AUTOCONTENIDAS (el modal abre en cualquier página; no
     depende de CSS por-página. preflight está off → resets explícitos). */
  var UI = {
    pillWrap: "inline-flex gap-1 p-1 rounded-lg bg-slate-100 dark:bg-slate-700/60 mb-4",
    pillOn:
      "appearance-none border-0 cursor-pointer px-4 py-1.5 rounded-md text-[12.5px] font-semibold " +
      "bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white",
    pillOff:
      "appearance-none border-0 cursor-pointer px-4 py-1.5 rounded-md text-[12.5px] font-semibold " +
      "bg-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400",
    linkBtn:
      "appearance-none border-0 bg-transparent p-0 cursor-pointer text-[12px] font-semibold " +
      "text-[color:var(--brand-600,#0d9488)] hover:underline",
    list:
      "mt-2 max-h-44 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 " +
      "dark:border-slate-600 dark:bg-slate-800",
    item:
      "flex items-center gap-2.5 px-2.5 py-2 rounded-md text-[13px] text-slate-700 cursor-pointer " +
      "hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700/50",
    chk: "tp-choice-input",
    radio: "tp-choice",
    label: "block text-[12px] font-semibold text-slate-600 dark:text-slate-300 mb-1.5",
    bar: "flex items-center justify-between gap-3",
    sel: "inline-flex gap-3",
  };

  function open(deviceId) {
    if (!cfg() || !window.tpModal) return;

    var S = {
      meta: null,
      conn: "gprs", // gprs | sms
      checked: {}, // device id → true
      commands: [], // catálogo actual [{type,title,attributes[]}]
      loadSeq: 0,
    };
    if (deviceId) S.checked[deviceId] = true;

    var body =
      // tp-cmd-form: separación uniforme (gap) entre secciones del modal
      '<div class="tp-cmd tp-cmd-form">' +
      // Conexión: radios (estilo referencia)
      '<div><label class="' + UI.label + '">' + esc(T("connection", "Conexión")) + "</label>" +
      '<div class="tp-choice-row">' +
      '<label class="' + UI.radio + '"><input type="radio" class="' + UI.chk + '" name="cmd_conn" value="gprs" checked><span>' +
      esc(T("gprs", "GPRS")) + "</span></label>" +
      '<label class="' + UI.radio + '"><input type="radio" class="' + UI.chk + '" name="cmd_conn" value="sms"><span>' +
      esc(T("sms", "SMS")) + "</span></label>" +
      "</div></div>" +
      // Auto-send when online (solo GPRS): encola si el device está desconectado
      '<label class="' + UI.radio + '" data-cmd-autowrap>' +
      '<input type="checkbox" class="' + UI.chk + '" data-cmd-auto><span>' +
      esc(T("auto_send_when_online", "Enviar automáticamente al conectarse")) +
      "</span></label>" +
      '<div data-cmd-alert class="hidden mb-3 rounded-lg px-3 py-2.5 text-[13px]"></div>' +
      // Dispositivos: dropdown multi-select con chips (estilo referencia)
      '<div><label class="' + UI.label + '">' + esc(T("devices", "Dispositivos")) + "</label>" +
      '<div class="relative" data-cmd-devwrap>' +
      '<button type="button" data-cmd-devbtn class="appearance-none cursor-pointer w-full min-h-[36px] text-left ' +
      "pl-2 pr-9 py-1 rounded-lg border border-solid border-slate-300 bg-white relative " +
      "flex flex-wrap items-center gap-1 " +
      "hover:border-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 " +
      'dark:bg-slate-800 dark:border-slate-600">' +
      '<span data-cmd-chips class="contents"></span>' +
      '<span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400">' +
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>' +
      "</button>" +
      // Portal fixed (se re-cuelga del <dialog> en bind): un panel absolute
      // quedaría RECORTADO por el overflow del cuerpo del modal.
      '<div data-cmd-devpanel hidden class="fixed z-[5000] rounded-lg border border-solid ' +
      'border-slate-200 bg-white shadow-xl shadow-slate-900/15 p-1.5 dark:bg-slate-800 dark:border-slate-600">' +
      '<div class="flex items-center gap-3 px-1 pb-1.5">' +
      '<input type="text" class="tp-input" style="height:32px;" data-cmd-search placeholder="' +
      esc(T("search", "Buscar")) + '…" autocomplete="off">' +
      '<button type="button" class="' + UI.linkBtn + ' whitespace-nowrap" data-cmd-all>' + esc(T("select_all", "Seleccionar todo")) + "</button>" +
      '<button type="button" class="' + UI.linkBtn + ' whitespace-nowrap" data-cmd-none>' + esc(T("deselect_all", "Deseleccionar todo")) + "</button>" +
      "</div>" +
      '<div class="max-h-52 overflow-y-auto" data-cmd-devices></div>' +
      "</div></div>" +
      '<div class="tp-hint" data-cmd-devhint hidden></div></div>' +
      // Tipo + atributos: SOLO visibles con dispositivos seleccionados
      '<div class="tp-cmd-form" data-cmd-typewrap' + (deviceId ? "" : " hidden") + ">" +
      '<div><label class="' + UI.label + '">' +
      esc(T("type", "Tipo")) +
      '</label><select name="type" class="tp-input" data-cmd-type></select></div>' +
      // Atributos dinámicos del comando
      '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-cmd-attrs></div>' +
      "</div>" +
      // Visor de resultados por dispositivo (se llena tras enviar)
      '<div data-cmd-results hidden></div>' +
      "</div>";

    tpModal.open({
      title: T("send_command", "Enviar comando"),
      size: "lg",
      body: body,
      buttons: [
        { label: T("cancel", "Cancelar"), variant: "ghost" },
        {
          label: T("send_command", "Enviar comando"),
          variant: "accent",
          close: false,
          onClick: function (dlg) {
            confirmSend(dlg);
            return false;
          },
        },
      ],
      onMount: function (dlg) {
        bind(dlg);
        loadMeta(dlg);
      },
    });

    /* ---------- Meta (gateway + devices) ---------- */
    function loadMeta(dlg) {
      tpModal.fetchJSON(cfg().urls.meta).then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          showAlert(dlg, "error", (res.data && res.data.message) || T("error", "Se produjo un error"));
          return;
        }
        S.meta = res.data;
        renderDevices(dlg);
        syncChips(dlg);
        loadCommands(dlg);
      });
    }

    /* Chips dentro del control cerrado (o placeholder si no hay selección) */
    function syncChips(dlg) {
      var box = dlg.querySelector("[data-cmd-chips]");
      if (!box) return;
      var byId = {};
      ((S.meta && S.meta.devices) || []).forEach(function (d) {
        byId[d.id] = d.name;
      });
      var names = selectedIds()
        .map(function (id) {
          return byId[id];
        })
        .filter(Boolean);
      if (!names.length) {
        box.innerHTML =
          '<span class="text-[13px] text-slate-400 dark:text-slate-500 pl-1">' +
          esc(T("select_device", "Seleccionar dispositivo")) + "…</span>";
        return;
      }
      var MAX = 3;
      var chips = names.slice(0, MAX).map(function (n) {
        return (
          '<span class="inline-flex items-center max-w-[180px] truncate px-2 py-0.5 rounded-md text-[12px] ' +
          'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200">' + esc(n) + "</span>"
        );
      });
      if (names.length > MAX)
        chips.push(
          '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[12px] font-semibold ' +
          'bg-brand-50 text-brand-700 dark:bg-slate-700 dark:text-brand-300">+' + (names.length - MAX) + "</span>",
        );
      box.innerHTML = chips.join("");
    }

    function devicesFor() {
      var all = (S.meta && S.meta.devices) || [];
      return S.conn === "sms"
        ? all.filter(function (d) {
            return d.sms;
          })
        : all;
    }

    function renderDevices(dlg, q) {
      q = (q || "").toLowerCase();
      var list = dlg.querySelector("[data-cmd-devices]");
      var rows = devicesFor().filter(function (d) {
        return !q || d.name.toLowerCase().indexOf(q) !== -1;
      });
      list.innerHTML =
        rows
          .map(function (d) {
            return (
              '<label class="' + UI.item + '"><input type="checkbox" class="' + UI.chk + '" data-cmd-dev value="' +
              d.id + '"' + (S.checked[d.id] ? " checked" : "") + '><span class="truncate">' +
              esc(d.name) + "</span></label>"
            );
          })
          .join("") ||
        '<div class="px-3 py-2.5 text-[13px] text-slate-400 dark:text-slate-500">' + esc(T("not_found", "Sin resultados")) + "</div>";

      var hint = dlg.querySelector("[data-cmd-devhint]");
      if (S.conn === "sms") {
        if (!S.meta.sms_gateway) {
          showAlert(dlg, "warn", T("sms_gateway_disabled", "SMS Gateway deshabilitado."));
        } else if (!devicesFor().length) {
          showAlert(dlg, "warn", T("no_devices_sim", "No hay dispositivos con número SIM."));
        }
        hint.hidden = true;
      } else {
        hint.hidden = true;
      }
    }

    function selectedIds() {
      return Object.keys(S.checked).filter(function (id) {
        return S.checked[id];
      });
    }

    /* ---------- Catálogo de comandos ---------- */
    function loadCommands(dlg) {
      var ids = selectedIds();
      var typeSel = dlg.querySelector("[data-cmd-type]");
      var seq = ++S.loadSeq;
      // Tipo/atributos solo con dispositivos seleccionados
      var wrap = dlg.querySelector("[data-cmd-typewrap]");
      if (wrap) wrap.hidden = !ids.length;
      if (!ids.length) {
        S.commands = [];
        typeSel.innerHTML = "";
        if (window.tpSelect) tpSelect.refresh(typeSel);
        renderAttrs(dlg);
        return;
      }
      typeSel.disabled = true;
      tpModal
        .fetchJSON(cfg().urls.list, { method: "POST", json: { devices: ids, connection: S.conn } })
        .then(function (res) {
          if (seq !== S.loadSeq) return; // respuesta obsoleta
          typeSel.disabled = false;
          S.commands = res.ok && Array.isArray(res.data) ? res.data : [];
          var cur = typeSel.value;
          typeSel.innerHTML = S.commands
            .map(function (c) {
              return '<option value="' + esc(c.type) + '">' + esc(c.title) + "</option>";
            })
            .join("");
          if (cur && typeSel.querySelector('option[value="' + CSS.escape(cur) + '"]')) typeSel.value = cur;
          if (window.tpSelect) tpSelect.refresh(typeSel); // re-lee opciones (tp-select)
          renderAttrs(dlg);
        });
    }

    function currentCommand(dlg) {
      var t = dlg.querySelector("[data-cmd-type]").value;
      return (
        S.commands.filter(function (c) {
          return String(c.type) === String(t);
        })[0] || null
      );
    }

    /* ---------- Atributos dinámicos (paridad formBuilder legacy) ---------- */
    function renderAttrs(dlg) {
      var box = dlg.querySelector("[data-cmd-attrs]");
      var cmd = currentCommand(dlg);
      var attrs = (cmd && cmd.attributes) || [];
      box.innerHTML = attrs
        .map(function (a) {
          var val = a.default != null ? a.default : "";
          var field = "";
          switch (a.type) {
            case "text":
              field = '<textarea class="tp-textarea" rows="2" name="' + esc(a.html_name) + '">' + esc(val) + "</textarea>";
              break;
            case "datetime":
              field = '<input type="datetime-local" step="1" class="tp-input" name="' + esc(a.html_name) + '" data-cmd-dt>';
              break;
            case "select":
            case "multiselect":
              field =
                '<select class="tp-input" name="' + esc(a.html_name) + '" data-no-ts="1"' +
                (a.type === "multiselect" ? " multiple" : "") + ">" +
                ((a.options || [])
                  .map(function (o) {
                    return '<option value="' + esc(o.id) + '"' + (String(val) === String(o.id) ? " selected" : "") + ">" + esc(o.title) + "</option>";
                  })
                  .join("")) +
                "</select>";
              break;
            default: // integer | string
              field = '<input type="text" class="tp-input" name="' + esc(a.html_name) + '" value="' + esc(val) + '">';
          }
          // Las descripciones legacy traen <br> literales: escapar todo y
          // re-permitir SOLO los saltos de línea.
          var descHtml = a.description
            ? esc(a.description).replace(/&lt;br\s*\/?\s*&gt;/gi, "<br>")
            : "";
          return (
            '<div class="tp-field' + (a.type === "text" ? " sm:col-span-2" : "") + '"><label class="tp-label">' +
            esc(a.title) + "</label>" + field +
            (descHtml ? '<div class="tp-hint">' + descHtml + "</div>" : "") +
            "</div>"
          );
        })
        .join("");
    }

    /* ---------- Envío ---------- */
    function payload(dlg) {
      var data = { connection: S.conn, devices: selectedIds(), type: dlg.querySelector("[data-cmd-type]").value };
      var auto = dlg.querySelector("[data-cmd-auto]");
      if (S.conn === "gprs" && auto && auto.checked) data.auto_online = 1;
      dlg.querySelectorAll("[data-cmd-attrs] [name]").forEach(function (el) {
        if (el.multiple) {
          data[el.name.replace(/\[\]$/, "")] = Array.prototype.map.call(el.selectedOptions, function (o) {
            return o.value;
          });
        } else if (el.hasAttribute("data-cmd-dt")) {
          // datetime-local → "YYYY-MM-DD HH:mm:ss" (formato legacy)
          data[el.name] = el.value ? el.value.replace("T", " ") + (el.value.length === 16 ? ":00" : "") : "";
        } else {
          data[el.name] = el.value;
        }
      });
      return data;
    }

    function confirmSend(dlg) {
      if (!selectedIds().length || !dlg.querySelector("[data-cmd-type]").value) {
        showAlert(dlg, "error", T("not_found", "Selecciona dispositivos y comando."));
        return;
      }
      tpModal
        .confirm({
          title: T("send_command", "Enviar comando"),
          message: T("are_you_sure", "¿Estás seguro?"),
          confirmLabel: T("send", "Enviar"),
          cancelLabel: T("cancel", "Cancelar"),
        })
        .then(function (ok) {
          if (ok) doSend(dlg);
        });
    }

    function doSend(dlg) {
      tpModal.setBusy(dlg, true);
      tpModal
        .fetchJSON(cfg().urls.send, { method: "POST", json: payload(dlg) })
        .then(function (res) {
          tpModal.setBusy(dlg, false);
          if (res.ok && res.data && res.data.ok) {
            if (res.data.warnings && res.data.warnings.length) {
              showAlert(
                dlg,
                "warn",
                res.data.warnings
                  .map(function (w) {
                    return esc(w);
                  })
                  .join("<br>"),
                true,
              );
            } else {
              showAlert(dlg, "ok", esc(res.data.message || T("command_sent", "Comando enviado.")), true);
            }
            renderResults(dlg, res.data.results || [], res.data.queued || []);
            return;
          }
          var errors = res.data && res.data.errors;
          if (errors) {
            // "Etiqueta: mensaje" por campo (ej. "Dispositivos: Necesario")
            var lines = Object.keys(errors).map(function (k) {
              var e = errors[k];
              var msg = Array.isArray(e) ? e[0] : e;
              return "<b>" + esc(T(k, k)) + ":</b> " + esc(msg);
            });
            showAlert(dlg, "error", lines.join("<br>"), true);
          } else {
            showAlert(dlg, "error", esc((res.data && res.data.message) || T("error", "Se produjo un error")), true);
          }
        })
        .catch(function () {
          tpModal.setBusy(dlg, false);
          showAlert(dlg, "error", esc(T("error", "Se produjo un error")), true);
        });
    }

    /* ---------- Visor de resultados por dispositivo ---------- */
    function resultBadge(kind) {
      var cls =
        kind === "ok"
          ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
          : kind === "queued"
            ? "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
            : "bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300";
      var label =
        kind === "ok" ? "OK" : kind === "queued" ? esc(T("queued", "En cola")) : "Error";
      return '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold ' + cls + '">' + label + "</span>";
    }
    function renderResults(dlg, results, queued) {
      var box = dlg.querySelector("[data-cmd-results]");
      if (!box) return;
      if ((!results || !results.length) && (!queued || !queued.length)) {
        box.hidden = true;
        box.innerHTML = "";
        return;
      }
      var rows = "";
      (results || []).forEach(function (r) {
        var detail = r.error || r.message || "";
        rows +=
          '<div class="flex items-start gap-2.5 px-3 py-2 border-0 border-t border-solid border-slate-100 dark:border-slate-700">' +
          '<span class="flex-1 min-w-0 truncate text-[13px] text-slate-700 dark:text-slate-200">' + esc(r.device || "—") + "</span>" +
          resultBadge(r.status ? "ok" : "err") +
          (detail
            ? '<span class="max-w-[45%] text-[12px] text-slate-500 dark:text-slate-400 break-words">' + esc(detail) + "</span>"
            : "") +
          "</div>";
      });
      (queued || []).forEach(function (name) {
        rows +=
          '<div class="flex items-start gap-2.5 px-3 py-2 border-0 border-t border-solid border-slate-100 dark:border-slate-700">' +
          '<span class="flex-1 min-w-0 truncate text-[13px] text-slate-700 dark:text-slate-200">' + esc(name) + "</span>" +
          resultBadge("queued") +
          '<span class="max-w-[45%] text-[12px] text-slate-500 dark:text-slate-400 break-words">' +
          esc(T("queued_for_online", "Se enviará cuando el dispositivo se conecte.")) + "</span></div>";
      });
      box.innerHTML =
        '<details class="rounded-lg border border-solid border-slate-200 dark:border-slate-600 overflow-hidden" open>' +
        '<summary class="cursor-pointer select-none px-3 py-2 text-[12.5px] font-semibold text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-700/50">' +
        esc(T("results", "Resultados")) + " (" + ((results || []).length + (queued || []).length) + ")" +
        "</summary>" + rows + "</details>";
      box.hidden = false;
    }

    /* ---------- Alert del modal ---------- */
    function showAlert(dlg, kind, html, isHtml) {
      var a = dlg.querySelector("[data-cmd-alert]");
      if (!a) return;
      a.className =
        "mb-3 rounded-lg px-3 py-2.5 text-[13px] " +
        (kind === "ok"
          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"
          : kind === "warn"
            ? "bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
            : "bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-300");
      if (isHtml) a.innerHTML = html;
      else a.textContent = html;
      a.scrollIntoView({ block: "nearest" });
    }
    function hideAlert(dlg) {
      var a = dlg.querySelector("[data-cmd-alert]");
      if (a) a.className = "hidden";
    }

    /* ---------- Wiring ---------- */
    function bind(dlg) {
      // Conexión (radios): SMS filtra devices con SIM y descarta los que no
      dlg.querySelectorAll('[name="cmd_conn"]').forEach(function (r) {
        r.addEventListener("change", function () {
          S.conn = dlg.querySelector('[name="cmd_conn"]:checked').value;
          hideAlert(dlg);
          // Auto-send solo aplica a GPRS. OJO: [hidden] pierde contra la
          // clase inline-flex del label → usar style.display.
          var aw = dlg.querySelector("[data-cmd-autowrap]");
          if (aw) aw.style.display = S.conn !== "gprs" ? "none" : "";
          var rv = dlg.querySelector("[data-cmd-results]");
          if (rv) {
            rv.hidden = true;
            rv.innerHTML = "";
          }
          if (S.meta) {
            if (S.conn === "sms") {
              // depurar seleccionados sin SIM
              var valid = {};
              devicesFor().forEach(function (d) {
                valid[d.id] = true;
              });
              Object.keys(S.checked).forEach(function (id) {
                if (!valid[id]) delete S.checked[id];
              });
            }
            renderDevices(dlg, dlg.querySelector("[data-cmd-search]").value);
            syncChips(dlg);
            loadCommands(dlg);
          }
        });
      });
      // Dropdown de dispositivos: portal fixed colgado del <dialog> (top layer)
      var devBtn = dlg.querySelector("[data-cmd-devbtn]");
      var devPanel = dlg.querySelector("[data-cmd-devpanel]");
      dlg.appendChild(devPanel);
      function placePanel() {
        if (devPanel.hidden) return;
        var r = devBtn.getBoundingClientRect();
        devPanel.style.width = r.width + "px";
        devPanel.style.left = r.left + "px";
        var below = r.bottom + 4;
        if (below + devPanel.offsetHeight > window.innerHeight - 8 && r.top - devPanel.offsetHeight - 4 > 8) {
          devPanel.style.top = r.top - devPanel.offsetHeight - 4 + "px";
        } else {
          devPanel.style.top = below + "px";
        }
      }
      devBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        devPanel.hidden = !devPanel.hidden;
        if (!devPanel.hidden) {
          placePanel();
          var s = dlg.querySelector("[data-cmd-search]");
          if (s) s.focus();
        }
      });
      window.addEventListener("scroll", placePanel, true);
      window.addEventListener("resize", placePanel);
      dlg.addEventListener("close", function () {
        window.removeEventListener("scroll", placePanel, true);
        window.removeEventListener("resize", placePanel);
      });
      dlg.addEventListener("click", function (e) {
        if (devPanel.hidden) return;
        if (!e.target.closest("[data-cmd-devwrap]") && !devPanel.contains(e.target)) devPanel.hidden = true;
      });
      dlg.querySelector("[data-cmd-search]").addEventListener("input", function () {
        renderDevices(dlg, this.value);
      });
      dlg.querySelector("[data-cmd-devices]").addEventListener("change", function (e) {
        var c = e.target.closest("[data-cmd-dev]");
        if (!c) return;
        S.checked[c.value] = c.checked;
        syncChips(dlg);
        loadCommands(dlg);
      });
      dlg.querySelector("[data-cmd-all]").addEventListener("click", function () {
        devicesFor().forEach(function (d) {
          S.checked[d.id] = true;
        });
        renderDevices(dlg, dlg.querySelector("[data-cmd-search]").value);
        syncChips(dlg);
        loadCommands(dlg);
      });
      dlg.querySelector("[data-cmd-none]").addEventListener("click", function () {
        S.checked = {};
        renderDevices(dlg, dlg.querySelector("[data-cmd-search]").value);
        syncChips(dlg);
        loadCommands(dlg);
      });
      dlg.querySelector("[data-cmd-type]").addEventListener("change", function () {
        renderAttrs(dlg);
      });
    }
  }

  window.tpCommands = { open: open };

  // Disparadores declarativos: rail y cualquier [data-tp-commands]
  document.addEventListener("click", function (e) {
    var t = e.target.closest("[data-tp-commands]");
    if (!t) return;
    e.preventDefault();
    open(parseInt(t.getAttribute("data-device-id"), 10) || null);
  });
})();
