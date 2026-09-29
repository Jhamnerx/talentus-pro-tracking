/* =====================================================
 * TalentusPro · tp-chat — Chat con dispositivos (2 paneles)
 * -----------------------------------------------------
 * Izquierda: lista de vehículos chateables con no-leídos (búsqueda).
 * Derecha: hilo de la conversación + composer. Lee por polling
 * (chat/{id}/messages?time=) y envía por chat/{id}/send. Reutiliza el
 * backend legacy (Chat/ChatMessage/ChatService). Sin dependencia de
 * socket en el front: el polling trae los mensajes nuevos.
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-chat-page");
  if (!root) return;

  var URLS = {
    conversations: root.getAttribute("data-conversations-url"),
    open: root.getAttribute("data-open-url"),
    messages: root.getAttribute("data-messages-url"),
    send: root.getAttribute("data-send-url"),
  };

  var convsEl = document.getElementById("tp-chat-convs");
  var searchEl = document.getElementById("tp-chat-search");
  var placeholderEl = document.getElementById("tp-chat-placeholder");
  var convEl = document.getElementById("tp-chat-conv");
  var nameEl = document.getElementById("tp-chat-conv-name");
  var msgsEl = document.getElementById("tp-chat-messages");
  var composerEl = document.getElementById("tp-chat-composer");
  var inputEl = document.getElementById("tp-chat-input");

  var state = { chatId: null, deviceId: null, lastTime: 0, s: "" };
  var listTimer = null, msgTimer = null, searchTimer = null;

  function esc(s) { return window.tpModal ? tpModal.escapeHtml(s) : String(s == null ? "" : s); }
  function get(url) {
    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } }).then(function (r) { return r.json(); });
  }
  function post(url, data) {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return fetch(url, {
      method: "POST", credentials: "same-origin",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": meta ? meta.getAttribute("content") : "" },
      body: JSON.stringify(data),
    }).then(function (r) { return r.json(); });
  }

  /* ----- Lista de conversaciones ----- */
  function loadConversations() {
    return get(URLS.conversations + "?s=" + encodeURIComponent(state.s)).then(function (res) {
      if (!res || !res.ok) return;
      var items = res.items || [];
      convsEl.innerHTML = items.length
        ? items.map(function (c) {
            return (
              '<li class="tp-chat__conv-item' + (state.deviceId === c.device_id ? " is-active" : "") + '" data-device="' + c.device_id + '">' +
              '<span class="tp-chat__avatar">' + esc((c.name || "?").slice(0, 2).toUpperCase()) + "</span>" +
              '<span class="tp-chat__conv-info"><span class="tp-chat__conv-title">' + esc(c.name) + "</span></span>" +
              (c.unread ? '<span class="tp-chat__badge">' + c.unread + "</span>" : "") +
              "</li>"
            );
          }).join("")
        : '<li class="tp-chat__empty">' + tpT('ui.no_vehicles_with_chat') + '</li>';
    });
  }

  convsEl.addEventListener("click", function (e) {
    var li = e.target.closest("[data-device]");
    if (li) openConversation(parseInt(li.getAttribute("data-device"), 10), li);
  });

  searchEl.addEventListener("input", function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { state.s = searchEl.value.trim(); loadConversations(); }, 300);
  });

  /* ----- Abrir conversación ----- */
  function openConversation(deviceId, li) {
    state.deviceId = deviceId;
    state.chatId = null;
    state.lastTime = 0;
    Array.prototype.forEach.call(convsEl.querySelectorAll(".tp-chat__conv-item"), function (n) { n.classList.remove("is-active"); });
    if (li) li.classList.add("is-active");
    var badge = li && li.querySelector(".tp-chat__badge");
    if (badge) badge.remove();

    placeholderEl.hidden = true;
    convEl.hidden = false;
    msgsEl.innerHTML = '<div class="tp-chat__loading">Cargando…</div>';

    get(URLS.open.replace("{id}", deviceId)).then(function (res) {
      if (!res || !res.ok || state.deviceId !== deviceId) return;
      state.chatId = res.chat_id;
      nameEl.textContent = res.name || "";
      msgsEl.innerHTML = "";
      renderMessages(res.messages || [], true);
      inputEl.focus();
      startMsgPoll();
    });
  }

  function renderMessages(msgs, scroll) {
    msgs.forEach(function (m) {
      var t = m.time ? String(m.time).slice(11, 16) : "";
      var el = document.createElement("div");
      el.className = "tp-chat__msg " + (m.mine ? "tp-chat__msg--mine" : "tp-chat__msg--them");
      el.innerHTML =
        (m.mine ? "" : '<span class="tp-chat__msg-sender">' + esc(m.sender) + "</span>") +
        '<span class="tp-chat__bubble">' + esc(m.content) + "</span>" +
        '<span class="tp-chat__msg-time">' + esc(t) + "</span>";
      msgsEl.appendChild(el);
      var ts = m.time ? Math.floor(new Date(m.time.replace(" ", "T")).getTime() / 1000) : 0;
      if (ts > state.lastTime) state.lastTime = ts;
    });
    if (scroll) msgsEl.scrollTop = msgsEl.scrollHeight;
  }

  function pollMessages() {
    if (!state.chatId) return;
    var cid = state.chatId;
    get(URLS.messages.replace("{id}", cid) + "?time=" + state.lastTime).then(function (res) {
      if (!res || !res.ok || state.chatId !== cid) return;
      if (res.messages && res.messages.length) {
        var atBottom = msgsEl.scrollTop + msgsEl.clientHeight >= msgsEl.scrollHeight - 40;
        renderMessages(res.messages, atBottom);
      }
    });
  }
  /* Con push NO se sondea: el socket avisa y se pide la conversacion en ese
     momento. Se deja un sondeo lento de red de seguridad por si se pierde
     algun aviso, y el rapido de siempre cuando no hay socket. */
  var MS_CON_PUSH = 60000;
  var MS_SIN_PUSH = 5000;

  function startMsgPoll() {
    clearInterval(msgTimer);

    var hayPush = window.tpSocket && tpSocket.isConnected();

    msgTimer = setInterval(pollMessages, hayPush ? MS_CON_PUSH : MS_SIN_PUSH);
  }

  /* ----- Enviar ----- */
  composerEl.addEventListener("submit", function (e) {
    e.preventDefault();
    var text = inputEl.value.trim();
    if (!text || !state.chatId) return;
    inputEl.value = "";
    post(URLS.send.replace("{id}", state.chatId), { message: text }).then(function (res) {
      if (res && res.ok) { pollMessages(); loadConversations(); }
      else { inputEl.value = text; if (window.tpModal) tpModal.toast(tpT('ui.send_failed'), "error"); }
    }).catch(function () { inputEl.value = text; });
  });

  /* ----- Tiempo real -----
     Al llegar un mensaje se relee la conversacion en vez de pintar el payload
     del socket: es un viaje de ida y vuelta minimo, pero reusa el mismo camino
     ya probado y no hay que duplicar el formato del mensaje en dos sitios. */
  if (window.tpSocket) {
    tpSocket.on("message", function () {
      pollMessages();
      loadConversations();
    });

    // Si el socket se cae, se vuelve al sondeo rapido; al volver, se espacia.
    tpSocket.onStatus(function () {
      if (state.chatId) startMsgPoll();
    });
  }

  /* ----- Boot + polling de la lista ----- */
  loadConversations();
  listTimer = setInterval(loadConversations, 20000);
})();
