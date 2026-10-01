/* =====================================================
 * TalentusPro · tp-modal · Phase 11
 * Native <dialog> modal + toast helpers.
 * Global: window.tpModal
 *   open({ title, sub, body, size, buttons, onMount, onClose })
 *   confirm({ title, message, danger }) -> Promise<bool>
 *   alert({ title, message })            -> Promise<void>
 *   toast(message, type='info')
 *   close(modal?)
 * ===================================================== */
(function () {
  "use strict";

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

  var ICON_CLOSE =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>';

  /** Dialogo abierto mas alto (el ultimo promovido al top layer). */
  function topDialog() {
    var abiertos = document.querySelectorAll("dialog[open]");
    return abiertos.length ? abiertos[abiertos.length - 1] : null;
  }

  function ensureToastHost() {
    var host = document.querySelector(".tp-toast-host");
    if (!host) {
      host = document.createElement("div");
      host.className = "tp-toast-host";
      host.setAttribute("aria-live", "polite");
      document.body.appendChild(host);
    }

    // Un <dialog> abierto con showModal() vive en el "top layer" del
    // navegador: queda por encima de CUALQUIER z-index del flujo normal, asi
    // que un host colgado del <body> sale SIEMPRE detras del modal (pasaba en
    // todas las pantallas). Se cuelga del dialogo mas alto; como el host es
    // position:fixed y el dialogo ya no tiene transform una vez abierto, el
    // overflow:hidden del modal no lo recorta.
    var padre = topDialog() || document.body;
    if (host.parentNode !== padre) padre.appendChild(host);

    return host;
  }

  /**
   * Render and open a modal.
   * @param {Object} opts
   * @returns {HTMLDialogElement}
   */
  var _ultimaApertura = { titulo: null, t: 0 };

  function open(opts) {
    opts = opts || {};

    // Doble clic impaciente: los modales que cargan por AJAX tardan un par
    // de segundos y sin indicador es facil pulsar dos veces. Cada pulsacion
    // abria su propio dialogo, el segundo encima del primero, y al cerrarlo
    // parecia que el boton "no habia funcionado". Se ignora la repeticion.
    var titulo = String(opts.title || "");
    var ahora = Date.now();
    if (titulo && titulo === _ultimaApertura.titulo && ahora - _ultimaApertura.t < 600) {
      return null;
    }
    _ultimaApertura = { titulo: titulo, t: ahora };

    var dlg = document.createElement("dialog");
    dlg.className =
      "tp-modal" +
      (opts.size === "xl"
        ? " tp-modal--xl"
        : opts.size === "lg"
          ? " tp-modal--lg"
          : opts.size === "sm"
            ? " tp-modal--sm"
            : "") +
      // Clase extra opcional para restyles scopeados por consumidor.
      (opts.className ? " " + opts.className : "");

    var buttonsHtml = "";
    (opts.buttons || []).forEach(function (b, idx) {
      buttonsHtml +=
        '<button type="button" class="tp-btn tp-btn--' +
        (b.variant || "ghost") +
        ' tp-btn--sm" data-tp-btn="' +
        idx +
        '"' +
        (b.disabled ? " disabled" : "") +
        ">" +
        escapeHtml(b.label) +
        "</button>";
    });

    dlg.innerHTML =
      '<div class="tp-modal__inner">' +
      '<header class="tp-modal__head">' +
      "<div>" +
      '<h2 class="tp-modal__title">' +
      escapeHtml(opts.title || "") +
      "</h2>" +
      (opts.sub
        ? '<div class="tp-modal__sub">' + escapeHtml(opts.sub) + "</div>"
        : "") +
      "</div>" +
      '<button type="button" class="tp-modal__close" aria-label="Cerrar" data-tp-close>' +
      ICON_CLOSE +
      "</button>" +
      "</header>" +
      '<div class="tp-modal__body" data-tp-body></div>' +
      (buttonsHtml
        ? '<footer class="tp-modal__foot" data-tp-foot>' +
          buttonsHtml +
          "</footer>"
        : "") +
      "</div>";

    var body = dlg.querySelector("[data-tp-body]");
    if (typeof opts.body === "string") {
      body.innerHTML = opts.body;
    } else if (opts.body instanceof Node) {
      body.appendChild(opts.body);
    }

    document.body.appendChild(dlg);

    // Close handlers
    dlg.querySelector("[data-tp-close]").addEventListener("click", function () {
      close(dlg);
    });
    dlg.addEventListener("click", function (e) {
      // click on backdrop (the dialog itself, not inner content).
      // opts.static = true → no cerrar (formularios largos: evita perder datos)
      if (e.target === dlg && !opts.static) close(dlg);
    });
    dlg.addEventListener("close", function () {
      if (typeof opts.onClose === "function") {
        try {
          opts.onClose(dlg.returnValue);
        } catch (e) {}
      }
      // Defer removal so any post-close animation completes
      setTimeout(function () {
        if (dlg.parentNode) dlg.parentNode.removeChild(dlg);
      }, 50);
    });

    // Button handlers
    (opts.buttons || []).forEach(function (b, idx) {
      var btn = dlg.querySelector('[data-tp-btn="' + idx + '"]');
      if (!btn) return;
      btn.addEventListener("click", function () {
        // Recordar el botón que disparó la acción → setBusy gira SOLO este.
        dlg._tpActiveBtn = btn;
        if (typeof b.onClick === "function") {
          var ret = b.onClick(dlg, btn);
          if (ret === false) return;
        }
        if (b.close !== false) close(dlg, b.value);
      });
    });

    // Fallback for browsers w/o <dialog>
    if (typeof dlg.showModal === "function") {
      dlg.showModal();
    } else {
      dlg.setAttribute("open", "");
    }

    if (typeof opts.onMount === "function") {
      try {
        opts.onMount(dlg);
      } catch (e) {}
    }

    return dlg;
  }

  function close(dlg, value) {
    if (!dlg) {
      // close top-most
      var all = document.querySelectorAll(".tp-modal[open]");
      dlg = all[all.length - 1];
    }
    if (!dlg) return;

    // Si el host de avisos estaba dentro de este modal, vuelve al body antes
    // de cerrarlo: de lo contrario se iria con el y el aviso que se lanza
    // justo despues de cerrar no se veria.
    var host = document.querySelector(".tp-toast-host");
    if (host && dlg.contains(host)) document.body.appendChild(host);

    try {
      if (typeof dlg.close === "function") {
        dlg.close(value == null ? "" : String(value));
      } else {
        dlg.removeAttribute("open");
        dlg.dispatchEvent(new Event("close"));
      }
    } catch (e) {}
  }

  function confirm(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var done = false;
      open({
        title: opts.title || tpT('global.confirm'),
        size: "sm",
        body:
          '<p style="margin:0; font-size:14px; line-height:1.5;">' +
          escapeHtml(opts.message || tpT('ui.continue_question')) +
          "</p>",
        buttons: [
          {
            label: opts.cancelLabel || tpT('global.cancel'),
            variant: "ghost",
            onClick: function () {
              done = true;
              resolve(false);
            },
          },
          {
            label: opts.confirmLabel || tpT('ui.accept'),
            variant: opts.danger ? "danger" : "primary",
            onClick: function () {
              done = true;
              resolve(true);
            },
          },
        ],
        onClose: function () {
          if (!done) resolve(false);
        },
      });
    });
  }

  function alertBox(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      open({
        title: opts.title || "Aviso",
        size: "sm",
        body:
          '<p style="margin:0; font-size:14px; line-height:1.5;">' +
          escapeHtml(opts.message || "") +
          "</p>",
        buttons: [
          {
            label: "OK",
            variant: "primary",
            onClick: function () {
              resolve();
            },
          },
        ],
        onClose: function () {
          resolve();
        },
      });
    });
  }

  function toast(message, type) {
    type = type || "info";
    var host = ensureToastHost();
    var t = document.createElement("div");
    t.className = "tp-toast tp-toast--" + type;
    t.textContent = String(message == null ? "" : message);
    host.appendChild(t);
    setTimeout(function () {
      t.classList.add("is-leaving");
      setTimeout(function () {
        if (t.parentNode) t.parentNode.removeChild(t);
      }, 250);
    }, 3200);
  }

  /* ---------- Form helpers ---------- */
  function setBusy(dlg, busy) {
    var btns = dlg.querySelectorAll("[data-tp-btn]");
    // El spinner va SOLO en el botón que disparó la acción (el que se clickeó);
    // los demás (Cancelar/Eliminar) solo se deshabilitan. Fallback: primary/accent.
    var active =
      dlg._tpActiveBtn ||
      dlg.querySelector("[data-tp-btn].tp-btn--primary, [data-tp-btn].tp-btn--accent");
    btns.forEach(function (b) {
      b.disabled = !!busy;
      if (b !== active) return;
      if (busy) b.dataset._txt = b.dataset._txt || b.innerHTML;
      b.innerHTML = busy
        ? '<span class="tp-modal__busy"></span>' +
          (b.dataset._txt || "Guardando…")
        : b.dataset._txt || b.innerHTML;
      if (!busy) delete b.dataset._txt;
    });
  }

  /**
   * Busca a que control del modal pertenece una clave de error.
   *
   * El backend nombra las claves como el modelo (send_to_email, devices,
   * date_from...), pero no todo campo es un <input name="...">: los
   * selectores de chips y los pickers no llevan name. Por eso se admite
   * tambien data-error-for, que puede listar varias claves separadas por
   * espacio (p.ej. "devices selected_devices"), y se reintenta sin el
   * indice cuando la regla es sobre un array ("send_to_email.0").
   */
  function campoDeError(dlg, key) {
    function busca(k) {
      // Las claves vienen del servidor; entrecomilladas son seguras, pero
      // una comilla dentro romperia el selector.
      if (/["\\]/.test(k)) return null;
      return (
        dlg.querySelector('[name="' + k + '"]') ||
        dlg.querySelector('[data-error-for~="' + k + '"]')
      );
    }

    var corte = key.indexOf(".");
    return busca(key) || (corte > 0 ? busca(key.slice(0, corte)) : null);
  }

  /**
   * Pinta los errores de validacion junto a su campo.
   *
   * Lo que no se puede colocar NO se descarta: se lista arriba del modal.
   * Antes se perdia en silencio y el modal se quedaba quieto, sin decir que
   * faltaba -justo lo que pasaba al guardar un reporte programado sin
   * correo: el servidor respondia 422 con send_to_email y no se veia nada.
   */
  function showFieldErrors(dlg, errors) {
    // Clear prior errors
    dlg.querySelectorAll(".tp-field__error").forEach(function (n) {
      n.remove();
    });
    dlg.querySelectorAll(".is-invalid").forEach(function (n) {
      n.classList.remove("is-invalid");
    });
    var previo = dlg.querySelector(".tp-modal__errors");
    if (previo) previo.remove();

    if (!errors || typeof errors !== "object") return;

    var sueltos = [];

    Object.keys(errors).forEach(function (key) {
      var msg = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
      var input = campoDeError(dlg, key);

      if (!input) {
        if (msg && sueltos.indexOf(msg) === -1) sueltos.push(msg);
        return;
      }

      input.classList.add("is-invalid");
      var err = document.createElement("div");
      err.className = "tp-field__error";
      err.textContent = msg;
      var holder =
        input.closest(".tp-field") || input.closest(".tp-form__field") || input.parentNode;
      holder.appendChild(err);

      // El aviso desaparece en cuanto se corrige el campo. Antes seguia
      // visible hasta el siguiente envio, asi que parecia que el
      // formulario no se enteraba de lo que acababas de escribir.
      var limpia = function () {
        input.classList.remove("is-invalid");
        if (err.parentNode) err.parentNode.removeChild(err);
        input.removeEventListener("input", limpia);
        input.removeEventListener("change", limpia);
      };
      input.addEventListener("input", limpia);
      input.addEventListener("change", limpia);
    });

    if (!sueltos.length) return;

    var body = dlg.querySelector(".tp-modal__body");
    if (!body) return;

    var caja = document.createElement("div");
    caja.className = "tp-modal__errors";
    caja.setAttribute("role", "alert");
    var lista = document.createElement("ul");
    sueltos.forEach(function (m) {
      var li = document.createElement("li");
      li.textContent = m;
      lista.appendChild(li);
    });
    caja.appendChild(lista);
    body.insertBefore(caja, body.firstChild);
    body.scrollTop = 0;
  }

  /* ── Teclado para controles que no son <button> ──────────────────────
     Doce sitios de la v2 reciben click sobre un <tr>, <div> o <span>: filas
     de tabla, opciones del desplegable propio, celdas del horario. Un
     <button> responde a Enter y Espacio por su cuenta; esos no.

     En vez de doce manejadores nuevos, uno solo: si el elemento declara rol
     de control y puede recibir foco, Enter o Espacio le disparan un click.
     Asi los manejadores de click que ya existen valen tal cual.

     No se toca nada nativo -<button>, <a href>, <input>, <select>...-, que
     ya lo hacen solos y donde Espacio significa otra cosa (escribir, abrir
     la lista). */
  var ROLES_CONTROL = ["button", "option", "switch", "checkbox", "menuitem", "tab", "radio"];
  var NATIVOS = ["BUTTON", "A", "INPUT", "SELECT", "TEXTAREA", "SUMMARY", "OPTION", "LABEL"];

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    var el = e.target;
    if (!el || el.nodeType !== 1) return;
    if (NATIVOS.indexOf(el.tagName) !== -1) return;
    if (el.isContentEditable) return;

    var rol = el.getAttribute("role");
    if (!rol || ROLES_CONTROL.indexOf(rol) === -1) return;
    if (el.getAttribute("aria-disabled") === "true") return;
    // Enfocable: o lo han hecho enfocable, o ya tiene el foco.
    if (el.tabIndex < 0 && document.activeElement !== el) return;

    // Espacio desplazaria la pagina.
    e.preventDefault();
    el.click();
  });

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }

  /**
   * Fetch JSON with CSRF + same-origin.
   */
  /**
   * Muestra el motivo real de un error del servidor.
   *
   * Un 422 trae {errors: {campo: ["mensaje"]}}. Sin esto el usuario solo
   * veia "No se pudo crear." y no habia forma de saber que campo fallaba.
   *
   * @param {object} r        respuesta de fetchJSON: {ok, status, data}
   * @param {string} fallback texto si el servidor no explica nada
   */
  function errorToast(r, fallback) {
    var msg = fallback;
    var d = (r && r.data) || {};
    if (d.errors && typeof d.errors === "object") {
      for (var k in d.errors) {
        if (!Object.prototype.hasOwnProperty.call(d.errors, k)) continue;
        var v = d.errors[k];
        msg = Array.isArray(v) ? v[0] : String(v);
        break;
      }
    } else if (d.message) {
      msg = d.message;
    }
    if (typeof toast === "function") toast(msg, "error");
    return msg;
  }

  function fetchJSON(url, opts) {
    opts = opts || {};
    var headers = Object.assign(
      {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      opts.headers || {},
    );
    if (opts.method && opts.method !== "GET") headers["X-CSRF-TOKEN"] = csrf();
    if (opts.json) {
      headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(opts.json);
      delete opts.json;
    }
    opts.headers = headers;
    opts.credentials = "same-origin";
    return fetch(url, opts).then(function (r) {
      return r
        .json()
        .then(function (j) {
          return { ok: r.ok, status: r.status, data: j };
        })
        .catch(function () {
          return { ok: r.ok, status: r.status, data: null };
        });
    });
  }

  window.tpModal = {
    open: open,
    close: close,
    confirm: confirm,
    alert: alertBox,
    toast: toast,
    setBusy: setBusy,
    showFieldErrors: showFieldErrors,
    fetchJSON: fetchJSON,
    errorToast: errorToast,
    escapeHtml: escapeHtml,
    csrf: csrf,
    tabs: buildTabs,
  };

  /**
   * Build a tabbed UI inside a container.
   * @param {HTMLElement} container — scope to mount tabs into
   * @param {Array<{key:string,label:string,html:string,badge?:string}>} tabs
   * @param {{active?:string}} opts
   */
  function buildTabs(container, tabs, opts) {
    opts = opts || {};
    var active = opts.active || (tabs[0] && tabs[0].key);
    var navHtml = '<div class="tp-tabs__nav" role="tablist">';
    var panelHtml = '<div class="tp-tabs__panels">';
    tabs.forEach(function (t) {
      var is = t.key === active ? " is-active" : "";
      navHtml +=
        '<button type="button" class="tp-tabs__tab' +
        is +
        '" role="tab" data-tp-tab="' +
        escapeHtml(t.key) +
        '">' +
        escapeHtml(t.label) +
        (t.badge
          ? '<span class="tp-tabs__badge">' + escapeHtml(t.badge) + "</span>"
          : "") +
        "</button>";
      panelHtml +=
        '<div class="tp-tabs__panel' +
        is +
        '" data-tp-panel="' +
        escapeHtml(t.key) +
        '">' +
        (t.html || "") +
        "</div>";
    });
    navHtml += "</div>";
    panelHtml += "</div>";
    container.innerHTML =
      '<div class="tp-tabs">' + navHtml + panelHtml + "</div>";

    // Estado inicial: la clase ya la pone el HTML de arriba, el atributo no.
    container.querySelectorAll("[data-tp-tab]").forEach(function (b) {
      b.setAttribute("aria-selected", b.classList.contains("is-active") ? "true" : "false");
    });

    container.querySelectorAll("[data-tp-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.getAttribute("data-tp-tab");
        container.querySelectorAll("[data-tp-tab]").forEach(function (b) {
          b.classList.toggle("is-active", b === btn);
          // La clase cambia el aspecto; el atributo es lo unico que oye un
          // lector de pantalla. Van juntos para que no se separen.
          b.setAttribute("aria-selected", b === btn ? "true" : "false");
        });
        container.querySelectorAll("[data-tp-panel]").forEach(function (p) {
          p.classList.toggle(
            "is-active",
            p.getAttribute("data-tp-panel") === key,
          );
        });
      });
    });

    return {
      activate: function (key) {
        var btn = container.querySelector('[data-tp-tab="' + key + '"]');
        if (btn) btn.click();
      },
    };
  }
})();
