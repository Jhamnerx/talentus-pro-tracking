/* =====================================================
 * TalentusPro · tp-socket — tiempo real de la UI v2
 * -----------------------------------------------------
 * Mismo servidor y mismo canal que el legacy (socket/socket.js): PHP
 * publica {event, data} en Redis y el servidor de sockets lo reemite a
 * la sala del usuario (getSocketChannel() = md5 de su tipo + id).
 *
 * Lo que llega por aqui, en vez de sondearse:
 *   notice                 avisos del servidor  -> toast
 *   message                mensaje de chat
 *   device_camera_create   foto nueva de una camara
 *
 * Los consumidores se suscriben con tpSocket.on(evento, fn) SIN esperar
 * a que haya conexion: las suscripciones se guardan y se enganchan al
 * conectar. Y con tpSocket.onStatus(fn) se enteran de si hay push o no,
 * para poder caer a sondeo cuando el servidor de sockets no responde.
 * ===================================================== */
(function () {
  "use strict";

  var CFG = (window.Talentus && window.Talentus.socket) || {};

  // Registro propio: guarda las suscripciones aunque aun no haya socket,
  // asi el orden de carga de los scripts deja de importar.
  var manejadores = {};
  var observadoresEstado = [];
  var socket = null;
  var conectado = false;

  function avisaEstado() {
    observadoresEstado.forEach(function (fn) {
      try { fn(conectado); } catch (e) {}
    });
  }

  function engancha(evento) {
    if (!socket) return;

    socket.on(evento, function (payload) {
      (manejadores[evento] || []).forEach(function (fn) {
        // Un manejador que falle no puede tumbar a los demas ni al socket
        try { fn(payload); } catch (e) {}
      });
    });
  }

  function conecta() {
    if (!window.io || !CFG.channel || !CFG.port) {
      return; // sin cliente, sin canal o sin puerto: todo sigue por sondeo
    }

    var esHttps = location.protocol === "https:";
    var url = (esHttps ? "https://" : "http://") + location.hostname + ":" +
      (esHttps ? CFG.sslPort : CFG.port);

    socket = window.io(url, {
      // Sin esto reintenta cada ~5 s para siempre y, con el servidor de
      // sockets apagado (tipico en desarrollo), llena la consola de errores.
      // Se mantiene el reintento infinito, pero espaciandolo.
      reconnectionDelay: 2000,
      reconnectionDelayMax: 30000,
      timeout: 8000,
    });

    socket.on("connect", function () {
      socket.emit("join", CFG.channel);
      conectado = true;
      avisaEstado();
    });

    socket.on("disconnect", function () {
      conectado = false;
      avisaEstado();
    });

    // connect_error se dispara en cada reintento: se ignora en silencio,
    // el estado ya lo llevan 'connect' y 'disconnect'.
    socket.on("connect_error", function () {});

    Object.keys(manejadores).forEach(engancha);
  }

  window.tpSocket = {
    /** Suscribe a un evento. Vale llamarlo antes de que haya conexion. */
    on: function (evento, fn) {
      if (typeof fn !== "function") return;

      var nuevo = !manejadores[evento];
      manejadores[evento] = manejadores[evento] || [];
      manejadores[evento].push(fn);

      if (nuevo) engancha(evento);
    },

    off: function (evento, fn) {
      var lista = manejadores[evento];
      if (!lista) return;

      var i = lista.indexOf(fn);
      if (i !== -1) lista.splice(i, 1);
    },

    /** true cuando hay push. Los consumidores sondean solo si es false. */
    isConnected: function () {
      return conectado;
    },

    /** Avisa al cambiar la conexion, y de inmediato con el estado actual. */
    onStatus: function (fn) {
      if (typeof fn !== "function") return;

      observadoresEstado.push(fn);
      try { fn(conectado); } catch (e) {}
    },
  };

  conecta();

  /* ----- Avisos del servidor -----------------------------------------
     Es comportamiento de toda la aplicacion (el legacy lo tiene igual en
     app.initSocket), asi que vive aqui y no en cada pagina. */
  window.tpSocket.on("notice", function (data) {
    if (!data || !data.message || !window.tpModal) return;

    var TIPOS = { success: "success", error: "error", warning: "warning", info: "info" };

    tpModal.toast(data.message, TIPOS[data.type] || "info");
  });
})();
