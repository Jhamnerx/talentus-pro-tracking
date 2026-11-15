/* =====================================================
 * TalentusPro · tp-modal · Phase 11
 * Native <dialog> modal + toast helpers.
 * Global: window.tpModal
 *   open({ title, icon, sub, body, size, buttons, onMount, onClose })
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
  var _modalSeq = 0;

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

    // Orden único del pie (QA V9), sea cual sea el orden en que lo pase cada
    // módulo: destructivo (Eliminar) a la izquierda y separado, luego
    // Cancelar y el resto, y la acción principal a la derecha del todo.
    // "Destructivo" = danger/critical cuando además hay una principal (en un
    // confirm() el botón rojo ES la principal y se queda a la derecha), o
    // b.destructive explícito. data-tp-btn conserva el índice original:
    // los manejadores de abajo lo buscan por él.
    // Altura: la normal de .tp-btn (36 px), igual que las acciones de página
    // (QA V4); --sm queda para barras densas y filas de tabla.
    var btnList = opts.buttons || [];
    var isMain = function (b) {
      return b.variant === "primary" || b.variant === "accent" || b.variant === "success";
    };
    var hasMain = btnList.some(isMain);
    var isDestructive = function (b) {
      return b.destructive === true ||
        (hasMain && (b.variant === "danger" || b.variant === "critical"));
    };
    var ordered = btnList.map(function (b, idx) {
      return { b: b, idx: idx, rank: isDestructive(b) ? 0 : isMain(b) ? 2 : 1 };
    }).sort(function (x, y) {
      return x.rank - y.rank || x.idx - y.idx;
    });
    var buttonsHtml = "";
    ordered.forEach(function (o) {
      var b = o.b;
      buttonsHtml +=
        '<button type="button" class="tp-btn tp-btn--' +
        (b.variant || "ghost") +
        (o.rank === 0 ? " tp-modal__foot-start" : "") +
        '" data-tp-btn="' +
        o.idx +
        '"' +
        (b.disabled ? " disabled" : "") +
        ">" +
        escapeHtml(b.label) +
        "</button>";
    });

    // Nombre accesible del dialogo: su titulo (QA D4). Id unico por dialogo
    // porque pueden apilarse varios (p.ej. confirmar encima de la ficha).
    var titleId = "tp-modal-title-" + ++_modalSeq;
    dlg.setAttribute("aria-labelledby", titleId);

    dlg.innerHTML =
      '<div class="tp-modal__inner">' +
      '<header class="tp-modal__head">' +
      "<div>" +
      '<h2 class="tp-modal__title" id="' + titleId + '">' +
      // Icono opcional delante del título (SVG de confianza, lo pasa el
      // propio código; NO viene de datos del usuario).
      (opts.icon ? '<span class="tp-modal__title-icon" aria-hidden="true">' + opts.icon + "</span>" : "") +
      escapeHtml(opts.title || "") +
      "</h2>" +
      (opts.sub
        ? '<div class="tp-modal__sub">' + escapeHtml(opts.sub) + "</div>"
        : "") +
      "</div>" +
      '<button type="button" class="tp-modal__close" aria-label="' +
      escapeHtml(typeof tpT === "function" ? tpT("global.close") : "Cerrar") +
      '" data-tp-close>' +
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
   * Nombre legible de un campo de error: la etiqueta que tiene al lado en el
   * modal; si no, validation.attributes (puente attr.*); si no, la clave.
   */
  function etiquetaDeCampo(dlg, key, input) {
    if (input) {
      var holder = input.closest(".tp-field") || input.closest(".tp-form__field") || input.closest("label");
      var lab = holder && holder.querySelector(".tp-form__label, .tp-field__label, label");
      var txt = lab && textoDeEtiqueta(lab);
      if (txt) return txt;
    }
    var base = key.indexOf(".") > 0 ? key.slice(0, key.indexOf(".")) : key;
    var attr = typeof tpT === "function" ? tpT("attr." + base) : "attr." + base;
    if (attr !== "attr." + base) return attr;
    return base.replace(/_/g, " ");
  }

  /**
   * Texto de una etiqueta SIN las ayudas «?» (.tp-help), iconos ni errores
   * que lleve dentro. Antes el resumen decia «IMEI del dispositivo ? / ID de
   * rastreador ?» porque se colaba el texto del icono de ayuda (QA D2).
   */
  function textoDeEtiqueta(lab) {
    var copia = lab.cloneNode(true);
    copia
      .querySelectorAll(".tp-help, [data-tp-help-text], [aria-hidden='true'], svg, .tp-field__error, input, select, textarea")
      .forEach(function (n) {
        n.parentNode.removeChild(n);
      });
    return copia.textContent
      .replace(/\s+/g, " ")
      .replace(/\s+([\/,])/g, " $1")
      .replace(/[*:]\s*$/, "")
      .trim();
  }

  /** Pestaña (boton) del modal que contiene el control, o null. */
  function pestanaDe(dlg, input) {
    var panel = input && input.closest("[data-tp-panel]");
    if (!panel) return null;
    var key = panel.getAttribute("data-tp-panel");
    return dlg.querySelector('[data-tp-tab="' + key + '"]');
  }

  /** Activa la pestaña del modal que contiene el control (si hay pestañas). */
  function mostrarPestanaDe(dlg, input) {
    var btn = pestanaDe(dlg, input);
    if (btn && !btn.classList.contains("is-active")) btn.click();
  }

  /** Control enfocable de un campo de error (el propio input o el primero que contenga). */
  function controlDe(input) {
    if (!input) return null;
    if (input.matches("input,select,textarea")) return input;
    return input.querySelector("input:not([type=hidden]),select,textarea,button") || input;
  }

  var _errSeq = 0;

  /** Marca/desmarca una pestaña con errores: punto rojo + texto para lectores. */
  function marcaPestana(btn, on) {
    if (!btn) return;
    btn.classList.toggle("has-error", !!on);
    var dot = btn.querySelector(".tp-tabs__err");
    if (on && !dot) {
      dot = document.createElement("span");
      dot.className = "tp-tabs__err";
      // El punto es visual; el texto oculto lo lee el lector de pantalla.
      var sr = document.createElement("span");
      sr.className = "tp-sr-only";
      sr.textContent = " (" + (typeof tpT === "function" ? tpT("ui.tab_has_errors") : "errores") + ")";
      dot.appendChild(sr);
      btn.appendChild(dot);
    } else if (!on && dot) {
      dot.parentNode.removeChild(dot);
    }
  }

  /** Quita aria-invalid y la referencia al mensaje de error de un control. */
  function limpiaAria(ctl, errId) {
    if (!ctl) return;
    ctl.removeAttribute("aria-invalid");
    ctl.removeAttribute("data-tp-invalid");
    var ids = (ctl.getAttribute("aria-describedby") || "").split(/\s+/).filter(function (t) {
      return t && (errId ? t !== errId : t.indexOf("tp-err-") !== 0);
    });
    if (ids.length) ctl.setAttribute("aria-describedby", ids.join(" "));
    else ctl.removeAttribute("aria-describedby");
  }

  /**
   * Pinta los errores de validacion junto a su campo Y los resume bajo la
   * cabecera del modal, uno por campo, con su nombre.
   *
   * El resumen es la parte que se ve siempre: el campo puede estar en otra
   * pestaña o fuera de la vista, y un "Necesario" suelto no dice que falta.
   * Cada linea del resumen lleva al campo (y cambia de pestaña si hace falta).
   */
  function showFieldErrors(dlg, errors) {
    // Clear prior errors
    dlg.querySelectorAll(".tp-field__error").forEach(function (n) {
      n.remove();
    });
    dlg.querySelectorAll(".is-invalid").forEach(function (n) {
      n.classList.remove("is-invalid");
    });
    dlg.querySelectorAll("[data-tp-invalid]").forEach(function (n) {
      limpiaAria(n);
    });
    dlg.querySelectorAll("[data-tp-tab].has-error").forEach(function (b) {
      marcaPestana(b, false);
    });
    var previo = dlg.querySelector(".tp-modal__errors");
    if (previo) previo.remove();

    if (!errors || typeof errors !== "object") return;

    var resumen = [];

    Object.keys(errors).forEach(function (key) {
      var msg = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
      var input = campoDeError(dlg, key);
      var label = etiquetaDeCampo(dlg, key, input);

      if (!msg) return;
      if (!resumen.some(function (r) { return r.label === label && r.msg === msg; })) {
        resumen.push({ label: label, msg: msg, input: input });
      }
      if (!input) return;

      input.classList.add("is-invalid");
      var err = document.createElement("div");
      err.className = "tp-field__error";
      err.id = "tp-err-" + ++_errSeq;
      err.textContent = msg;
      var holder =
        input.closest(".tp-field") || input.closest(".tp-form__field") || input.parentNode;
      holder.appendChild(err);

      // Accesibilidad: el control anuncia que es invalido y cual es el
      // mensaje (vale para vehiculo, conductor, alerta... todos pasan por aqui).
      var ctl = controlDe(input);
      if (ctl) {
        ctl.setAttribute("aria-invalid", "true");
        ctl.setAttribute("data-tp-invalid", "1");
        var desc = (ctl.getAttribute("aria-describedby") || "").trim();
        ctl.setAttribute("aria-describedby", (desc ? desc + " " : "") + err.id);
      }

      // La pestaña que contiene el campo se marca con un punto rojo.
      var tab = pestanaDe(dlg, input);
      marcaPestana(tab, true);

      // El aviso desaparece en cuanto se corrige el campo. Antes seguia
      // visible hasta el siguiente envio, asi que parecia que el
      // formulario no se enteraba de lo que acababas de escribir.
      var limpia = function () {
        input.classList.remove("is-invalid");
        if (err.parentNode) err.parentNode.removeChild(err);
        limpiaAria(ctl, err.id);
        // La pestaña deja de marcarse cuando ya no le quedan campos con error.
        if (tab) {
          var panel = input.closest("[data-tp-panel]");
          if (!panel || !panel.querySelector(".is-invalid")) marcaPestana(tab, false);
        }
        input.removeEventListener("input", limpia);
        input.removeEventListener("change", limpia);
      };
      input.addEventListener("input", limpia);
      input.addEventListener("change", limpia);
    });

    if (!resumen.length) return;

    var head = dlg.querySelector(".tp-modal__head");
    var body = dlg.querySelector(".tp-modal__body");
    if (!head && !body) return;

    var caja = document.createElement("div");
    caja.className = "tp-modal__errors";
    caja.setAttribute("role", "alert");
    var titulo = document.createElement("div");
    titulo.className = "tp-modal__errors-title";
    titulo.textContent =
      resumen.length === 1
        ? tpT("ui.fix_field_to_continue")
        : tpT("ui.fix_fields_to_continue", { count: resumen.length });
    caja.appendChild(titulo);
    var lista = document.createElement("ul");
    resumen.forEach(function (r) {
      var li = document.createElement("li");
      var b = document.createElement("strong");
      b.textContent = r.label;
      li.appendChild(b);
      li.appendChild(document.createTextNode(": " + r.msg));
      if (r.input) {
        li.className = "is-link";
        li.setAttribute("role", "button");
        li.tabIndex = 0;
        li.addEventListener("click", function () {
          mostrarPestanaDe(dlg, r.input);
          var foco = r.input.matches("input,select,textarea") ? r.input : r.input.querySelector("input,select,textarea,button");
          if (foco) { try { foco.focus({ preventScroll: true }); } catch (e) { foco.focus(); } }
          (foco || r.input).scrollIntoView({ block: "center", behavior: "smooth" });
        });
      }
      lista.appendChild(li);
    });
    caja.appendChild(lista);
    if (head && head.parentNode) head.parentNode.insertBefore(caja, head.nextSibling);
    else body.insertBefore(caja, body.firstChild);
    // Y se abre la PRIMERA pestaña (en el orden de las pestañas, no en el
    // de las claves del servidor) que tenga un campo con error, y se enfoca
    // ese campo. Si la pestaña activa ya tiene errores, se queda en ella.
    var conInput = resumen.filter(function (r) { return r.input; });
    var primero = conInput[0];
    var tabs = dlg.querySelectorAll("[data-tp-tab]");
    if (tabs.length && conInput.length) {
      var activa = dlg.querySelector("[data-tp-tab].is-active.has-error");
      var destino = activa || dlg.querySelector("[data-tp-tab].has-error");
      if (destino) {
        var enDestino = conInput.filter(function (r) { return pestanaDe(dlg, r.input) === destino; })[0];
        if (enDestino) primero = enDestino;
      }
    }
    if (primero) {
      mostrarPestanaDe(dlg, primero.input);
      var foco = controlDe(primero.input);
      if (foco && typeof foco.focus === "function") {
        try { foco.focus({ preventScroll: true }); } catch (e) { foco.focus(); }
      }
    }
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
  /**
   * Primer error de validacion como texto "Campo: mensaje" (o el respaldo).
   * Para los sitios que avisan con un toast y no tienen formulario donde
   * colocar el error: "Necesario" a secas no dice que falta.
   */
  function firstError(errors, fallback) {
    if (!errors || typeof errors !== "object") return fallback;
    for (var k in errors) {
      if (!Object.prototype.hasOwnProperty.call(errors, k)) continue;
      var v = errors[k];
      var msg = Array.isArray(v) ? v[0] : String(v);
      if (!msg) continue;
      return etiquetaDeCampo(null, k, null) + ": " + msg;
    }
    return fallback;
  }

  function errorToast(r, fallback) {
    var msg = fallback;
    var d = (r && r.data) || {};
    if (d.errors && typeof d.errors === "object") {
      msg = firstError(d.errors, fallback);
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
    firstError: firstError,
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

  // --------------------------------------------------------------------------
  // Ayuda "?" con imagen (paridad tooltipMarkImei del legacy): un
  // <span class="tp-help" data-tp-help-img="url" data-tp-help-text="…">
  // muestra al pasar el ratón (o con foco) una tarjeta con el texto a la
  // izquierda y la imagen a la derecha. Se pinta en el <dialog> abierto (o
  // en body) con position:fixed para que el overflow del modal no la corte.
  // --------------------------------------------------------------------------
  var helpPop = null;
  var helpFor = null;

  function hideHelp() {
    if (helpPop && helpPop.parentNode) helpPop.parentNode.removeChild(helpPop);
    helpPop = null;
    helpFor = null;
  }

  function showHelp(mark) {
    if (helpFor === mark) return;
    hideHelp();
    helpFor = mark;
    var pop = document.createElement("div");
    pop.className = "tp-help-pop";
    pop.setAttribute("role", "tooltip");
    var img = mark.getAttribute("data-tp-help-img");
    if (img) pop.style.backgroundImage = 'url("' + img.replace(/"/g, "%22") + '")';
    else pop.classList.add("tp-help-pop--text");
    var txt = document.createElement("span");
    txt.className = "tp-help-pop__text";
    txt.textContent = mark.getAttribute("data-tp-help-text") || "";
    pop.appendChild(txt);
    (mark.closest("dialog") || document.body).appendChild(pop);
    helpPop = pop;

    var r = mark.getBoundingClientRect();
    var w = pop.offsetWidth;
    var h = pop.offsetHeight;
    var left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 8));
    var top = r.top - h - 8;
    if (top < 8) top = r.bottom + 8;
    pop.style.left = left + "px";
    pop.style.top = top + "px";
  }

  document.addEventListener("mouseover", function (e) {
    var mark = e.target.closest && e.target.closest("[data-tp-help-text]");
    if (mark) showHelp(mark);
    else if (helpFor) hideHelp();
  });
  document.addEventListener("focusin", function (e) {
    var mark = e.target.closest && e.target.closest("[data-tp-help-text]");
    if (mark) showHelp(mark);
  });
  document.addEventListener("focusout", function (e) {
    if (helpFor && e.target === helpFor) hideHelp();
  });
  document.addEventListener("scroll", hideHelp, true);
})();

