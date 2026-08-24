/* =====================================================
 * TalentusPro · Admin & Integrations · Phase 9
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-admin");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");

  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    // Fallback
    var ta = document.createElement("textarea");
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand("copy");
    } catch (e) {}
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function renderApi(api) {
    $("#api-base").textContent = api.base_url || "—";
    if (api.has_token) {
      $("#api-status").textContent = "Activo";
      $("#api-status").classList.remove("tp-pill--muted");
      $("#api-status").classList.add("tp-pill--success");
      $("#api-token").innerHTML =
        '<span class="tp-admin__mono">••••••••••••</span> <span class="tp-text-muted tp-text-xs">(oculto por seguridad)</span>';
    } else {
      $("#api-status").textContent = "Sin token";
      $("#api-status").classList.add("tp-pill--muted");
      $("#api-token").innerHTML =
        '<span class="tp-text-muted">Inicia sesión vía <code>' +
        escapeHtml(api.login_url) +
        "</code> para obtener tu token.</span>";
    }
    $("#api-expires").textContent =
      api.expires_at || "Sin expiración configurada";
    $("#api-docs").href = api.docs_url || "/apidoc/";
  }

  function renderWebhooks(wh) {
    $("#wh-count").textContent = wh.count + " total";
    $("#wh-active").textContent = wh.active;
    $("#wh-total").textContent = wh.count;
    $("#wh-types").textContent = (wh.by_type || []).length;

    var list = $("#wh-list");
    if (!wh.list || !wh.list.length) {
      list.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:16px;">No tienes forwards configurados.</div>';
      return;
    }
    list.innerHTML = wh.list
      .map(function (w) {
        return (
          '<div class="tp-admin__list-item">' +
          '<span class="tp-admin__list-item-dot tp-admin__list-item-dot--' +
          (w.active ? "on" : "off") +
          '"></span>' +
          '<span class="tp-admin__list-item-title" title="' +
          escapeHtml(w.title) +
          '">' +
          escapeHtml(w.title || "(sin título)") +
          "</span>" +
          '<span class="tp-admin__list-item-type">' +
          escapeHtml(w.type || "—") +
          "</span>" +
          "</div>"
        );
      })
      .join("");
  }

  function renderSso(sso) {
    if (sso.azure_enabled) {
      $("#sso-status").textContent = "Habilitado";
      $("#sso-status").classList.remove("tp-pill--muted");
      $("#sso-status").classList.add("tp-pill--success");
      $("#sso-hint").textContent =
        "Los usuarios pueden iniciar sesión con su cuenta Microsoft.";
    } else {
      $("#sso-status").textContent = "Deshabilitado";
      $("#sso-hint").textContent =
        "Configura AZURE_TENANT_ID en tu .env para habilitar.";
    }
    $("#sso-tenant").textContent = sso.tenant_id_set
      ? "••••••••-••••-••••-••••-••••••••••••"
      : "No configurado";
  }

  function renderBranding(br) {
    $("#br-name").textContent = br.app_name || "—";
    $("#br-color").textContent = br.theme_color || "—";
    $("#br-swatch").style.background = br.theme_color || "transparent";
    if (br.logo) {
      $("#br-logo").innerHTML =
        '<img src="' +
        escapeHtml(br.logo) +
        '" alt="Logo" style="max-height:32px; max-width:160px; object-fit:contain;">';
    } else {
      $("#br-logo").innerHTML =
        '<span class="tp-text-muted">— Sin logo personalizado —</span>';
    }
  }

  function renderCounts(counts) {
    if (!counts) return;
    $("#counts-panel").hidden = false;
    $("#cnt-users").textContent = (counts.users || 0).toLocaleString();
    $("#cnt-devices").textContent = (counts.devices || 0).toLocaleString();
  }

  function renderQuick(quick) {
    var grid = $("#quick-grid");
    if (!quick || !quick.length) {
      grid.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">Sin accesos disponibles.</div>';
      return;
    }
    grid.innerHTML = quick
      .map(function (q) {
        var badge =
          q.group === "admin"
            ? "admin"
            : q.group === "manager"
              ? "manager"
              : "me";
        var label =
          q.group === "admin"
            ? "Admin"
            : q.group === "manager"
              ? "Manager"
              : "Tú";
        return (
          '<a class="tp-admin__quick-link" href="' +
          escapeHtml(q.url) +
          '" target="_blank" rel="noopener">' +
          '<span class="tp-admin__quick-link-badge tp-admin__quick-link-badge--' +
          badge +
          '">' +
          label +
          "</span>" +
          '<span class="tp-admin__quick-link-title">' +
          escapeHtml(q.label) +
          "</span>" +
          '<span class="tp-admin__quick-link-desc">' +
          escapeHtml(q.desc) +
          "</span>" +
          "</a>"
        );
      })
      .join("");
  }

  function load() {
    return fetch(DATA_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.ok) throw new Error("load_failed");
        renderApi(j.api || {});
        renderWebhooks(
          j.webhooks || { count: 0, active: 0, by_type: [], list: [] },
        );
        renderSso(j.sso || {});
        renderBranding(j.branding || {});
        renderCounts(j.counts);
        renderQuick(j.quick || []);
      })
      .catch(function (e) {
        $("#quick-grid").innerHTML =
          '<div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">Error al cargar: ' +
          escapeHtml(e.message || e) +
          "</div>";
      });
  }

  function bind() {
    // Copy buttons
    document.addEventListener("click", function (e) {
      var b = e.target.closest("[data-copy]");
      if (!b) return;
      var target = document.querySelector(b.getAttribute("data-copy"));
      if (!target) return;
      copyText(target.textContent || "").then(function () {
        var old = b.textContent;
        b.textContent = "✓ Copiado";
        setTimeout(function () {
          b.textContent = old;
        }, 1500);
      });
    });
  }

  /* =====================================================
   * Cumplimiento regulatorio
   * -----------------------------------------------------
   * Lee el resumen precalculado por `integrations:status`. La pantalla no
   * consulta las colas de reenvio: son cientos de miles de filas y los jobs
   * de envio estan trabajando sobre ellas.
   * ===================================================== */

  var CMP_URL = "/ui/v2/api/integrations/status";
  var CMP_DATOS = null;

  var CMP_ETIQUETA = {
    ok: "Al día",
    curso: "En curso",
    aviso: "Con atraso",
    critico: "Crítico",
  };

  function cmpEsc(v) {
    return window.tpModal ? tpModal.escapeHtml(v) : String(v == null ? "" : v);
  }

  function cmpCargar(cb) {
    if (!window.tpModal) return;

    window.tpModal
      .fetchJSON(CMP_URL, { method: "GET" })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          var lista = document.getElementById("cmp-list");
          if (lista) lista.innerHTML = '<div class="tp-text-muted" style="padding:12px;">No disponible.</div>';
          return;
        }
        CMP_DATOS = res.data;
        cmpPintarTarjeta();
        if (typeof cb === "function") cb();
      })
      .catch(function () {
        var lista = document.getElementById("cmp-list");
        if (lista) lista.innerHTML = '<div class="tp-text-error" style="padding:12px;">Error de red.</div>';
      });
  }

  function cmpPintarTarjeta() {
    var d = CMP_DATOS;
    if (!d) return;

    var ok = 0, aviso = 0, critico = 0, vehiculos = 0;
    (d.summary || []).forEach(function (s2) {
      ok += s2.ok + s2.curso;
      aviso += s2.aviso;
      critico += s2.critico;
      vehiculos += s2.devices;
    });

    function poner(id, valor) {
      var el = document.getElementById(id);
      if (el) el.textContent = valor;
    }

    poner("cmp-ok", ok);
    poner("cmp-aviso", aviso);
    poner("cmp-critico", critico);
    poner("cmp-count", vehiculos + " obligados");
    poner("cmp-calculado", d.computed_at ? "Calculado " + d.computed_at : "");

    var lista = document.getElementById("cmp-list");
    if (!lista) return;

    if (!(d.summary || []).length) {
      lista.innerHTML =
        '<div class="tp-text-muted" style="padding:12px;">Ningún vehículo tiene integraciones activas.</div>';
      return;
    }

    lista.innerHTML = d.summary
      .map(function (s2) {
        var mal = s2.aviso + s2.critico;
        var color = s2.critico ? "danger" : mal ? "warn" : "ok";
        return (
          '<div class="tp-admin__list-item">' +
          '<span class="tp-admin__list-item-dot tp-admin__list-item-dot--' +
          (s2.critico ? "off" : "on") + '"></span>' +
          '<span class="tp-admin__list-item-title">' + cmpEsc(s2.integration) +
          ' <span class="tp-text-xs tp-text-muted">· ' + s2.devices + " veh. · " +
          s2.pending.toLocaleString() + " en cola</span></span>" +
          '<span class="tp-cmp-chip tp-cmp-chip--' + color + '">' +
          (mal ? mal + " con atraso" : "al día") +
          "</span></div>"
        );
      })
      .join("");
  }

  function cmpDetalle(soloFallos) {
    if (!window.tpModal) return;

    var url = CMP_URL + (soloFallos ? "?failing=1" : "");

    window.tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      var d = (res.data && res.data.ok) ? res.data : null;

      if (!d) {
        window.tpModal.toast("No se pudo cargar el detalle.", "error");
        return;
      }

      var filas = d.items || [];

      var cuerpo =
        '<p class="tp-text-xs tp-text-muted" style="margin:0 0 10px;">' +
        "Se considera <b>con atraso</b> a partir de " + d.thresholds.aviso +
        " min en cola, y <b>crítico</b> a partir de " + d.thresholds.critico + " min. " +
        (d.computed_at ? "Resumen calculado " + cmpEsc(d.computed_at) + "." : "") +
        "</p>";

      if (!filas.length) {
        cuerpo +=
          '<p class="tp-text-sm tp-text-muted">' +
          (soloFallos ? "Ningún vehículo con atraso." : "Sin datos.") +
          "</p>";
      } else {
        cuerpo +=
          '<div style="max-height:60vh;overflow:auto;"><table class="tp-table"><thead><tr>' +
          "<th>Vehículo</th><th>Integración</th><th>En cola</th><th>Más antigua</th><th>Estado</th>" +
          "</tr></thead><tbody>" +
          filas
            .map(function (f) {
              return (
                "<tr><td>" + cmpEsc(f.name) +
                (f.plate ? ' <span class="tp-text-xs tp-text-muted">' + cmpEsc(f.plate) + "</span>" : "") +
                "</td><td>" + cmpEsc(f.integration) +
                '</td><td style="text-align:right;">' + f.pending.toLocaleString() +
                "</td><td>" + cmpEsc(f.oldest_at || "—") +
                '</td><td><span class="tp-cmp-chip tp-cmp-chip--' +
                (f.status === "critico" ? "danger" : f.status === "aviso" ? "warn" : "ok") +
                '">' + (CMP_ETIQUETA[f.status] || f.status) + "</span></td></tr>"
              );
            })
            .join("") +
          "</tbody></table></div>";
      }

      // Los fallos de los jobs: una cola que no drena suele venir de aqui
      var fallos = d.failed_jobs || {};
      var nombres = Object.keys(fallos);
      if (nombres.length) {
        cuerpo +=
          '<p class="tp-text-xs tp-text-muted" style="margin:12px 0 4px;"><b>Trabajos fallidos recientes</b></p>' +
          nombres
            .map(function (n) {
              return '<span class="tp-cmp-chip tp-cmp-chip--danger" style="margin-right:6px;">' +
                cmpEsc(n) + ": " + fallos[n] + "</span>";
            })
            .join("");
      }

      window.tpModal.open({
        title: soloFallos ? "Cumplimiento · solo los que fallan" : "Cumplimiento regulatorio",
        size: "xl",
        body: cuerpo,
        buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      });
    });
  }

  function cmpBind() {
    var ver = document.getElementById("cmp-ver");
    if (ver) ver.addEventListener("click", function () { cmpDetalle(false); });

    var fallos = document.getElementById("cmp-fallos");
    if (fallos) fallos.addEventListener("click", function () { cmpDetalle(true); });
  }

  function init() {
    bind();
    load();
    cmpBind();
    cmpCargar();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
