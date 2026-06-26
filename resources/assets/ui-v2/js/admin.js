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

  function init() {
    bind();
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