/* =====================================================
 * tpFmt · fechas con el formato del usuario (QA V10)
 * -----------------------------------------------------
 * Una sola forma de pintar fechas en la v2: la que el usuario eligió en
 * Ajustes (date_format + time_format, los mismos que usa Formatter en el
 * servidor). window.Talentus.datetime lo inyecta layout.blade.php.
 *
 *   tpFmt.datetime("2026-11-07 19:13:02")  → "07-11-2026 19:13:02"
 *   tpFmt.datetime(valor, { seconds: false }) → sin segundos (tablero)
 *   tpFmt.date("2026-11-07")               → "07-11-2026"
 *   tpFmt.fromDate(new Date(iso))          → pasa a la zona del usuario
 *
 * Las cadenas "Y-m-d H:i:s" que manda el servidor ya vienen en la zona del
 * usuario (Formatter::time()->convert): solo se reordenan. Lo que no tenga
 * esa forma (ya formateado, texto) se devuelve tal cual.
 * ===================================================== */
(function () {
  "use strict";

  var CFG = (window.Talentus && window.Talentus.datetime) || {};
  var DATE_FMT = CFG.date || "Y-m-d";
  var TIME_FMT = CFG.time || "H:i:s";
  var OFFSET = Number(CFG.offset) || 0;
  var RX = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?)?$/;
  // ISO con zona («…Z», «…+00:00»): es un instante absoluto, no una hora local.
  var RX_ZONED = /^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:?\d{2})$/;

  function pad(n) {
    return (n < 10 ? "0" : "") + n;
  }

  // Subconjunto de date() de PHP: lo que ofrecen config('tobuli.date_formats')
  // y time_formats (Y m d H i s h A), más j n y G g a por si acaso.
  function render(fmt, p) {
    var out = "";
    for (var i = 0; i < fmt.length; i++) {
      var c = fmt.charAt(i);
      var h12 = p.H % 12 || 12;
      switch (c) {
        case "Y": out += p.Y; break;
        case "y": out += String(p.Y).slice(-2); break;
        case "m": out += pad(p.m); break;
        case "n": out += p.m; break;
        case "d": out += pad(p.d); break;
        case "j": out += p.d; break;
        case "H": out += pad(p.H); break;
        case "G": out += p.H; break;
        case "h": out += pad(h12); break;
        case "g": out += h12; break;
        case "i": out += pad(p.i); break;
        case "s": out += pad(p.s); break;
        case "A": out += p.H < 12 ? "AM" : "PM"; break;
        case "a": out += p.H < 12 ? "am" : "pm"; break;
        case "\\": i++; out += fmt.charAt(i); break;
        default: out += c;
      }
    }
    return out;
  }

  function timeFmt(seconds) {
    if (seconds !== false) return TIME_FMT;
    // "H:i:s" → "H:i", "h:i:s A" → "h:i A"
    return TIME_FMT.replace(/:s/, "");
  }

  function parts(value) {
    var m = RX.exec(String(value == null ? "" : value).trim());
    if (!m) return null;
    return {
      Y: +m[1], m: +m[2], d: +m[3],
      H: m[4] != null ? +m[4] : null,
      i: m[5] != null ? +m[5] : 0,
      s: m[6] != null ? +m[6] : 0,
    };
  }

  function datetime(value, opts) {
    if (value == null || value === "") return "—";
    var str = String(value).trim();
    if (RX_ZONED.test(str)) return fromDate(new Date(str), opts);
    var p = parts(str);
    if (!p) return str;
    if (p.H == null) return render(DATE_FMT, p);
    return render(DATE_FMT + " " + timeFmt(opts && opts.seconds), p);
  }

  function date(value) {
    if (value == null || value === "") return "—";
    var p = parts(value);
    return p ? render(DATE_FMT, p) : String(value);
  }

  // Date (instante absoluto) → zona del usuario con el desfase del servidor.
  function fromDate(d, opts) {
    if (!(d instanceof Date) || isNaN(d.getTime())) return "—";
    var u = new Date(d.getTime() + OFFSET * 60000);
    var p = {
      Y: u.getUTCFullYear(), m: u.getUTCMonth() + 1, d: u.getUTCDate(),
      H: u.getUTCHours(), i: u.getUTCMinutes(), s: u.getUTCSeconds(),
    };
    return render(DATE_FMT + " " + timeFmt(opts && opts.seconds), p);
  }

  window.tpFmt = { datetime: datetime, date: date, fromDate: fromDate };

  /* ---------------------------------------------------------------
   * tpEmpty · texto de estado vacío (QA V7)
   *   tpEmpty(filtrado, "ui.no_expenses")
   *   - con búsqueda o filtro activos → «Sin resultados.»
   *   - sin nada que mostrar          → «Todavía no hay X.» (clave propia
   *     del módulo o, si no se pasa, «Todavía no hay registros.»)
   * --------------------------------------------------------------- */
  window.tpEmpty = function (filtered, noneKey) {
    var t = typeof window.tpT === "function" ? window.tpT : function (k) { return k; };
    if (filtered) return t("ui.no_results");
    return t(noneKey || "ui.no_records_yet");
  };
})();
