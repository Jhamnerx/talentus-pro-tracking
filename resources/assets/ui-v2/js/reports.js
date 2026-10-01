/* =====================================================
 * TalentusPro · Reportes (rediseño estilo referencia)
 * -----------------------------------------------------
 * Página con tabs: "Reportes generados" (tabla `reports` = definiciones
 * guardadas/programadas; click en fila = editar) y "Logs de programados"
 * (`report_logs`; click = descargar).
 *
 * Ojo con el nombre del primer tab, heredado del legacy: esas filas NO son
 * reportes generados, son definiciones. Por eso la accion del menu se llama
 * "Generar ahora" y no "Abrir": lanza el reporte de nuevo, y como las fechas
 * se guardan relativas, sale el mismo periodo movido a hoy.
 *
 * Modal Generar/Guardar:
 *  - Título · Periodo (picker de rango con 2 meses + rangos rápidos +
 *    Desde/Hasta con hora, como Historial) · "Controlar días" (pills
 *    Lun–Dom → period_weekdays[]) · Tipo · Formato.
 *  - Campos por tipo (paridad con el modal legacy): el catálogo de
 *    fields habilitados y los PARÁMETROS dinámicos vienen de la ruta
 *    legacy reports/types/{type} (\Field: integer/string/text/checkbox/
 *    select/multiselect/multiselect-group → port de
 *    buildReportAttributes del create.blade legacy).
 *  - Devices (chips agrupados) · Geocercas (chips) · Metas · límite de
 *    velocidad · paradas · checkboxes.
 *  - Enviar a email (chips con "+", se envía como send_to_email con ;)
 *    y programación Diario/Semanal/Mensual con hora (los flags viven en
 *    la entidad Report; el cron reports:daily los procesa).
 *  - ABRIR una guardada = se pide la definicion al endpoint de la v2 y
 *    se manda como la mandaria el formulario: la ruta legacy no sabe
 *    cargar un reporte por id, exige la definicion entera.
 *  - GENERAR = POST a la ruta legacy reports/update con generate=1 en
 *    ESTA pestaña: responde con Content-Disposition: attachment y el
 *    navegador descarga, igual que el legacy; GUARDAR = POST al backend v2
 *    que delega en ReportModalHelper::create() (los devices viajan como
 *    devices[] + secuencia selected_devices[] del EntityLoader).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-reports-page");
  if (!root) return;

  var URLS = {
    meta: root.getAttribute("data-meta-url"),
    saved: root.getAttribute("data-saved-url"),
    logs: root.getAttribute("data-logs-url"),
    item: root.getAttribute("data-item-url"),
    store: root.getAttribute("data-store-url"),
    destroy: root.getAttribute("data-destroy-url"),
    logDestroy: root.getAttribute("data-log-destroy-url"),
  };
  var canCreate = root.getAttribute("data-can-create") === "1";
  var canRemove = root.getAttribute("data-can-remove") === "1";

  var META = null; // catálogos compartidos (tipos, formatos, devices, geocercas…)

  function esc(s) {
    return tpModal.escapeHtml(s);
  }

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function ymd(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  function loadMeta() {
    if (META) return Promise.resolve(META);
    return tpModal.fetchJSON(URLS.meta, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) throw new Error("meta");
      META = res.data;
      return META;
    });
  }

  /* ================= Chips multiselect (devices/geocercas/metas) ===== */
  // Caja de chips + panel portal con buscador y checkboxes (agrupado si
  // hay groups). Devuelve {getIds, setIds, root}.
  // El selector vive en tp-chips-select.js para que lo use cualquier
  // pantalla que necesite elegir vehiculos, no solo este modal.
  function chipsSelect(opts) {
    return window.tpChipsSelect(opts);
  }

  /* ================= Periodos rápidos (paridad legacy) ============== */
  // Mismos valores que el select `period` del legacy (ReportModalHelper), y
  // el mismo calculo de fechas que momentCalendar() de helper.js.
  var PERIODOS = [
    ["", "Personalizado"],
    ["1", tpT('front.today')],
    ["2", tpT('front.yesterday')],
    ["3", tpT('ui.before_2_days')],
    ["4", tpT('ui.before_3_days')],
    ["5", tpT('ui.this_week')],
    ["6", tpT('ui.last_week')],
    ["7", tpT('ui.this_month')],
    ["8", tpT('ui.last_month')],
  ];

  /**
   * Fechas de un periodo rápido. Port de momentCalendar(): el fin es
   * EXCLUSIVO (hoy = de hoy a mañana), que es como lo entiende el backend.
   * Devuelve null para "Personalizado".
   */
  function rangoDePeriodo(valor) {
    var inicioSemana = 1;
    try {
      if (window.app && app.settings && app.settings.weekStart != null) {
        inicioSemana = parseInt(app.settings.weekStart, 10);
      }
    } catch (e) {}

    function dia(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
    function suma(d, n) { var x = dia(d); x.setDate(x.getDate() + n); return x; }
    function inicioMes(d, m) { return new Date(d.getFullYear(), d.getMonth() + (m || 0), 1); }

    // Lunes de la semana de `d`, corregido por el dia de inicio del usuario.
    function inicioDeSemana(d, semanas) {
      var x = dia(d);
      var dow = (x.getDay() + 6) % 7; // 0 = lunes
      x.setDate(x.getDate() - dow + (inicioSemana > 1 ? inicioSemana - 1 : 0) - 7 * (semanas || 0));
      return x;
    }

    var hoy = new Date();

    switch (String(valor)) {
      case "1": return [dia(hoy), suma(hoy, 1)];
      case "2": return [suma(hoy, -1), dia(hoy)];
      case "3": return [suma(hoy, -2), suma(hoy, -1)];
      case "4": return [suma(hoy, -3), suma(hoy, -2)];
      case "5": return [inicioDeSemana(hoy, 0), suma(inicioDeSemana(hoy, 0), 7)];
      case "6": return [inicioDeSemana(hoy, 1), inicioDeSemana(hoy, 0)];
      case "7": return [inicioMes(hoy, 0), inicioMes(hoy, 1)];
      case "8": return [inicioMes(hoy, -1), inicioMes(hoy, 0)];
      default: return null;
    }
  }

  /* El picker de rango de dos meses se retiro al pasar a Periodo +
     Fecha desde/hasta (paridad legacy): quedo sin usar y ya no
     describia la interfaz. Sigue en el historial de git. */


  /* ================= Modal Generar / Guardar ========================= */

  function openReportModal(id) {
    var isEdit = !!id;

    var dlg = tpModal.open({
      title: tpT('front.report'),
      size: "xl",
      static: true,
      body: '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>',
      // Mismo orden y jerarquia que el legacy: Generar es la accion
      // principal -es la que descarga el reporte, lo que se viene a hacer-
      // y Guardar solo deja la definicion para mas tarde. Estaban al reves.
      buttons: [
        {
          label: "Generar",
          variant: "primary",
          close: false,
          onClick: function (dialog) {
            doGenerate(dialog);
            return false;
          },
        },
        {
          label: tpT('global.save'),
          variant: "outline",
          close: false,
          onClick: function (dialog) {
            doSave(dialog, id);
            return false;
          },
        },
        {
          // "Nuevo" del legacy: limpia para volver a generar o guardar sin
          // arrastrar lo anterior. Se reabre el modal en vez de resetear
          // campo por campo: asi no se queda ninguno a medias -ni el id, que
          // es lo que convertia un alta en una edicion silenciosa-.
          label: tpT('front.new'),
          variant: "ghost",
          close: false,
          onClick: function (dialog) {
            tpModal.close(dialog);
            openReportModal();
            return false;
          },
        },
        { label: tpT('global.cancel'), variant: "ghost", close: true },
      ],
    });

    var itemPromise = isEdit
      ? tpModal.fetchJSON(URLS.item.replace("{id}", id), { method: "GET" }).then(function (r) {
          return r.ok && r.data && r.data.ok ? r.data.item : null;
        })
      : Promise.resolve(null);

    Promise.all([loadMeta(), itemPromise]).then(function (out) {
      buildModal(dlg, out[0], out[1]);
    }).catch(function () {
      var b = dlg.querySelector(".tp-modal__body");
      if (b) b.innerHTML = '<div class="tp-text-center tp-text-error" style="padding:24px;">' + tpT('ui.load_failed') + '</div>';
    });

    return dlg;
  }

  function buildModal(dlg, meta, item) {
    item = item || {};
    var body = dlg.querySelector(".tp-modal__body");
    if (!body) return;

    var today = new Date();
    // Default: HOY completo (00:00 → 23:59) — el "hasta 00:00 de mañana"
    // del legacy confundía al usuario.
    var range = {
      from: item.date_from || ymd(today),
      fromTime: item.from_time || "00:00",
      to: item.date_to || ymd(today),
      toTime: item.to_time || "23:59",
    };

    var groupTitles = {};
    (meta.groups || []).forEach(function (g) {
      groupTitles[g.id] = g.title;
    });

    var DAY_PILLS = [
      [1, "Lun"], [2, "Mar"], [3, tpT('ui.wed_short')], [4, "Jue"], [5, "Vie"], [6, tpT('ui.sat_short')], [7, "Dom"],
    ];

    var HR = '<hr style="border:0;border-top:1px solid var(--border-default,#eef2f7);margin:16px 0;">';
    // Franjas de 15 minutos, las mismas 96 que getSelectTimeRange() del legacy.
    function horaOpciones(sel) {
      var o = "";
      for (var i = 0; i < 96; i++) {
        var h = pad2(Math.floor(i / 4)) + ":" + pad2((i % 4) * 15);
        o += '<option value="' + h + '"' + (h === sel ? " selected" : "") + ">" + h + "</option>";
      }
      return o;
    }

    // Fecha + hora en una fila, como el input-group del legacy.
    function fechaHora(cual, fecha, hora) {
      return (
        '<div style="display:flex;gap:6px;">' +
        '<input type="date" data-rep-date="' + cual + '" value="' + esc(fecha) + '" style="flex:1;min-width:0;">' +
        '<select class="tp-input" data-rep-time="' + cual + '" style="width:104px;flex:0 0 auto;">' +
        horaOpciones(hora) +
        "</select></div>"
      );
    }

    var ROW = '<div class="tp-form__grid tp-form__grid--2">';

    body.innerHTML =
      '<form class="tp-form tp-report-form">' +
      (item.id ? '<input type="hidden" name="id" value="' + item.id + '">' : "") +
      // ── Fila 1 (layout referencia): izquierda Título/Tipo/Formato
      //    apilados; derecha el bloque de Periodo ──
      // ── Distribución del modal legacy (Reports/create.blade): Título a lo
      //    ancho y luego dos columnas — Tipo|Formato, Periodo|Dispositivos,
      //    Fecha desde|Geocercas, Fecha hasta|control de días. Antes Título,
      //    Tipo y Formato iban apilados a la izquierda y el periodo ocupaba
      //    toda la derecha, que no se parecía a lo que el operador conoce.
      '<label class="tp-form__field" style="margin-bottom:12px;"><span class="tp-form__label">' + tpT('ui.title') + '</span>' +
      '<input type="text" name="title" maxlength="120" value="' + esc(item.title || "") + '" data-tp-field></label>' +
      ROW +
      '<label class="tp-form__field"><span class="tp-form__label">' + tpT('front.type') + '</span>' +
      '<select class="tp-input" style="width:100%" name="type" data-tp-field>' +
      (meta.templates || []).map(function (t) {
        return '<option value="' + t.id + '"' + (String(item.type) === String(t.id) ? " selected" : "") + ">" + esc(t.title) + "</option>";
      }).join("") +
      "</select></label>" +
      '<label class="tp-form__field"><span class="tp-form__label">Formato</span>' +
      '<select class="tp-input" style="width:100%" name="format" data-tp-field>' +
      (meta.formats || []).map(function (f) {
        return '<option value="' + f.id + '"' + ((item.format || "html") === f.id ? " selected" : "") + ">" + esc(f.label) + "</option>";
      }).join("") +
      "</select></label>" +
      "</div>" +
      ROW +
      // Periodo rápido: los mismos 8 del legacy. Al elegir uno se rellenan
      // las dos fechas; tocarlas a mano lo deja en "Personalizado".
      '<label class="tp-form__field"><span class="tp-form__label">Periodo</span>' +
      '<select class="tp-input" style="width:100%" name="period" data-rep-period>' +
      PERIODOS.map(function (p) {
        return '<option value="' + p[0] + '">' + esc(p[1]) + "</option>";
      }).join("") +
      "</select></label>" +
      '<div class="tp-form__field" data-rep-field="devices" data-error-for="devices selected_devices"><span class="tp-form__label">' + tpT('front.devices') + '</span><div data-rep-devices></div></div>' +
      "</div>" +
      ROW +
      '<div class="tp-form__field" data-error-for="date_from from_time"><span class="tp-form__label">' + tpT('ui.date_from') + '</span>' +
      fechaHora("from", range.from, range.fromTime) + "</div>" +
      '<div class="tp-form__field" data-rep-field="geofences" data-error-for="geofences"><span class="tp-form__label">Geocercas</span><div data-rep-geofences></div></div>' +
      "</div>" +
      ROW +
      '<div class="tp-form__field" data-error-for="date_to to_time"><span class="tp-form__label">' + tpT('ui.date_to') + '</span>' +
      fechaHora("to", range.to, range.toTime) + "</div>" +
      // Filtro de días y franja horaria: no lo tiene el legacy, pero es lo
      // que permite "solo lunes a viernes de 08:00 a 18:00". Plegado.
      '<div class="tp-form__field"><span class="tp-form__label">&nbsp;</span>' +
      '<button type="button" data-rep-days-toggle style="appearance:none;border:1px solid var(--border-default,#cbd5e1);background:var(--surface-raised,#fff);border-radius:8px;cursor:pointer;color:var(--brand-500,#2e63d9);font-size:12.5px;font-weight:600;padding:8px;">' + tpT('ui.control_days_hours') + '</button>' +
      '<div data-rep-days hidden style="margin-top:10px;">' +
      '<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;">' +
      DAY_PILLS.map(function (d) {
        return (
          '<button type="button" data-rep-day="' + d[0] + '" class="is-on" ' +
          'style="appearance:none;cursor:pointer;border:1px solid var(--border-default,#cbd5e1);background:var(--surface-raised,#fff);border-radius:10px;padding:6px 12px;font-size:12px;font-weight:600;color:var(--fg-secondary,#475569);">' +
          d[1] + "</button>"
        );
      }).join("") +
      "</div>" +
      '<div style="display:flex;gap:12px;align-items:center;margin-top:12px;padding:0 4px;">' +
      '<span data-ts-label style="font-size:12px;font-weight:600;min-width:92px;color:var(--fg-primary,#0f172a);">00:00 - 24:00</span>' +
      '<div data-ts-track style="position:relative;flex:1;height:22px;cursor:pointer;touch-action:none;">' +
      '<div style="position:absolute;top:9px;left:0;right:0;height:4px;border-radius:999px;background:var(--border-default,#e2e8f0);"></div>' +
      '<div data-ts-fill style="position:absolute;top:9px;height:4px;border-radius:999px;background:var(--brand-500,#2e63d9);"></div>' +
      '<div data-ts-tip style="position:absolute;top:-24px;transform:translateX(-50%);background:var(--brand-500,#2e63d9);color:#fff;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;display:none;white-space:nowrap;">00:00</div>' +
      '<button type="button" data-ts-h="from" style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;border:1px solid var(--border-strong,#cbd5e1);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:grab;transform:translateX(-50%);padding:0;appearance:none;"></button>' +
      '<button type="button" data-ts-h="to" style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;border:1px solid var(--border-strong,#cbd5e1);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:grab;transform:translateX(-50%);padding:0;appearance:none;"></button>' +
      "</div></div></div></div>" +
      "</div> " +
      HR +
      // Parámetros dinámicos por tipo
      '<div data-rep-extra class="tp-form__grid tp-form__grid--2" style="margin-top:12px;display:none;"></div>' +
      '<div class="tp-form__grid tp-form__grid--2" style="margin-top:12px;">' +
      '<div class="tp-form__field" data-rep-field="speed_limit"><span class="tp-form__label">' + tpT('ui.speed_limit') + '</span>' +
      '<input type="text" name="speed_limit" placeholder="60" value="' + esc(item.speed_limit || "") + '" data-tp-field></div>' +
      '<div class="tp-form__field" data-rep-field="stops"><span class="tp-form__label">Paradas</span>' +
      '<select class="tp-input" style="width:100%" name="stops">' +
      (meta.stops || []).map(function (s) {
        return '<option value="' + esc(s.value) + '"' + (String(item.stops || "60") === String(s.value) ? " selected" : "") + ">" + esc(s.label) + "</option>";
      }).join("") +
      "</select></div>" +
      "</div>" +
      '<div class="tp-form__grid tp-form__grid--2" style="margin-top:12px;">' +
      '<div class="tp-form__field" data-rep-field="metas" data-error-for="metas"><span class="tp-form__label">Metas</span><div data-rep-metas></div></div>' +
      '<div class="tp-form__field"><span class="tp-form__label">&nbsp;</span>' +
      '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;min-height:36px;">' +
      '<label class="tp-choice" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="skip_blank_results" value="1"' + (item.skip_blank_results ? " checked" : "") + "><span>" + tpT('ui.skip_empty_results') + "</span></label>" +
      '<label class="tp-choice" data-rep-field="zones_instead" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="zones_instead" value="1"' + (item.zones_instead ? " checked" : "") + "><span>" + tpT('ui.zones_instead_of_addresses') + "</span></label>" +
      '<label class="tp-choice" data-rep-field="show_addresses" style="margin:0;"><input type="checkbox" class="tp-choice-input" name="show_addresses" value="1"' + (item.show_addresses ? " checked" : "") + "><span>" + tpT('ui.show_addresses') + "</span></label>" +
      "</div></div>" +
      "</div>" +
      HR +
      // ── Email (con + integrado) | Programación (alineada al input) ──
      ROW +
      '<div class="tp-form__field"><span class="tp-form__label">Enviar a email</span>' +
      '<div class="tp-input-group" style="padding-right:4px;padding-left:0;">' +
      '<input type="text" data-rep-email-input data-error-for="send_to_email" placeholder="Email…" style="padding-left:11px;">' +
      '<button type="button" data-rep-email-add title="Agregar" style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--brand-500,#2e63d9);font-size:16px;font-weight:700;padding:0 8px;line-height:1;">＋</button>' +
      "</div>" +
      '<div data-rep-emails style="display:flex;flex-direction:column;gap:6px;margin-top:6px;"></div>' +
      (meta.showFtp
        ? '<div class="tp-form__field" style="margin-top:8px;"><span class="tp-form__label">Enviar a FTP</span>' +
          '<input type="text" name="send_to_ftp" value="' + esc(item.ftp || "") + '" placeholder="ftp://usuario:contraseña@host:puerto">' +
          '<small class="tp-form__hint">' + tpT('ui.ftp_url_help') + '</small></div>'
        : "") +
      "</div>" +
      '<div class="tp-form__field"><span class="tp-form__label">&nbsp;</span>' +
      '<div style="display:flex;flex-direction:column;gap:8px;">' +
      schedRow("daily", tpT('front.daily'), item.daily, item.daily_time, "") +
      schedRow("weekly", tpT('attr.weekly'), item.weekly, item.weekly_time, daySelect("weekly_day", DAY_PILLS, item.weekly_day, tpT('ui.weekday_select'))) +
      schedRow("monthly", tpT('front.monthly'), item.monthly, item.monthly_time, daySelect("monthly_day", null, item.monthly_day, tpT('ui.monthday_select'))) +
      "</div></div>" +
      "</div></form>";

    var form = body.querySelector("form.tp-report-form");

    // ── Periodo y fechas ──
    // `form._range` sigue siendo la única fuente de la que sale el payload;
    // los tres campos solo escriben en ella.
    var periodEl = form.querySelector("[data-rep-period]");
    var dateFrom = form.querySelector('[data-rep-date="from"]');
    var dateTo = form.querySelector('[data-rep-date="to"]');
    var timeFrom = form.querySelector('[data-rep-time="from"]');
    var timeTo = form.querySelector('[data-rep-time="to"]');

    form._range = range;

    if (item.period) periodEl.value = String(item.period);

    function leerFechas() {
      var fIso = window.tpDate ? tpDate.value(dateFrom) : dateFrom.value;
      var tIso = window.tpDate ? tpDate.value(dateTo) : dateTo.value;

      range = {
        from: fIso || range.from,
        fromTime: timeFrom.value || "00:00",
        to: tIso || range.to,
        toTime: timeTo.value || "00:00",
      };
      form._range = range;
    }

    periodEl.addEventListener("change", function () {
      var r = rangoDePeriodo(periodEl.value);
      if (!r) return; // Personalizado: se respeta lo que haya escrito

      dateFrom.value = ymd(r[0]);
      dateTo.value = ymd(r[1]);
      timeFrom.value = "00:00";
      timeTo.value = "00:00";
      leerFechas();
    });

    // Tocar una fecha a mano deja de ser un periodo rápido.
    [dateFrom, dateTo, timeFrom, timeTo].forEach(function (el) {
      el.addEventListener("change", function () {
        periodEl.value = "";
        if (periodEl._tpdd && window.tpSelect && tpSelect.refresh) {
          try { tpSelect.refresh(periodEl); } catch (e) {}
        }
        leerFechas();
      });
    });

    // ── Pills de días + franja ──
    var daysWrap = form.querySelector("[data-rep-days]");
    form.querySelector("[data-rep-days-toggle]").addEventListener("click", function () {
      daysWrap.hidden = !daysWrap.hidden;
    });
    function setPill(pill, on) {
      pill.classList.toggle("is-on", on);
      pill.style.background = on ? "var(--surface-raised,#fff)" : "var(--surface-sunken,#f1f5f9)";
      pill.style.opacity = on ? "1" : "0.55";
    }
    daysWrap.addEventListener("click", function (e) {
      var pill = e.target.closest("[data-rep-day]");
      if (!pill) return;
      setPill(pill, !pill.classList.contains("is-on"));
    });

    // Precarga del filtro persistido (edición)
    var savedDays = item.period_weekdays || [];
    if (savedDays.length) {
      Array.prototype.forEach.call(form.querySelectorAll("[data-rep-day]"), function (p) {
        setPill(p, savedDays.indexOf(p.getAttribute("data-rep-day")) >= 0 || savedDays.indexOf(+p.getAttribute("data-rep-day")) >= 0);
      });
      daysWrap.hidden = false;
    }

    // Slider de franja horaria
    wireTimeSlider(form, toMin(item.period_time_from), toMin(item.period_time_to));
    if (item.period_time_from && item.period_time_to) daysWrap.hidden = false;

    // ── Chips ──
    form._devices = chipsSelect({
      mount: form.querySelector("[data-rep-devices]"),
      items: meta.devices || [],
      groups: groupTitles,
      selected: item.device_ids || [],
      placeholder: tpT('ui.select_devices_ellipsis'),
      host: dlg,
    });
    form._geofences = chipsSelect({
      mount: form.querySelector("[data-rep-geofences]"),
      items: (meta.geofences || []).map(function (g) {
        return { id: g.id, name: g.name };
      }),
      selected: item.geofence_ids || [],
      placeholder: tpT('ui.select_geofences_ellipsis'),
      host: dlg,
    });
    form._metas = chipsSelect({
      mount: form.querySelector("[data-rep-metas]"),
      items: (meta.metas || []).map(function (m) {
        return { id: m.value, name: m.label };
      }),
      selected: item.metas || [],
      placeholder: tpT('ui.select_goals_ellipsis'),
      host: dlg,
    });

    // ── Emails (chips con +) ──
    var emailsWrap = form.querySelector("[data-rep-emails]");
    var emailInput = form.querySelector("[data-rep-email-input]");
    form._emails = [];
    (item.email ? item.email.split(";") : []).forEach(function (e) {
      if (e.trim()) form._emails.push(e.trim());
    });
    // Filas de email editables con "−" (estilo referencia)
    function renderEmails() {
      emailsWrap.innerHTML = form._emails
        .map(function (e, i) {
          return (
            '<div class="tp-input-group" style="padding-right:4px;padding-left:0;">' +
            '<input type="text" value="' + esc(e) + '" data-rep-email-idx="' + i + '" style="padding-left:11px;">' +
            '<button type="button" data-rep-email-rm="' + i + '" title="Quitar" ' +
            'style="appearance:none;border:0;background:transparent;cursor:pointer;color:var(--fg-muted,#94a3b8);font-size:16px;font-weight:700;padding:0 8px;line-height:1;">−</button>' +
            "</div>"
          );
        })
        .join("");
    }
    renderEmails();
    form.querySelector("[data-rep-email-add]").addEventListener("click", function () {
      var v = emailInput.value.trim();
      if (!v) return;
      form._emails.push(v);
      emailInput.value = "";
      renderEmails();
    });
    emailInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        form.querySelector("[data-rep-email-add]").click();
      }
    });
    emailsWrap.addEventListener("click", function (e) {
      var rm = e.target.closest("[data-rep-email-rm]");
      if (!rm) return;
      form._emails.splice(+rm.getAttribute("data-rep-email-rm"), 1);
      renderEmails();
    });
    emailsWrap.addEventListener("input", function (e) {
      var idx = e.target.getAttribute && e.target.getAttribute("data-rep-email-idx");
      if (idx != null) form._emails[+idx] = e.target.value.trim();
    });

    // ── Tipo → fields + parámetros dinámicos ──
    var typeSel = form.querySelector('select[name="type"]');
    typeSel.addEventListener("change", function () {
      applyType(form, meta, typeSel.value, null);
    });
    applyType(form, meta, typeSel.value, item);
  }

  /* ── Slider de franja horaria (dos manijas, paso 5 min) ── */
  function fmtMin(m) {
    return pad2(Math.floor(m / 60)) + ":" + pad2(m % 60);
  }

  // Opciones de hora en pasos de 5 min (24h) + 23:59 — el componente de
  // hora de la v2 es un select mejorado por tp-select (con buscador),
  // no el input time nativo (que muestra AM/PM y se corta). Si el valor
  // guardado no calza en la rejilla, se inyecta como opción propia.
  function timeOptionsHtml(selected) {
    var html = "";
    var found = false;
    for (var m = 0; m < 1440; m += 5) {
      var t = fmtMin(m);
      var sel = t === selected;
      if (sel) found = true;
      html += '<option value="' + t + '"' + (sel ? " selected" : "") + ">" + t + "</option>";
    }
    var sel59 = selected === "23:59";
    html += '<option value="23:59"' + (sel59 ? " selected" : "") + ">23:59</option>";
    if (sel59) found = true;
    if (!found && selected) {
      html = '<option value="' + esc(selected) + '" selected>' + esc(selected) + "</option>" + html;
    }
    return html;
  }

  function toMin(hhmm) {
    if (!hhmm || !/^\d{1,2}:\d{2}/.test(hhmm)) return null;
    var p = hhmm.split(":");
    return Math.min(1440, +p[0] * 60 + +p[1]);
  }

  function wireTimeSlider(form, initFrom, initTo) {
    var track = form.querySelector("[data-ts-track]");
    if (!track) return;
    var fill = form.querySelector("[data-ts-fill]");
    var tip = form.querySelector("[data-ts-tip]");
    var label = form.querySelector("[data-ts-label]");
    var hFrom = form.querySelector('[data-ts-h="from"]');
    var hTo = form.querySelector('[data-ts-h="to"]');

    var st = { from: initFrom != null ? initFrom : 0, to: initTo != null ? initTo : 1440 };
    form._tsGet = function () {
      return { from: st.from, to: st.to };
    };

    function render() {
      var pf = (st.from / 1440) * 100;
      var pt = (st.to / 1440) * 100;
      hFrom.style.left = pf + "%";
      hTo.style.left = pt + "%";
      fill.style.left = pf + "%";
      fill.style.width = pt - pf + "%";
      label.textContent = fmtMin(st.from) + " - " + fmtMin(st.to);
    }

    function minutesFromEvent(e) {
      var r = track.getBoundingClientRect();
      var x = Math.max(0, Math.min(e.clientX - r.left, r.width));
      return Math.round(((x / r.width) * 1440) / 5) * 5;
    }

    function showTip(handle, value) {
      tip.style.display = "";
      tip.style.left = handle.style.left;
      tip.textContent = fmtMin(value);
    }

    var dragging = null;

    function startDrag(which, e) {
      dragging = which;
      e.preventDefault();
      showTip(which === "from" ? hFrom : hTo, which === "from" ? st.from : st.to);
      document.addEventListener("pointermove", onMove);
      document.addEventListener("pointerup", onUp);
    }

    function onMove(e) {
      if (!dragging) return;
      var m = minutesFromEvent(e);
      if (dragging === "from") st.from = Math.min(m, st.to - 5);
      else st.to = Math.max(m, st.from + 5);
      st.from = Math.max(0, st.from);
      st.to = Math.min(1440, st.to);
      render();
      showTip(dragging === "from" ? hFrom : hTo, dragging === "from" ? st.from : st.to);
    }

    function onUp() {
      dragging = null;
      tip.style.display = "none";
      document.removeEventListener("pointermove", onMove);
      document.removeEventListener("pointerup", onUp);
    }

    hFrom.addEventListener("pointerdown", function (e) {
      startDrag("from", e);
    });
    hTo.addEventListener("pointerdown", function (e) {
      startDrag("to", e);
    });
    track.addEventListener("pointerdown", function (e) {
      if (e.target === hFrom || e.target === hTo) return;
      // mueve la manija más cercana al punto clicado
      var m = minutesFromEvent(e);
      var which = Math.abs(m - st.from) <= Math.abs(m - st.to) ? "from" : "to";
      if (which === "from") st.from = Math.min(m, st.to - 5);
      else st.to = Math.max(m, st.from + 5);
      render();
      startDrag(which, e);
    });

    render();
  }

  function schedRow(key, label, on, time, extra) {
    return (
      '<div style="display:flex;gap:9px;align-items:center;">' +
      '<label class="tp-choice" style="margin:0;min-width:96px;">' +
      '<input type="checkbox" class="tp-choice-input" name="' + key + '" value="1" id="tp-rep-' + key + '"' + (on ? " checked" : "") + ">" +
      "<span>" + label + "</span></label>" +
      '<select class="tp-input" name="' + key + '_time" data-enable="tp-rep-' + key + '"' + (on ? "" : " disabled") + ' style="width:104px;">' +
      timeOptionsHtml(time || "00:00") +
      "</select>" +
      (extra || "") +
      "</div>"
    );
  }

  // Día concreto del schedule (extensión: weekly_day ISO 1-7 / monthly_day 1-31)
  function daySelect(name, pills, selected, placeholder) {
    var key = name === "weekly_day" ? "weekly" : "monthly";
    var html = '<select class="tp-input" name="' + name + '" data-enable="tp-rep-' + key + '"' +
      (selected ? "" : " disabled") + ' style="width:150px;">' +
      '<option value="">' + esc(placeholder) + "</option>";
    if (pills) {
      pills.forEach(function (d) {
        html += '<option value="' + d[0] + '"' + (String(selected) === String(d[0]) ? " selected" : "") + ">" + d[1] + "</option>";
      });
    } else {
      for (var i = 1; i <= 31; i++) {
        html += '<option value="' + i + '"' + (String(selected) === String(i) ? " selected" : "") + ">" + i + "</option>";
      }
    }
    return html + "</select>";
  }

  // Muestra/oculta campos según el tipo y renderiza sus parámetros
  // dinámicos (port de buildReportAttributes del create.blade legacy).
  /**
   * Habilita o apaga un campo del formulario por su `data-rep-field`. Los
   * de chips (devices/geocercas/metas) tienen su propio setDisabled, porque
   * no son inputs nativos.
   */
  function setFieldEnabled(form, campo, on) {
    var bloque = form.querySelector('[data-rep-field="' + campo + '"]');
    if (!bloque) return;

    var chips = { devices: form._devices, geofences: form._geofences, metas: form._metas }[campo];
    if (chips && chips.setDisabled) chips.setDisabled(!on);

    Array.prototype.forEach.call(bloque.querySelectorAll("input,select,textarea,button"), function (el) {
      el.disabled = !on;
    });

    bloque.style.opacity = on ? "" : "0.55";
    bloque.title = on ? "" : tpT('ui.not_for_this_report');
  }

  function applyType(form, meta, typeId, item) {
    var url = meta.typeUrl.replace("{type}", typeId);
    tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      var type = res.data || {};
      var fields = type.fields || [];
      if (!Array.isArray(fields)) fields = Object.values(fields);

      // Paridad legacy (buildReportForm de Reports/create.blade): los campos
      // que no aplican al tipo se DESHABILITAN, no se ocultan. Ocultarlos
      // movia el formulario entero al cambiar de tipo y hacia creer que la
      // v2 habia perdido opciones -Metas y Zonas en lugar de direcciones
      // desaparecian segun el reporte-. Ahora siempre estan, en gris.
      ["devices", "geofences", "metas", "speed_limit", "stops", "zones_instead", "show_addresses"].forEach(function (f) {
        setFieldEnabled(form, f, fields.indexOf(f) >= 0);
      });

      // Formatos permitidos por el tipo
      var formats = type.formats || null;
      if (formats) {
        var allowed = Array.isArray(formats) ? formats : Object.keys(formats);
        var fmtSel = form.querySelector('select[name="format"]');
        Array.prototype.forEach.call(fmtSel.options, function (o) {
          o.disabled = allowed.indexOf(o.value) < 0;
        });
        if (fmtSel.selectedOptions[0] && fmtSel.selectedOptions[0].disabled && allowed.length) {
          fmtSel.value = allowed[0];
        }
        if (fmtSel._tpdd && window.tpSelect && tpSelect.refresh) {
          try {
            tpSelect.refresh(fmtSel);
          } catch (e) {}
        }
      }

      // Parámetros dinámicos
      var extra = form.querySelector("[data-rep-extra]");
      var params = type.parameters || [];
      if (!Array.isArray(params)) params = Object.values(params);
      var saved = (item && item.parameters) || {};
      extra.innerHTML = params.map(function (p) {
        return paramHtml(p, saved[p.name]);
      }).join("");
      extra.style.display = params.length ? "" : "none";
      if (window.tpSelect && tpSelect.init) {
        try {
          tpSelect.init(extra);
        } catch (e) {}
      }
    });
  }

  function paramHtml(p, savedVal) {
    var val = savedVal != null ? savedVal : p.default;
    var name = p.html_name || p.name;
    var label = '<span class="tp-form__label">' + esc(p.title || p.name) + (p.required ? " *" : "") + "</span>";
    var hint = p.description ? '<small class="tp-form__hint">' + esc(p.description) + "</small>" : "";

    switch (p.type) {
      case "checkbox":
        var checked = savedVal != null ? !!+savedVal : !!p.checked;
        return (
          '<label class="tp-choice" data-rep-param style="align-self:end;margin:0 0 8px;">' +
          '<input type="hidden" name="' + esc(name) + '" value="0">' +
          '<input type="checkbox" class="tp-choice-input" name="' + esc(name) + '" value="1"' + (checked ? " checked" : "") + ">" +
          "<span>" + esc(p.title || p.name) + "</span></label>"
        );
      case "select":
      case "multiselect":
      case "multiselect-group":
        var multiple = p.type !== "select";
        var opts = p.options || {};
        var html = '<div class="tp-form__field" data-rep-param>' + label +
          '<select class="tp-input" style="width:100%" name="' + esc(name) + '"' + (multiple ? " multiple" : "") + ">";
        var selVals = Array.isArray(val) ? val.map(String) : [String(val == null ? "" : val)];
        Object.keys(opts).forEach(function (k) {
          var v = opts[k];
          if (v && typeof v === "object") {
            html += '<optgroup label="' + esc(k) + '">';
            Object.keys(v).forEach(function (k2) {
              html += '<option value="' + esc(k2) + '"' + (selVals.indexOf(String(k2)) >= 0 ? " selected" : "") + ">" + esc(v[k2]) + "</option>";
            });
            html += "</optgroup>";
          } else {
            html += '<option value="' + esc(k) + '"' + (selVals.indexOf(String(k)) >= 0 ? " selected" : "") + ">" + esc(v) + "</option>";
          }
        });
        return html + "</select>" + hint + "</div>";
      case "text":
        return '<div class="tp-form__field" data-rep-param style="grid-column:1/-1;">' + label +
          '<textarea name="' + esc(name) + '" rows="2">' + esc(val == null ? "" : val) + "</textarea>" + hint + "</div>";
      default: // integer | string | datetime
        return '<div class="tp-form__field" data-rep-param>' + label +
          '<input type="text" name="' + esc(name) + '" value="' + esc(val == null ? "" : val) + '">' + hint + "</div>";
    }
  }

  /* ── Payload compartido Generar/Guardar ── */
  function collectPayload(dialog) {
    var form = dialog.querySelector("form.tp-report-form");
    var range = form._range;
    var payload = {
      title: form.querySelector('input[name="title"]').value.trim(),
      type: form.querySelector('select[name="type"]').value,
      format: form.querySelector('select[name="format"]').value,
      // El periodo elegido viaja junto a las fechas: al reabrir un reporte
      // guardado se vuelve a ver "Esta semana" y no unas fechas sueltas.
      period: (form.querySelector('[data-rep-period]') || {}).value || "",
      date_from: range.from,
      from_time: range.fromTime,
      date_to: range.to,
      to_time: range.toTime,
      speed_limit: (form.querySelector('input[name="speed_limit"]') || {}).value || "",
      stops: (form.querySelector('select[name="stops"]') || {}).value || "",
      show_addresses: form.querySelector('input[name="show_addresses"]').checked ? 1 : 0,
      zones_instead: form.querySelector('input[name="zones_instead"]').checked ? 1 : 0,
      skip_blank_results: form.querySelector('input[name="skip_blank_results"]').checked ? 1 : 0,
      send_to_email: form._emails
        .filter(function (e) {
          return e && e.trim();
        })
        .join(";"),
      daily: form.querySelector('input[name="daily"]').checked ? 1 : 0,
      daily_time: form.querySelector('[name="daily_time"]').value || "00:00",
      weekly: form.querySelector('input[name="weekly"]').checked ? 1 : 0,
      weekly_time: form.querySelector('[name="weekly_time"]').value || "00:00",
      monthly: form.querySelector('input[name="monthly"]').checked ? 1 : 0,
      monthly_time: form.querySelector('[name="monthly_time"]').value || "00:00",
    };

    var idEl = form.querySelector('input[name="id"]');
    if (idEl) payload.id = idEl.value;

    var devIds = form._devices.getIds();
    payload.devices = devIds.map(Number);
    payload.selected_devices = ["s;0;"].concat(devIds.map(function (i) {
      return "id;1;" + i;
    }));
    payload.geofences = form._geofences.getIds().map(Number);
    payload.metas = form._metas.getIds();

    // Pills de días: solo si el usuario desmarcó alguno
    var pills = form.querySelectorAll("[data-rep-day]");
    var onDays = [];
    Array.prototype.forEach.call(pills, function (p) {
      if (p.classList.contains("is-on")) onDays.push(p.getAttribute("data-rep-day"));
    });
    if (onDays.length && onDays.length < 7) payload.period_weekdays = onDays;

    // Franja horaria del slider: rango completo (00:00–24:00) = sin filtro
    if (form._tsGet) {
      var ts = form._tsGet();
      if (ts.from > 0 || ts.to < 1440) {
        payload.period_time_from = fmtMin(ts.from);
        payload.period_time_to = ts.to >= 1440 ? "23:59" : fmtMin(ts.to);
      }
    }

    // send_to_ftp es la URL ftp://user:pass@host:puerto (string, como el
    // legacy: el helper copia data['send_to_ftp'] → report.ftp tal cual).
    // Antes era un checkbox 1/0 y editar DESTRUÍA la URL guardada.
    var ftpEl = form.querySelector('input[name="send_to_ftp"]');
    if (ftpEl) payload.send_to_ftp = (ftpEl.value || "").trim();

    // Día concreto del schedule (solo si su checkbox está activo)
    var wdEl = form.querySelector('select[name="weekly_day"]');
    payload.weekly_day = payload.weekly && wdEl ? wdEl.value : "";
    var mdEl = form.querySelector('select[name="monthly_day"]');
    payload.monthly_day = payload.monthly && mdEl ? mdEl.value : "";

    // Parámetros dinámicos
    Array.prototype.forEach.call(form.querySelectorAll("[data-rep-param] [name]"), function (el) {
      var n = el.name;
      if (el.type === "hidden") {
        if (payload[n.replace("[]", "")] == null) payload[n] = el.value;
        return;
      }
      if (el.type === "checkbox") {
        payload[n] = el.checked ? 1 : 0;
        return;
      }
      if (el.multiple) {
        payload[n.replace("[]", "")] = Array.prototype.map.call(el.selectedOptions, function (o) {
          return o.value;
        });
        return;
      }
      payload[n] = el.value;
    });

    return payload;
  }

  /**
   * Pide el reporte como lo pide el legacy: un POST normal en ESTA pestaña.
   * La ruta responde con Content-Disposition: attachment, asi que el
   * navegador descarga el fichero y la pagina -y el modal- se quedan donde
   * estaban. Nada de window.open: una pestaña nueva solo sirve para que el
   * reporte se abra en vez de bajarse.
   *
   * Va por POST y no por GET a proposito: con muchos vehiculos elegidos la
   * querystring se pasa del limite del servidor.
   */
  function postDescarga(url, campos) {
    var form = document.createElement("form");
    form.method = "POST";
    form.action = url;
    form.style.display = "none";

    function campo(nombre, valor) {
      var i = document.createElement("input");
      i.type = "hidden";
      i.name = nombre;
      i.value = valor;
      form.appendChild(i);
    }

    campo("_token", tpModal.csrf());

    Object.keys(campos).forEach(function (k) {
      var v = campos[k];
      if (Array.isArray(v)) {
        var nm = /\[\]$/.test(k) ? k : k + "[]";
        v.forEach(function (x) {
          campo(nm, x);
        });
      } else if (v != null && v !== "") {
        campo(k, v);
      }
    });

    document.body.appendChild(form);
    form.submit();
    // El navegador ya tiene el envio en marcha; el form sobra.
    setTimeout(function () {
      if (form.parentNode) form.parentNode.removeChild(form);
    }, 0);
  }

  function doGenerate(dialog) {
    var payload = collectPayload(dialog);
    delete payload.id;

    // Validar ANTES de enviar. La ruta legacy lanza ValidationException
    // si no hay dispositivos, y el envio se lleva la pagina por delante:
    // el usuario acabaria en un error sin saber que le faltaba elegir.
    if (!payload.devices || !payload.devices.length) {
      tpModal.toast && tpModal.toast(tpT('ui.select_at_least_one_vehicle'), "warning");
      return;
    }
    if (!payload.type) {
      tpModal.toast && tpModal.toast(tpT('ui.pick_a_type'), "warning");
      return;
    }

    payload.generate = 1;
    // Sin `view`: con el, la ruta devuelve $report->view() -la vista, sin
    // cabecera de descarga- y el reporte se abria en una pestaña. Sin el
    // entra por $report->download(), que es lo que hace el legacy. Ademas
    // view() no tiene caso para 'csv', asi que ese formato salia en blanco.

    // Un reporte grande tarda, y al descargarse no cambia nada en pantalla:
    // sin este aviso el usuario vuelve a pulsar Generar.
    if (tpModal.toast) tpModal.toast(tpT("ui.generating_report"), "info");

    postDescarga(META.generateUrl, payload);
  }

  function doSave(dialog, id) {
    var payload = collectPayload(dialog);
    if (id) payload.id = id;

    tpModal.setBusy(dialog, true);
    tpModal
      .fetchJSON(URLS.store, { method: "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(dialog, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          tpModal.showFieldErrors(dialog, errors);
          var first = Object.keys(errors)[0];
          tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : tpT('ui.check_the_fields'), "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast((res.data && res.data.message) || tpT('ui.save_failed'), "error");
          return;
        }
        tpModal.toast("Reporte guardado.", "success");
        tpModal.close(dialog);
        loadTab();
      })
      .catch(function () {
        tpModal.setBusy(dialog, false);
        tpModal.toast(tpT('ui.network_error'), "error");
      });
  }

  /* ================= Página (tabs + tablas) ========================== */

  var savedRows = document.getElementById("tp-rep-saved-rows");
  var logsRows = document.getElementById("tp-rep-logs-rows");
  var searchEl = document.getElementById("tp-rep-search");
  var typeFilter = document.getElementById("tp-rep-type-filter");
  var limitSel = document.getElementById("tp-rep-limit");
  var rangeEl = document.getElementById("tp-rep-range");
  var prevBtn = document.getElementById("tp-rep-prev");
  var nextBtn = document.getElementById("tp-rep-next");
  var addBtn = document.getElementById("tp-rep-add");

  var state = {
    tab: "saved",
    saved: { page: 1, last: 1, total: 0 },
    logs: { page: 1, last: 1, total: 0 },
    per: 20,
    s: "",
    type: "",
  };
  var timer = null;

  function kebab(items) {
    if (!items) return "";
    return (
      '<div class="tp-svc-kebab">' +
      '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
      "</button>" +
      '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>"
    );
  }

  function updatePager(t) {
    var st = state[t];
    var from = st.total ? (st.page - 1) * state.per + 1 : 0;
    var to = Math.min(st.page * state.per, st.total);
    rangeEl.textContent = from + "–" + to + " / " + st.total;
    prevBtn.disabled = st.page <= 1;
    nextBtn.disabled = st.page >= st.last;
  }

  function loadSaved() {
    var q = URLS.saved + "?s=" + encodeURIComponent(state.s) + "&type=" + encodeURIComponent(state.type) +
      "&page=" + state.saved.page + "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        savedRows.innerHTML = '<tr><td colspan="7" class="tp-svc-table__empty">' + tpT('ui.load_failed') + '</td></tr>';
        return;
      }
      state.saved.last = res.data.last || 1;
      state.saved.total = res.data.total || 0;
      var items = res.data.items || [];
      savedRows.innerHTML = items.length
        ? items.map(function (r) {
            var acts = "";
            acts += '<button type="button" role="menuitem" data-rep-open="' + r.id + '">' +
              '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg> ' + tpT('ui.generate_now') + '</button>';
            if (canCreate)
              acts += '<button type="button" role="menuitem" data-rep-edit="' + r.id + '">' +
                '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> ' + tpT('global.edit') + '</button>';
            if (canRemove)
              acts += '<button type="button" role="menuitem" class="is-danger" data-rep-del="' + r.id + '">' +
                '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> ' + tpT('global.delete') + '</button>';
            return (
              '<tr data-rep-row="' + r.id + '" data-format="' + esc(r.format.toLowerCase()) + '" style="cursor:pointer;">' +
              "<td>" + esc(r.title || "–") + "</td>" +
              "<td>" + esc(r.type_label) + "</td>" +
              "<td>" + esc(r.format) + "</td>" +
              "<td>" + r.devices_count + "</td>" +
              "<td>" + r.geofences_count + "</td>" +
              "<td>" + esc(r.schedule || "–") + "</td>" +
              '<td class="tp-svc-table__actions">' + kebab(acts) + "</td></tr>"
            );
          }).join("")
        : '<tr><td colspan="7" class="tp-svc-table__empty">' + tpT('front.no_data') + '</td></tr>';
      updatePager("saved");
    });
  }

  function loadLogs() {
    var q = URLS.logs + "?s=" + encodeURIComponent(state.s) + "&type=" + encodeURIComponent(state.type) +
      "&page=" + state.logs.page + "&per_page=" + state.per;
    tpModal.fetchJSON(q, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) {
        logsRows.innerHTML = '<tr><td colspan="6" class="tp-svc-table__empty">' + tpT('ui.load_failed') + '</td></tr>';
        return;
      }
      state.logs.last = res.data.last || 1;
      state.logs.total = res.data.total || 0;
      var items = res.data.items || [];
      logsRows.innerHTML = items.length
        ? items.map(function (r) {
            var badge = r.is_send
              ? '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#dcfce7;color:#15803d;">' + tpT('ui.yes') + '</span>'
              : '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#f1f5f9;color:#64748b;">No</span>';
            var acts = '<button type="button" role="menuitem" data-log-dl="' + esc(r.download) + '">' +
              '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg> Descargar</button>';
            if (canRemove)
              acts += '<button type="button" role="menuitem" class="is-danger" data-log-del="' + r.id + '">' +
                '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> ' + tpT('global.delete') + '</button>';
            return (
              '<tr data-log-row="' + esc(r.download) + '" style="cursor:pointer;">' +
              "<td>" + esc(r.title || "–") + "</td>" +
              "<td>" + esc(r.type_label) + "</td>" +
              "<td>" + esc(r.format) + "</td>" +
              "<td>" + esc(r.size) + "</td>" +
              "<td>" + badge + "</td>" +
              '<td class="tp-svc-table__actions">' + kebab(acts) + "</td></tr>"
            );
          }).join("")
        : '<tr><td colspan="6" class="tp-svc-table__empty">' + tpT('front.no_data') + '</td></tr>';
      updatePager("logs");
    });
  }

  function loadTab() {
    if (state.tab === "saved") loadSaved();
    else loadLogs();
  }

  /**
   * Una definicion guardada, con la forma que tendria si se hubiese abierto
   * el modal de edicion y se hubiese pulsado Generar. Mismos nombres de
   * campo que collectPayload(): es el contrato que espera la ruta legacy.
   *
   * Quedan fuera a proposito los campos de programacion y de envio (daily,
   * weekly, monthly, send_to_email, send_to_ftp): no cambian el contenido
   * del reporte, y mandarlos activa la validacion extra de validateInput()
   * -si hay programacion, exige correo- sin que sirva para nada aqui.
   */
  function payloadDesdeItem(it) {
    var hoy = ymd(new Date());
    var devIds = (it.device_ids || []).map(Number);

    var p = {
      title: it.title || "",
      type: it.type,
      format: it.format,
      period: it.period || "",
      date_from: it.date_from || hoy,
      from_time: it.from_time || "00:00",
      date_to: it.date_to || hoy,
      to_time: it.to_time || "23:59",
      speed_limit: it.speed_limit == null ? "" : it.speed_limit,
      stops: it.stops || "",
      show_addresses: it.show_addresses ? 1 : 0,
      zones_instead: it.zones_instead ? 1 : 0,
      skip_blank_results: it.skip_blank_results ? 1 : 0,
      devices: devIds,
      selected_devices: ["s;0;"].concat(devIds.map(function (i) {
        return "id;1;" + i;
      })),
      geofences: (it.geofence_ids || []).map(Number),
      metas: it.metas || [],
      generate: 1,
    };

    var dias = it.period_weekdays || [];
    if (dias.length && dias.length < 7) p.period_weekdays = dias;
    if (it.period_time_from) p.period_time_from = it.period_time_from;
    if (it.period_time_to) p.period_time_to = it.period_time_to;

    // Parametros propios del tipo, tal y como se guardaron.
    var params = it.parameters || {};
    Object.keys(params).forEach(function (k) {
      if (p[k] == null) p[k] = params[k];
    });

    return p;
  }

  // Abrir una definicion guardada es generarla: tambien se descarga.
  function openSaved(id) {
    if (tpModal.toast) tpModal.toast(tpT("ui.generating_report"), "info");

    tpModal
      .fetchJSON(URLS.item.replace("{id}", id), { method: "GET" })
      .then(function (r) {
        var it = r.ok && r.data && r.data.ok ? r.data.item : null;
        if (!it) throw new Error("item");
        postDescarga(META.generateUrl, payloadDesdeItem(it));
      })
      .catch(function () {
        tpModal.toast(tpT("ui.load_failed"), "error");
      });
  }

  // Tabs
  Array.prototype.forEach.call(document.querySelectorAll("[data-rep-tab]"), function (btn) {
    btn.addEventListener("click", function () {
      state.tab = btn.getAttribute("data-rep-tab");
      Array.prototype.forEach.call(document.querySelectorAll("[data-rep-tab]"), function (b) {
        var on = b === btn;
        b.classList.toggle("is-active", on);
        b.style.borderBottomColor = on ? "var(--brand-500,#2e63d9)" : "transparent";
        b.style.color = on ? "var(--brand-500,#2e63d9)" : "";
        b.style.fontWeight = on ? "600" : "500";
      });
      Array.prototype.forEach.call(document.querySelectorAll("[data-rep-panel]"), function (p) {
        p.hidden = p.getAttribute("data-rep-panel") !== state.tab;
      });
      loadTab();
    });
  });

  searchEl.addEventListener("input", function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      state.s = searchEl.value.trim();
      state[state.tab].page = 1;
      loadTab();
    }, 300);
  });
  typeFilter.addEventListener("change", function () {
    state.type = typeFilter.value;
    state[state.tab].page = 1;
    loadTab();
  });
  limitSel.addEventListener("change", function () {
    state.per = parseInt(limitSel.value, 10) || 20;
    state[state.tab].page = 1;
    loadTab();
  });
  prevBtn.addEventListener("click", function () {
    var st = state[state.tab];
    if (st.page > 1) {
      st.page--;
      loadTab();
    }
  });
  nextBtn.addEventListener("click", function () {
    var st = state[state.tab];
    if (st.page < st.last) {
      st.page++;
      loadTab();
    }
  });
  if (addBtn)
    addBtn.addEventListener("click", function () {
      openReportModal(null);
    });

  savedRows.addEventListener("click", function (e) {
    var open = e.target.closest("[data-rep-open]");
    if (open) {
      openSaved(open.getAttribute("data-rep-open"));
      return;
    }
    var edit = e.target.closest("[data-rep-edit]");
    if (edit) {
      openReportModal(edit.getAttribute("data-rep-edit"));
      return;
    }
    var del = e.target.closest("[data-rep-del]");
    if (del) {
      var rid = del.getAttribute("data-rep-del");
      tpModal.confirm({ title: tpT('ui.delete_report'), message: tpT('ui.confirm_delete_report_def'), danger: true, confirmLabel: tpT('global.delete') })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.destroy.replace("{id}", rid), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Eliminado.", "success");
              loadSaved();
            } else tpModal.toast(tpT('ui.delete_failed'), "error");
          });
        });
      return;
    }
    if (e.target.closest(".tp-svc-kebab")) return;
    // La fila abre la edicion, no genera: el legacy tampoco genera al pulsar
    // en la fila, y un click despistado se bajaria el reporte entero.
    var row = e.target.closest("[data-rep-row]");
    if (row && canCreate) openReportModal(row.getAttribute("data-rep-row"));
  });

  logsRows.addEventListener("click", function (e) {
    var dl = e.target.closest("[data-log-dl]");
    if (dl) {
      // attachment: el navegador baja el fichero y la pagina no se mueve.
      window.location.href = dl.getAttribute("data-log-dl");
      return;
    }
    var del = e.target.closest("[data-log-del]");
    if (del) {
      var lid = del.getAttribute("data-log-del");
      tpModal.confirm({ title: tpT('ui.delete_log'), message: tpT('ui.confirm_delete_sent_report'), danger: true, confirmLabel: tpT('global.delete') })
        .then(function (okGo) {
          if (!okGo) return;
          tpModal.fetchJSON(URLS.logDestroy.replace("{id}", lid), { method: "DELETE" }).then(function (res) {
            if (res.ok && res.data && res.data.ok) {
              tpModal.toast("Eliminado.", "success");
              loadLogs();
            } else tpModal.toast(tpT('ui.delete_failed'), "error");
          });
        });
      return;
    }
    if (e.target.closest(".tp-svc-kebab")) return;
    var row = e.target.closest("[data-log-row]");
    if (row) window.location.href = row.getAttribute("data-log-row");
  });

  // Habilitadores del schedule (delegado: el modal se crea después).
  // Un checkbox puede habilitar varios controles (hora + select de día).
  document.addEventListener("change", function (e) {
    if (e.target.matches && e.target.matches('input[type="checkbox"][id^="tp-rep-"]')) {
      var form = e.target.closest("form.tp-report-form");
      if (!form) return;
      Array.prototype.forEach.call(form.querySelectorAll('[data-enable="' + e.target.id + '"]'), function (t) {
        t.disabled = !e.target.checked;
        if (t.tagName === "SELECT" && t._tpdd && window.tpSelect && tpSelect.refresh) {
          try {
            tpSelect.refresh(t);
          } catch (err) {}
        }
      });
    }
  });

  /* ── Init ── */
  loadMeta().then(function (meta) {
    var html = '<option value="">' + tpT('ui.all_types') + '</option>';
    (meta.templates || []).forEach(function (t) {
      html += '<option value="' + t.id + '">' + esc(t.title) + "</option>";
    });
    typeFilter.innerHTML = html;
    if (window.tpSelect && tpSelect.refresh) {
      try {
        tpSelect.refresh(typeFilter);
      } catch (e) {}
    }
  });
  loadSaved();

  if (root.getAttribute("data-open-modal") === "1" && canCreate) {
    openReportModal(null);
  }
})();
