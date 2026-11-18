/* =====================================================
 * TalentusPro · Talentus IA CHAT (UI v2)
 * Panel lateral del asistente. Lee la respuesta del servidor como eventos
 * SSE: conversation, delta, source, action, done, error.
 * Los botones de la respuesta solo navegan a pantallas de la UI v2 o
 * descargan archivos; nunca se ejecuta JS que mande el servidor.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tpai");
  if (!root) return;

  var PERIOD_TAG = "{{elegir_periodo}}";
  var STATE_KEY = "tpai_state";

  var cfg = {
    chat: root.getAttribute("data-url-chat"),
    suggestions: root.getAttribute("data-url-suggestions"),
    conversations: root.getAttribute("data-url-conversations"),
    confirm: root.getAttribute("data-url-confirm"),
    cancel: root.getAttribute("data-url-cancel"),
    live: root.getAttribute("data-url-live"),
    history: root.getAttribute("data-url-history"),
    screen: root.getAttribute("data-screen") || "live",
    csrf: (window.Talentus && window.Talentus.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content,
    i18n: {},
  };
  try { cfg.i18n = JSON.parse(root.getAttribute("data-i18n")) || {}; } catch (e) {}

  var el = {
    body: document.getElementById("tpai-body"),
    welcome: document.getElementById("tpai-welcome"),
    suggestions: document.getElementById("tpai-suggestions"),
    messages: document.getElementById("tpai-messages"),
    history: document.getElementById("tpai-history"),
    form: document.getElementById("tpai-form"),
    input: document.getElementById("tpai-input"),
    send: root.querySelector(".tpai__send"),
    quota: document.getElementById("tpai-quota"),
  };

  var state = { conversationId: null, busy: false, loaded: false };
  var openers = Array.prototype.slice.call(document.querySelectorAll("[data-tpai-open]"));

  /* ---------------- Abrir / cerrar ---------------- */

  var fab = document.getElementById("tpai-fab");
  var fabDot = document.getElementById("tpai-fab-dot");

  /* El estado (abierto/minimizado y conversacion) sigue al usuario entre
     pantallas: se guarda en sessionStorage y se restaura al cargar. */
  function saveState() {
    try {
      sessionStorage.setItem(STATE_KEY, JSON.stringify({ open: !root.hidden, id: state.conversationId }));
    } catch (e) {}
  }

  function open() {
    root.hidden = false;
    openers.forEach(function (b) { b.setAttribute("aria-expanded", "true"); });
    if (fabDot) fabDot.hidden = true;
    if (!state.loaded) { state.loaded = true; loadSuggestions(); }
    saveState();
    setTimeout(function () { el.input.focus(); }, 50);
  }

  /* Minimiza: el panel se oculta y queda el boton flotante. */
  function close() {
    root.hidden = true;
    openers.forEach(function (b) { b.setAttribute("aria-expanded", "false"); });
    saveState();
    if (fab && root.contains(document.activeElement)) fab.focus();
  }

  function setBusy(busy) {
    state.busy = busy;
    el.send.disabled = busy;
    if (fab) fab.classList.toggle("is-busy", busy);
    // Respuesta terminada con el chat minimizado: aviso en el boton.
    if (!busy && root.hidden && fabDot) fabDot.hidden = false;
  }

  function toggle() { root.hidden ? open() : close(); }

  openers.forEach(function (b) {
    b.addEventListener("click", function (e) { e.preventDefault(); toggle(); });
  });

  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === "i" || e.key === "I")) {
      var t = e.target;
      var editable = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if (editable && t !== el.input) return;
      e.preventDefault();
      toggle();
    } else if (e.key === "Escape" && !root.hidden && root.contains(document.activeElement)) {
      close();
    }
  });

  root.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-tpai]");
    if (!btn) return;
    var action = btn.getAttribute("data-tpai");
    if (action === "close") close();
    if (action === "new") newConversation();
    if (action === "history") toggleHistory();
  });

  /* ---------------- Utilidades ---------------- */

  function t(key, repl) {
    var s = cfg.i18n[key] || key;
    if (repl) Object.keys(repl).forEach(function (k) { s = s.replace(":" + k, repl[k]); });
    return s;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function node(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function scrollDown() { el.body.scrollTop = el.body.scrollHeight; }

  function setQuota(remaining) {
    el.quota.textContent = remaining === null || remaining === undefined ? "" : t("quota_left", { count: remaining });
  }

  function headers(extra) {
    var h = { Accept: "application/json", "X-CSRF-TOKEN": cfg.csrf, "X-Requested-With": "XMLHttpRequest" };
    Object.keys(extra || {}).forEach(function (k) { h[k] = extra[k]; });
    return h;
  }

  function request(method, url) {
    return fetch(url, { method: method, credentials: "same-origin", headers: headers() }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) throw new Error(data.message || t("error"));
        return data;
      });
    });
  }

  function toast(message) {
    if (typeof window.tpToast === "function") window.tpToast({ title: message, variant: "danger" });
    else alert(message);
  }

  /* ---------------- Markdown basico y seguro ---------------- */

  function inline(text) {
    var s = escapeHtml(text);
    s = s.replace(/`([^`]+)`/g, "<code>$1</code>");
    s = s.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, "$1<em>$2</em>");
    s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    s = s.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>');
    return s;
  }

  function renderMarkdown(md) {
    var lines = md.replace(/\r/g, "").split("\n");
    var html = [];
    var i = 0;
    var cells = function (r) { return r.trim().replace(/^\||\|$/g, "").split("|").map(function (c) { return c.trim(); }); };

    while (i < lines.length) {
      var line = lines[i];

      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
        var rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(lines[i]); i++; }
        var out = '<div class="tpai-table"><table><thead><tr>' +
          cells(rows[0]).map(function (c) { return "<th>" + inline(c) + "</th>"; }).join("") + "</tr></thead><tbody>";
        rows.slice(2).forEach(function (r) {
          out += "<tr>" + cells(r).map(function (c) { return "<td>" + inline(c) + "</td>"; }).join("") + "</tr>";
        });
        html.push(out + "</tbody></table></div>");
        continue;
      }

      if (/^\s*([-*•])\s+/.test(line)) {
        var items = [];
        while (i < lines.length && /^\s*([-*•])\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*[-*•]\s+/, "")); i++; }
        html.push("<ul>" + items.map(function (it) { return "<li>" + inline(it) + "</li>"; }).join("") + "</ul>");
        continue;
      }

      if (/^\s*\d+[.)]\s+/.test(line)) {
        var oitems = [];
        // Conserva el numero con que empieza: una sublista corta la lista y
        // el paso siguiente ("4. ...") no debe volver a 1.
        var start = parseInt(line.match(/^\s*(\d+)/)[1], 10);
        while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) { oitems.push(lines[i].replace(/^\s*\d+[.)]\s+/, "")); i++; }
        html.push("<ol" + (start > 1 ? ' start="' + start + '"' : "") + ">" +
          oitems.map(function (it) { return "<li>" + inline(it) + "</li>"; }).join("") + "</ol>");
        continue;
      }

      if (/^\s*$/.test(line)) { i++; continue; }

      var para = [];
      while (i < lines.length && !/^\s*$/.test(lines[i]) && !/^\s*([-*•]|\d+[.)])\s+/.test(lines[i]) && !/^\s*\|.*\|\s*$/.test(lines[i])) {
        para.push(lines[i].replace(/^#+\s*/, ""));
        i++;
      }
      html.push("<p>" + para.map(inline).join("<br>") + "</p>");
    }

    return html.join("");
  }

  /* ---------------- Botones de la respuesta ---------------- */

  /* Solo rutas de la propia instalacion (/ui/v2/...). */
  function safeUrl(url) {
    try {
      var u = new URL(url, location.origin);
      return u.origin === location.origin ? u.href : null;
    } catch (e) {
      return null;
    }
  }

  /* Navega dejando el panel abierto en la conversacion actual. */
  function go(url) {
    var target = safeUrl(url);
    if (!target) return;
    saveState();
    location.href = target;
  }

  /* ---------------- Atajos de la UI v2 (lista fija) ----------------
     Cada atajo usa la misma API que el menu de tres puntos o el menu
     Herramientas. Si la pantalla actual no carga esa API, se abre el mapa en
     vivo (que las carga todas) y el atajo se ejecuta al terminar de cargar. */

  var PENDING_KEY = "tpai_pending";

  function api(name) { return window[name] || null; }
  function attr(selector, name) {
    var n = document.querySelector(selector);
    return n ? n.getAttribute(name) : null;
  }
  function refreshLive() {
    if (window.tpLive && typeof window.tpLive.refresh === "function") window.tpLive.refresh();
  }

  var SHORTCUTS = {
    editar_unidad: { need: "tpDeviceModal", run: function (a) { tpDeviceModal.openEdit(a.device_id, refreshLive); } },
    enviar_comando: { need: "tpCommands", run: function (a) { tpCommands.open(a.device_id); } },
    video_en_vivo: { need: "tpDeviceVideo", run: function (a) { tpDeviceVideo.open(a.device_id, { name: a.device_name }); } },
    grabaciones_video: { need: "tpDeviceVideo", run: function (a) { tpDeviceVideo.recordings(a.device_id, { name: a.device_name }); } },
    fotos_camara: { need: "tpDeviceMedia", run: function (a) { tpDeviceMedia.open(a.device_id, a.device_name); } },
    alertas_unidad: { need: "tpDeviceAlerts", run: function (a) { tpDeviceAlerts.open(a.device_id, a.device_name); } },
    configurar_equipo: { need: "tpDeviceConfig", run: function (a) { tpDeviceConfig.open(a.device_id); } },
    compartir_unidad: { need: "tpSharing", run: function (a) { tpSharing.openForm(null, { deviceId: a.device_id }); } },
    servicios_unidad: { need: "tpServices", run: function (a) { tpServices.openList(a.device_id); } },
    nuevo_servicio: { need: "tpServices", run: function (a) { tpServices.openForm(a.device_id, null); } },
    acciones_llamada: { need: "tpCallActions", run: function (a) { tpCallActions.open(a.device_id); } },
    tipos_recorrido: { need: "tpRouteTypes", run: function (a) { tpRouteTypes.open(a.device_id, a.device_name); } },
    nueva_unidad: { need: "tpDeviceModal", run: function () { tpDeviceModal.openCreate(refreshLive); } },
    regla: { need: "tpLiveTools", run: function () { tpLiveTools.ruler(); } },
    mostrar_punto: { need: "tpLiveTools", run: function () { tpLiveTools.showPoint(); } },
    buscar_direccion: { need: "tpLiveTools", run: function () { tpLiveTools.searchAddress(attr("[data-address-search-url]", "data-address-search-url")); } },
    unidades_sin_transmitir: { need: "tpSilentDevices", run: function () { tpSilentDevices.open(); } },
    reenvios: { need: "tpForwards", run: function () { tpForwards.open(attr("[data-forwards-url]", "data-forwards-url")); } },
    ajustes_cuenta: { need: "tpAccount", run: function () { tpAccount.open(); } },
    cambiar_contrasena: { need: "tpAccount", run: function () { tpAccount.openPassword(); } },
    idioma: { need: "tpAccount", run: function () { tpAccount.openLanguage(); } },
    // Solo navegan
    enlaces_compartidos: { url: function (a) { return "/ui/v2/sharing?device_id=" + encodeURIComponent(a.device_id); } },
    nuevo_reporte: { url: function () { return "/ui/v2/reports?create=1"; } },
  };

  function runShortcut(action) {
    var def = SHORTCUTS[action.shortcut];
    if (!def) return;

    if (def.url) {
      go(def.url(action));
      return;
    }

    if (api(def.need)) {
      close(); // el formulario se abre en un modal: el chat queda en el boton flotante
      try { def.run(action); } catch (e) { toast(t("error")); }
      return;
    }

    try { sessionStorage.setItem(PENDING_KEY, JSON.stringify(action)); } catch (e) {}
    go(cfg.live + (action.device_id ? "?device=" + encodeURIComponent(action.device_id) : ""));
  }

  /* Atajo pendiente tras abrir el mapa en vivo: espera a que cargue su API. */
  function runPendingShortcut() {
    var action = null;
    try {
      action = JSON.parse(sessionStorage.getItem(PENDING_KEY) || "null");
      sessionStorage.removeItem(PENDING_KEY);
    } catch (e) {}
    var def = action && SHORTCUTS[action.shortcut];
    if (!def || !def.need) return;

    var tries = 0;
    (function wait() {
      if (api(def.need)) {
        // Un respiro para que la pagina termine de pintar el mapa y la lista.
        setTimeout(function () { close(); try { def.run(action); } catch (e) { toast(t("error")); } }, 400);
      } else if (++tries < 50) {
        setTimeout(wait, 200);
      }
    })();
  }

  function runAction(action, button) {
    if (!action) return;

    switch (action.type) {
      case "shortcut":
        runShortcut(action);
        break;

      case "screen":
        if (action.url) go(action.url);
        break;

      case "device":
        go(cfg.live + "?device=" + encodeURIComponent(action.device_id));
        break;

      case "history":
        go(cfg.history + "?device=" + encodeURIComponent(action.device_id) +
          "&from=" + encodeURIComponent(action.from) + "&to=" + encodeURIComponent(action.to));
        break;

      case "download":
        if (safeUrl(action.url)) location.href = safeUrl(action.url);
        break;

      case "history_export":
        if (button) button.disabled = true;
        request("GET", action.url).then(function (data) {
          if (data.download && safeUrl(data.download)) location.href = safeUrl(data.download);
          else toast(data.error || t("error"));
        }).catch(function (err) { toast(err.message); })
          .then(function () { if (button) button.disabled = false; });
        break;
    }
  }

  /* Tarjeta de confirmacion: la accion solo se ejecuta si el usuario confirma. */
  function confirmCard(action) {
    var card = node("div", "tpai-confirm");
    card.appendChild(node("div", "tpai-confirm__title", action.title));
    var list = node("ul");
    (action.details || []).forEach(function (d) { list.appendChild(node("li", null, d)); });
    card.appendChild(list);

    var buttons = node("div", "tpai-confirm__buttons");
    var ok = node("button", "tp-btn tp-btn--primary tp-btn--sm", action.confirm || t("confirm"));
    var cancel = node("button", "tp-btn tp-btn--secondary tp-btn--sm", t("cancel"));
    ok.type = cancel.type = "button";
    buttons.appendChild(ok);
    buttons.appendChild(cancel);
    card.appendChild(buttons);

    var result = node("div", "tpai-confirm__result");
    card.appendChild(result);

    function finish(url) {
      ok.disabled = cancel.disabled = true;
      result.textContent = t("thinking");
      buttons.hidden = true;
      fetch(url.replace("__TOKEN__", encodeURIComponent(action.token)), {
        method: "POST",
        credentials: "same-origin",
        headers: headers({ "Content-Type": "application/json" }),
        body: JSON.stringify({ conversation_id: state.conversationId }),
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          result.textContent = data.message || (r.ok ? tpT('done_short') : t("error"));
          card.classList.add(r.ok && data.status !== "cancelled" ? "is-done" : "is-closed");
        });
      }).catch(function () {
        result.textContent = t("error");
        ok.disabled = cancel.disabled = false;
        buttons.hidden = false;
      });
    }

    ok.addEventListener("click", function () { finish(cfg.confirm); });
    cancel.addEventListener("click", function () { finish(cfg.cancel); });

    return card;
  }

  /* ---------------- Burbujas ---------------- */

  function hideWelcome() { el.welcome.hidden = true; }

  function addUserBubble(text) {
    hideWelcome();
    el.messages.appendChild(node("div", "tpai-msg tpai-msg--user", text));
    scrollDown();
  }

  function createBotBubble() {
    hideWelcome();
    var wrap = node("div", "tpai-msg tpai-msg--bot");
    var sources = node("details", "tpai-sources");
    sources.hidden = true;
    sources.appendChild(node("summary"));
    sources.appendChild(node("ul"));
    var text = node("div", "tpai-text");
    var typing = node("div", "tpai-typing");
    typing.innerHTML = "<span></span><span></span><span></span>";
    typing.setAttribute("aria-label", t("thinking"));
    var actions = node("div", "tpai-actions");
    var periods = node("div", "tpai-periods");
    wrap.appendChild(sources);
    wrap.appendChild(typing);
    wrap.appendChild(text);
    wrap.appendChild(actions);
    wrap.appendChild(periods);
    el.messages.appendChild(wrap);
    scrollDown();

    var bubble = {
      raw: "",
      setText: function (raw) {
        bubble.raw = raw;
        var hasPeriods = raw.indexOf(PERIOD_TAG) !== -1;
        text.innerHTML = renderMarkdown(raw.split(PERIOD_TAG).join("").trim());
        if (raw) typing.hidden = true;
        periods.innerHTML = "";
        if (hasPeriods) {
          (cfg.i18n.periods || []).forEach(function (label) {
            var chip = node("button", "tpai__chip", label);
            chip.type = "button";
            chip.addEventListener("click", function () { ask(label); });
            periods.appendChild(chip);
          });
        }
      },
      appendText: function (delta) {
        // Texto que llega despues de una consulta: parrafo nuevo.
        var sep = bubble.breakNext && bubble.raw.trim() ? "\n\n" : "";
        bubble.breakNext = false;
        bubble.setText(bubble.raw.replace(/\s+$/, sep ? "" : bubble.raw.match(/\s*$/)[0]) + sep + delta);
      },
      addSource: function (label) {
        bubble.breakNext = true;
        sources.hidden = false;
        sources.querySelector("ul").appendChild(node("li", null, label));
        var count = sources.querySelectorAll("li").length;
        var first = sources.querySelector("li").textContent;
        sources.querySelector("summary").textContent = t("consulted") + " " + first + (count > 1 ? " (+" + (count - 1) + ")" : "");
      },
      addAction: function (action, fromHistory) {
        if (action.type === "confirm") {
          // Una tarjeta del historial ya no se puede confirmar (vence a los 15 min).
          if (fromHistory) {
            var old = node("div", "tpai-confirm is-closed");
            old.appendChild(node("div", "tpai-confirm__title", action.title));
            actions.appendChild(old);
          } else {
            actions.appendChild(confirmCard(action));
          }
          return;
        }
        var btn = node("button", "tp-btn tp-btn--outline tp-btn--sm", action.label || tpT('open'));
        btn.type = "button";
        btn.addEventListener("click", function () { runAction(action, btn); });
        actions.appendChild(btn);
      },
      error: function (message) {
        typing.hidden = true;
        wrap.classList.add("tpai-msg--error");
        if (!bubble.raw) text.textContent = message;
        else text.appendChild(node("p", null, message));
      },
      finish: function () { typing.hidden = true; },
    };

    return bubble;
  }

  /* ---------------- Enviar pregunta (streaming) ---------------- */

  function ask(question) {
    question = (question || "").trim();
    if (!question || state.busy) return;

    showChat();
    setBusy(true);
    addUserBubble(question);
    var bubble = createBotBubble();

    fetch(cfg.chat, {
      method: "POST",
      credentials: "same-origin",
      headers: headers({ "Content-Type": "application/json", Accept: "text/event-stream" }),
      body: JSON.stringify({ message: question, conversation_id: state.conversationId, screen: cfg.screen }),
    }).then(function (response) {
      var type = response.headers.get("Content-Type") || "";
      if (!response.ok || type.indexOf("text/event-stream") === -1) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          throw new Error(data.message || t("error"));
        });
      }
      return readStream(response, bubble);
    }).catch(function (err) {
      bubble.error(err && err.message ? err.message : t("error"));
    }).then(function () {
      bubble.finish();
      setBusy(false);
      scrollDown();
    });
  }

  function readStream(response, bubble) {
    var reader = response.body.getReader();
    var decoder = new TextDecoder("utf-8");
    var buffer = "";

    function handle(eventName, data) {
      switch (eventName) {
        case "conversation": state.conversationId = data.id; saveState(); break;
        case "delta": bubble.appendText(data.text || ""); scrollDown(); break;
        case "source": bubble.addSource(data.label); break;
        case "action": bubble.addAction(data); break;
        case "done": setQuota(data.remaining); break;
        case "error": bubble.error(data.message || t("error")); break;
      }
    }

    function pump() {
      return reader.read().then(function (result) {
        if (result.done) return;
        buffer += decoder.decode(result.value, { stream: true });
        var parts = buffer.split("\n\n");
        buffer = parts.pop();
        parts.forEach(function (raw) {
          var name = "message";
          var data = "";
          raw.split("\n").forEach(function (line) {
            if (line.indexOf("event:") === 0) name = line.slice(6).trim();
            else if (line.indexOf("data:") === 0) data += line.slice(5).trim();
          });
          if (!data) return;
          try { handle(name, JSON.parse(data)); } catch (e) { /* evento incompleto */ }
        });
        return pump();
      });
    }

    return pump();
  }

  el.form.addEventListener("submit", function (e) {
    e.preventDefault();
    var q = el.input.value;
    el.input.value = "";
    autosize();
    ask(q);
  });

  el.input.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      el.form.dispatchEvent(new Event("submit", { cancelable: true }));
    }
  });

  function autosize() {
    el.input.style.height = "auto";
    el.input.style.height = Math.min(el.input.scrollHeight, 140) + "px";
  }
  el.input.addEventListener("input", autosize);

  /* ---------------- Sugerencias ---------------- */

  function loadSuggestions() {
    request("GET", cfg.suggestions).then(function (data) {
      el.suggestions.innerHTML = "";
      (data.suggestions || []).forEach(function (s) {
        var chip = node("button", "tpai__chip", s);
        chip.type = "button";
        chip.addEventListener("click", function () { ask(s); });
        el.suggestions.appendChild(chip);
      });
      setQuota(data.remaining);
    }).catch(function (err) {
      el.suggestions.innerHTML = "";
      el.suggestions.appendChild(node("div", "tpai-empty", err.message));
    });
  }

  /* ---------------- Conversaciones ---------------- */

  function showChat() {
    el.history.hidden = true;
    el.messages.hidden = false;
  }

  function newConversation() {
    if (state.busy) return;
    state.conversationId = null;
    saveState();
    el.messages.innerHTML = "";
    showChat();
    el.welcome.hidden = false;
    el.input.focus();
  }

  function toggleHistory() {
    if (!el.history.hidden) {
      showChat();
      el.welcome.hidden = el.messages.children.length > 0;
      return;
    }
    el.welcome.hidden = true;
    el.messages.hidden = true;
    el.history.hidden = false;
    el.history.innerHTML = "";
    el.history.appendChild(node("div", "tpai-empty", t("thinking")));

    request("GET", cfg.conversations).then(function (data) {
      el.history.innerHTML = "";
      var list = data.conversations || [];
      if (!list.length) {
        el.history.appendChild(node("div", "tpai-empty", t("no_history")));
        return;
      }
      list.forEach(function (c) {
        var item = node("div", "tpai-hist");
        item.setAttribute("role", "button");
        item.tabIndex = 0;
        item.appendChild(node("span", "tpai-hist__t", c.title || "..."));
        item.appendChild(node("span", "tpai-hist__d", (c.updated_at || "").slice(0, 10)));
        var del = node("button", "tp-btn tp-btn--ghost tp-btn--icon tp-btn--sm", "×");
        del.type = "button";
        del.title = t("delete");
        del.setAttribute("aria-label", t("delete"));
        del.addEventListener("click", function (e) {
          e.stopPropagation();
          request("DELETE", cfg.conversations + "/" + c.id).then(function () {
            item.remove();
            if (state.conversationId === c.id) newConversation();
          });
        });
        item.appendChild(del);
        var openIt = function () { loadConversation(c.id); };
        item.addEventListener("click", openIt);
        item.addEventListener("keydown", function (e) { if (e.key === "Enter") openIt(); });
        el.history.appendChild(item);
      });
    }).catch(function (err) {
      el.history.innerHTML = "";
      el.history.appendChild(node("div", "tpai-empty", err.message));
    });
  }

  function loadConversation(id) {
    return request("GET", cfg.conversations + "/" + id).then(function (data) {
      state.conversationId = data.id;
      saveState();
      el.messages.innerHTML = "";
      showChat();
      (data.turns || []).forEach(function (turn) {
        if (turn.role === "user") {
          addUserBubble(turn.text);
        } else {
          var b = createBotBubble();
          (turn.sources || []).forEach(b.addSource);
          b.setText(turn.text || "");
          (turn.actions || []).forEach(function (a) { b.addAction(a, true); });
          b.finish();
        }
      });
      el.welcome.hidden = (data.turns || []).length > 0;
      scrollDown();
    }).catch(function (err) {
      showChat();
      createBotBubble().error(err.message);
    });
  }

  /* Restaura el chat al cambiar de pantalla: abierto o minimizado, con su conversacion. */
  (function restore() {
    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(STATE_KEY) || "null"); } catch (e) {}
    if (!saved) return;
    if (saved.id) loadConversation(saved.id);
    if (saved.open) open();
  })();

  if (document.readyState === "complete") runPendingShortcut();
  else window.addEventListener("load", runPendingShortcut);

  /* Tarjeta de resumen del tablero (si la pagina la tiene). */
  (function dashboardSummary() {
    var card = document.getElementById("tpai-dash");
    if (!card) return;
    var out = document.getElementById("tpai-dash-text");
    request("GET", card.getAttribute("data-url")).then(function (data) {
      out.innerHTML = renderMarkdown(data.text || "") || "<p>-</p>";
      if (data.generated_at) document.getElementById("tpai-dash-time").textContent = data.generated_at;
    }).catch(function (err) {
      out.textContent = err.message;
    });
  })();

  window.tpAiChat = { open: open, close: close, ask: function (q) { open(); ask(q); } };
})();
