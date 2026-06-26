/* =====================================================
 * TalentusPro · Reports · Phase 8
 * - Quick generate: builds URL to legacy /reports/update?generate=1
 *   and opens in a new tab (delegates render/export to legacy engine)
 * - Saved templates card grid → "Generar ahora" / "Editar"
 * - Recent run logs table → download / error detail
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-reports");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");

  var state = {
    templates: [],
    formats: [],
    saved: [],
    logs: [],
    devices: [],
    selected: new Set(),
    legacy: {},
    devicesFilter: "",
  };

  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function $$(s, c) {
    return Array.prototype.slice.call((c || document).querySelectorAll(s));
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
  function fmtBytes(b) {
    if (!b) return "—";
    if (b < 1024) return b + " B";
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + " KB";
    return (b / 1024 / 1024).toFixed(1) + " MB";
  }
  function fmtDate(s) {
    try {
      return new Date(s.replace(" ", "T")).toLocaleString();
    } catch (e) {
      return s;
    }
  }
  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }
  function toLocalInput(d) {
    return (
      d.getFullYear() +
      "-" +
      pad2(d.getMonth() + 1) +
      "-" +
      pad2(d.getDate()) +
      "T" +
      pad2(d.getHours()) +
      ":" +
      pad2(d.getMinutes())
    );
  }

  /* ---------- DATE PRESETS ---------- */
  function applyQuick(key) {
    var now = new Date(),
      from = new Date(now),
      to = new Date(now);
    if (key === "today") {
      from.setHours(0, 0, 0, 0);
      to.setHours(23, 59, 0, 0);
    } else if (key === "yesterday") {
      from.setDate(now.getDate() - 1);
      from.setHours(0, 0, 0, 0);
      to.setDate(now.getDate() - 1);
      to.setHours(23, 59, 0, 0);
    } else if (key === "7d") {
      from.setDate(now.getDate() - 7);
      from.setHours(0, 0, 0, 0);
    } else if (key === "month") {
      from.setDate(1);
      from.setHours(0, 0, 0, 0);
    } else if (key === "lastmonth") {
      from = new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0);
      to = new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 0);
    }
    $("#rep-from").value = toLocalInput(from);
    $("#rep-to").value = toLocalInput(to);
  }

  /* ---------- RENDER ---------- */
  function renderTemplates() {
    var sel = $("#rep-type");
    sel.innerHTML =
      '<option value="">— Selecciona plantilla —</option>' +
      state.templates
        .map(function (t) {
          return (
            '<option value="' +
            t.id +
            '" data-deviceless="' +
            (t.deviceless ? 1 : 0) +
            '">' +
            escapeHtml(t.title) +
            "</option>"
          );
        })
        .join("");
  }

  function renderFormats() {
    var sel = $("#rep-format");
    sel.innerHTML = state.formats
      .map(function (f) {
        return (
          '<option value="' +
          escapeHtml(f.id) +
          '">' +
          escapeHtml(f.title) +
          "</option>"
        );
      })
      .join("");
    sel.value = "pdf";
  }

  function renderDevices() {
    var wrap = $("#tp-rep-devices");
    var q = state.devicesFilter.toLowerCase();
    var list = q
      ? state.devices.filter(function (d) {
          return (d.name + " " + d.plate).toLowerCase().indexOf(q) !== -1;
        })
      : state.devices;

    if (!list.length) {
      wrap.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:24px;">Sin resultados</div>';
      return;
    }

    wrap.innerHTML = list
      .map(function (d) {
        var checked = state.selected.has(d.id);
        return (
          '<label class="tp-reports__device' +
          (checked ? " is-checked" : "") +
          '" data-id="' +
          d.id +
          '">' +
          '<input type="checkbox" data-id="' +
          d.id +
          '"' +
          (checked ? " checked" : "") +
          ">" +
          '<span class="tp-reports__device-name">' +
          escapeHtml(d.name) +
          "</span>" +
          (d.plate
            ? '<span class="tp-reports__device-plate">' +
              escapeHtml(d.plate) +
              "</span>"
            : "") +
          "</label>"
        );
      })
      .join("");
    updateDevicesCount();
  }
  function updateDevicesCount() {
    $("#tp-rep-devices-count").textContent =
      state.selected.size + " / " + state.devices.length;
  }

  function renderSaved() {
    var grid = $("#tp-rep-saved");
    $("#tp-rep-saved-count").textContent = state.saved.length + " plantilla(s)";
    if (!state.saved.length) {
      grid.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:32px; grid-column:1/-1;">No tienes plantillas guardadas. <a href="' +
        escapeHtml(state.legacy.list || "/reports") +
        '" target="_blank" rel="noopener">Crear la primera</a>.</div>';
      return;
    }
    grid.innerHTML = state.saved
      .map(function (r) {
        var schedule = r.schedule.length
          ? r.schedule
              .map(function (s) {
                return (
                  '<span class="tp-reports__chip">' + escapeHtml(s) + "</span>"
                );
              })
              .join("")
          : '<span class="tp-reports__chip tp-reports__chip--muted">manual</span>';
        var email = r.has_email
          ? '<span class="tp-reports__chip tp-reports__chip--muted">email</span>'
          : "";
        var dev =
          r.devices_count > 0
            ? '<span class="tp-reports__chip tp-reports__chip--muted">' +
              r.devices_count +
              " vehículos</span>"
            : "";

        return (
          "" +
          '<article class="tp-reports__card" data-id="' +
          r.id +
          '">' +
          '<header class="tp-reports__card-head">' +
          '<div class="tp-reports__card-title" title="' +
          escapeHtml(r.title) +
          '">' +
          escapeHtml(r.title) +
          "</div>" +
          '<span class="tp-reports__card-format">' +
          escapeHtml(r.format || "—") +
          "</span>" +
          "</header>" +
          '<div class="tp-text-muted tp-text-xs">' +
          escapeHtml(r.type_label) +
          "</div>" +
          '<div class="tp-reports__card-meta">' +
          schedule +
          dev +
          email +
          "</div>" +
          '<div class="tp-reports__card-actions">' +
          '<button class="tp-btn tp-btn--primary tp-btn--xs" data-action="run">Generar ahora</button>' +
          '<a class="tp-btn tp-btn--ghost tp-btn--xs" data-action="edit" href="' +
          escapeHtml((state.legacy.edit || "/reports") + "/" + r.id + "/edit") +
          '" target="_blank" rel="noopener">Editar</a>' +
          "</div>" +
          "</article>"
        );
      })
      .join("");
  }

  function renderLogs() {
    var body = $("#tp-rep-logs-body");
    $("#tp-rep-logs-count").textContent = state.logs.length + " registro(s)";
    if (!state.logs.length) {
      body.innerHTML =
        '<tr><td colspan="6" class="tp-text-muted tp-text-center" style="padding:24px;">Sin ejecuciones recientes.</td></tr>';
      return;
    }
    body.innerHTML = state.logs
      .map(function (l) {
        var status, label;
        if (l.error) {
          status = "err";
          label = "Error";
        } else if (l.is_send || l.is_upload) {
          status = "ok";
          label = l.is_send ? "Enviado" : "Subido";
        } else {
          status = "idle";
          label = "Generado";
        }

        return (
          "<tr>" +
          '<td><span style="white-space:nowrap;">' +
          escapeHtml(fmtDate(l.created_at)) +
          "</span></td>" +
          "<td>" +
          escapeHtml(l.title) +
          "</td>" +
          '<td><span class="tp-reports__card-format">' +
          escapeHtml(l.format) +
          "</span></td>" +
          "<td>" +
          escapeHtml(fmtBytes(l.size)) +
          "</td>" +
          '<td><span class="tp-reports__status tp-reports__status--' +
          status +
          '" title="' +
          escapeHtml(l.error || "") +
          '">' +
          label +
          "</span></td>" +
          '<td style="text-align:right;">' +
          '<a class="tp-btn tp-btn--ghost tp-btn--xs" href="' +
          escapeHtml(l.download) +
          '" target="_blank" rel="noopener">Descargar</a>' +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  /* ---------- BUILD LEGACY URL ---------- */
  function buildGenerateUrl() {
    var type = $("#rep-type").value;
    var format = $("#rep-format").value;
    var fromLocal = $("#rep-from").value,
      toLocal = $("#rep-to").value;
    if (!type) {
      alert("Selecciona una plantilla.");
      return null;
    }
    if (!format) {
      alert("Selecciona un formato.");
      return null;
    }
    if (!fromLocal || !toLocal) {
      alert("Selecciona el rango de fechas.");
      return null;
    }

    // Legacy expects date_from=YYYY-MM-DD, from_time=HH:MM
    var fromParts = fromLocal.split("T");
    var toParts = toLocal.split("T");

    var params = new URLSearchParams();
    params.set("type", type);
    params.set("format", format);
    params.set("date_from", fromParts[0]);
    params.set("from_time", fromParts[1] || "00:00");
    params.set("date_to", toParts[0]);
    params.set("to_time", toParts[1] || "23:59");
    params.set("generate", "1");
    params.set("view", "0"); // download
    params.set("show_addresses", "0");
    params.set("stops", "180");

    // Devices
    var opt = $("#rep-type").options[$("#rep-type").selectedIndex];
    var deviceless = opt && opt.getAttribute("data-deviceless") === "1";
    if (!deviceless) {
      if (!state.selected.size) {
        alert("Selecciona al menos un vehículo (o todos).");
        return null;
      }
      state.selected.forEach(function (id) {
        params.append("devices[]", id);
      });
    }

    return (
      (state.legacy.generate || "/reports/update") + "?" + params.toString()
    );
  }

  /* ---------- LOAD ---------- */
  function load() {
    return fetch(DATA_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.error) || "load_failed");
        state.templates = j.templates || [];
        state.formats = j.formats || [];
        state.saved = j.saved || [];
        state.logs = j.logs || [];
        state.devices = j.devices || [];
        state.legacy = j.legacy || {};
        renderTemplates();
        renderFormats();
        renderDevices();
        renderSaved();
        renderLogs();
      })
      .catch(function (e) {
        $("#tp-rep-saved").innerHTML =
          '<div class="tp-text-muted tp-text-center" style="padding:24px; grid-column:1/-1;">Error: ' +
          escapeHtml(e.message || e) +
          "</div>";
      });
  }

  /* ---------- EVENTS ---------- */
  function bind() {
    $("#tp-rep-quick").addEventListener("click", function (e) {
      var b = e.target.closest("[data-quick]");
      if (!b) return;
      $$("#tp-rep-quick .tp-seg__btn").forEach(function (x) {
        x.classList.remove("is-active");
      });
      b.classList.add("is-active");
      applyQuick(b.getAttribute("data-quick"));
    });

    // Device list
    $("#tp-rep-devices").addEventListener("change", function (e) {
      var input = e.target.closest('input[type="checkbox"]');
      if (!input) return;
      var id = parseInt(input.getAttribute("data-id"), 10);
      if (input.checked) state.selected.add(id);
      else state.selected.delete(id);
      input
        .closest(".tp-reports__device")
        .classList.toggle("is-checked", input.checked);
      updateDevicesCount();
    });
    $("#tp-rep-devices-all").addEventListener("click", function () {
      state.devices.forEach(function (d) {
        state.selected.add(d.id);
      });
      renderDevices();
    });
    $("#tp-rep-devices-none").addEventListener("click", function () {
      state.selected.clear();
      renderDevices();
    });

    var qTimer = null;
    $("#tp-rep-devices-q").addEventListener("input", function (e) {
      clearTimeout(qTimer);
      qTimer = setTimeout(function () {
        state.devicesFilter = e.target.value;
        renderDevices();
      }, 200);
    });

    // Show/hide device section based on template deviceless flag
    $("#rep-type").addEventListener("change", function () {
      var opt = $("#rep-type").options[$("#rep-type").selectedIndex];
      var deviceless = opt && opt.getAttribute("data-deviceless") === "1";
      $("#tp-rep-devices-wrap").style.display = deviceless ? "none" : "";
    });

    $("#tp-rep-go").addEventListener("click", function () {
      var url = buildGenerateUrl();
      if (url) window.open(url, "_blank", "noopener");
    });

    // Saved card actions (event delegation)
    $("#tp-rep-saved").addEventListener("click", function (e) {
      var btn = e.target.closest('[data-action="run"]');
      if (!btn) return;
      var card = btn.closest(".tp-reports__card");
      var id = card && card.getAttribute("data-id");
      if (!id) return;
      // Legacy supports ?id=X&generate=1 to run an existing saved report.
      var url =
        (state.legacy.generate || "/reports/update") +
        "?" +
        new URLSearchParams({ id: id, generate: "1", view: "0" }).toString();
      window.open(url, "_blank", "noopener");
    });
  }

  /* ---------- INIT ---------- */
  function init() {
    bind();
    applyQuick("yesterday");
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
