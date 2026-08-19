/* =====================================================================
 * tp-admin-popups.js · Avisos del administrador
 * ---------------------------------------------------------------------
 * Paridad Frontend/Popups/index.blade: los popups (posición distinta de
 * "top") se abren 5 s después de cargar la página. Las franjas superiores
 * las pinta el propio blade y aquí solo se cierran.
 * ===================================================================== */
(function () {
  "use strict";

  // Cierre de las franjas superiores
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".tp-adminbanner__close");
    if (!btn) return;
    var banner = btn.closest(".tp-adminbanner");
    if (banner) banner.remove();
  });

  var node = document.getElementById("tp-admin-popups");
  if (!node || !window.tpModal) return;

  var items;
  try {
    items = JSON.parse(node.textContent || "[]");
  } catch (e) {
    items = [];
  }
  if (!items.length) return;

  // Mismo retardo que el legacy (5 s tras load) y en cascada para no
  // apilar varios modales a la vez.
  window.setTimeout(function () {
    items.forEach(function (p, i) {
      window.setTimeout(function () {
        tpModal.open({
          title: p.title || "Aviso",
          size: "md",
          body: '<div class="tp-admin-popup__body">' + p.content + "</div>",
          buttons: [{ label: "Entendido", variant: "primary", close: true }],
        });
      }, i * 400);
    });
  }, 5000);
})();
