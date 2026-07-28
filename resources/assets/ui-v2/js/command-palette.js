/* ============================================================
   TalentusPro · Command Palette v1.0
   ------------------------------------------------------------
   ⌘K / Ctrl+K global search. Talks to backend search endpoint.
   Vanilla JS, no deps. Debounced. Keyboard navigation.
   ============================================================ */
(function () {
  "use strict";

  var T = window.Talentus || {};
  var panel = document.getElementById("tp-cmdk");
  var input = document.getElementById("tp-cmdk-input");
  var body = document.getElementById("tp-cmdk-body");
  var status = document.getElementById("tp-cmdk-status");
  var openBtn = document.getElementById("tp-open-palette");
  var resultsGroup = body ? body.querySelector('[data-group="results"]') : null;
  var resultsList = body ? body.querySelector("[data-results-list]") : null;
  var resultsTitle = body ? body.querySelector("[data-group-title]") : null;
  var actionsGroup = body ? body.querySelector('[data-group="actions"]') : null;
  var recentsGroup = body ? body.querySelector('[data-group="recents"]') : null;

  if (!panel || !input) return;

  var RECENTS_KEY = "tp_cmdk_recents";
  var MAX_RECENTS = 6;
  var debounceTimer;
  var lastQuery = "";
  var abortCtrl = null;

  function getRecents() {
    try {
      return JSON.parse(localStorage.getItem(RECENTS_KEY) || "[]");
    } catch (e) {
      return [];
    }
  }
  function pushRecent(item) {
    var list = getRecents().filter(function (r) {
      return r.id !== item.id || r.type !== item.type;
    });
    list.unshift(item);
    list = list.slice(0, MAX_RECENTS);
    try {
      localStorage.setItem(RECENTS_KEY, JSON.stringify(list));
    } catch (e) {}
    renderRecents();
  }

  function open() {
    panel.hidden = false;
    setTimeout(function () {
      input.focus();
      input.select();
    }, 0);
    renderRecents();
    updateActive(getItems()[0]);
  }
  function close() {
    panel.hidden = true;
    input.value = "";
    lastQuery = "";
    if (abortCtrl) {
      abortCtrl.abort();
      abortCtrl = null;
    }
    hideResults();
  }

  /* ---------- triggers ---------- */
  if (openBtn) openBtn.addEventListener("click", open);

  panel.querySelectorAll("[data-cmdk-close]").forEach(function (el) {
    el.addEventListener("click", close);
  });

  document.addEventListener("keydown", function (e) {
    var meta = e.metaKey || e.ctrlKey;
    if (meta && e.key && e.key.toLowerCase() === "k") {
      e.preventDefault();
      panel.hidden ? open() : close();
      return;
    }
    if (e.key === "/" && document.activeElement === document.body) {
      e.preventDefault();
      open();
      return;
    }
    if (!panel.hidden) {
      if (e.key === "Escape") {
        e.preventDefault();
        close();
        return;
      }
      if (e.key === "ArrowDown") {
        e.preventDefault();
        moveActive(1);
        return;
      }
      if (e.key === "ArrowUp") {
        e.preventDefault();
        moveActive(-1);
        return;
      }
      if (e.key === "Enter") {
        e.preventDefault();
        activateActive();
        return;
      }
    }
  });

  /* ---------- recents ---------- */
  function renderRecents() {
    if (!recentsGroup) return;
    var list = getRecents();
    if (!list.length) {
      recentsGroup.innerHTML =
        '<div class="tp-cmdk__group-title">Recientes</div><div class="tp-cmdk__empty">Sin elementos recientes.</div>';
      return;
    }
    var html = '<div class="tp-cmdk__group-title">Recientes</div>';
    list.forEach(function (r) {
      html += renderItem(r);
    });
    recentsGroup.innerHTML = html;
    bindItemClicks(recentsGroup);
  }

  /* ---------- input → search ---------- */
  input.addEventListener("input", function () {
    var q = input.value.trim();
    if (q === lastQuery) return;
    lastQuery = q;
    clearTimeout(debounceTimer);

    if (q.length === 0) {
      hideResults();
      if (actionsGroup) actionsGroup.hidden = false;
      if (recentsGroup) recentsGroup.hidden = false;
      status.textContent = "—";
      return;
    }
    if (actionsGroup) actionsGroup.hidden = true;
    if (recentsGroup) recentsGroup.hidden = true;

    debounceTimer = setTimeout(function () {
      runSearch(q);
    }, 180);
  });

  function runSearch(q) {
    if (!T.routes || !T.routes.search) return;
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = window.AbortController ? new AbortController() : null;

    status.textContent = "Buscando…";
    var url = T.routes.search + "?q=" + encodeURIComponent(q);

    fetch(url, {
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      signal: abortCtrl ? abortCtrl.signal : undefined,
    })
      .then(function (r) {
        return r.ok ? r.json() : Promise.reject(r.status);
      })
      .then(function (data) {
        renderResults(q, data);
      })
      .catch(function (err) {
        if (err && err.name === "AbortError") return;
        renderResults(q, { results: [], total: 0 });
        status.textContent = "Error al buscar";
      });
  }

  function renderResults(q, data) {
    if (!resultsGroup || !resultsList) return;
    var results = (data && data.results) || [];
    var total = (data && data.total) || results.length;
    resultsTitle.textContent = "Resultados (" + total + ")";
    if (!results.length) {
      resultsList.innerHTML =
        '<div class="tp-cmdk__empty">Sin resultados para "' +
        escapeHtml(q) +
        '"</div>';
    } else {
      resultsList.innerHTML = results.map(renderItem).join("");
    }
    resultsGroup.hidden = false;
    bindItemClicks(resultsGroup);
    status.textContent = total + " resultado" + (total === 1 ? "" : "s");
    updateActive(getItems()[0]);
  }

  function hideResults() {
    if (resultsGroup) resultsGroup.hidden = true;
    if (resultsList) resultsList.innerHTML = "";
  }

  /* ---------- rendering ---------- */
  var typeIcons = {
    device: "🚛",
    geofence: "📍",
    poi: "⭐",
    user: "👤",
    driver: "👤",
    group: "📁",
    report: "📑",
    action: "⚡",
  };

  function renderItem(it) {
    var icon = typeIcons[it.type] || "•";
    var meta =
      it.meta ||
      (it.type ? it.type.charAt(0).toUpperCase() + it.type.slice(1) : "");
    var sub = it.subtitle
      ? '<div class="tp-cmdk__item-sub">' + escapeHtml(it.subtitle) + "</div>"
      : "";
    var url = it.url ? escapeAttr(it.url) : "";
    var id = it.id != null ? String(it.id) : "";
    return (
      '<button class="tp-cmdk__item" data-action="result" data-type="' +
      escapeAttr(it.type || "") +
      '" data-id="' +
      escapeAttr(id) +
      '" data-url="' +
      url +
      '" data-label="' +
      escapeAttr(it.label || "") +
      '">' +
      '<span class="tp-cmdk__item-icon">' +
      icon +
      "</span>" +
      '<span class="tp-cmdk__item-label"><div>' +
      escapeHtml(it.label || "") +
      "</div>" +
      sub +
      "</span>" +
      '<span class="tp-cmdk__item-meta">' +
      escapeHtml(meta) +
      "</span>" +
      "</button>"
    );
  }

  function bindItemClicks(scope) {
    scope.querySelectorAll(".tp-cmdk__item").forEach(function (el) {
      el.addEventListener("click", function () {
        activateItem(el);
      });
      el.addEventListener("mousemove", function () {
        setActive(el);
      });
    });
  }

  function getItems() {
    return Array.prototype.slice
      .call(panel.querySelectorAll(".tp-cmdk__item:not([hidden])"))
      .filter(function (el) {
        // only consider items whose group is visible
        var g = el.closest(".tp-cmdk__group");
        return g && !g.hidden;
      });
  }
  function setActive(el) {
    getItems().forEach(function (i) {
      i.classList.toggle("is-active", i === el);
    });
  }
  function updateActive(el) {
    if (!el) return;
    setActive(el);
    el.scrollIntoView({ block: "nearest" });
  }
  function moveActive(delta) {
    var items = getItems();
    if (!items.length) return;
    var idx = items.findIndex(function (i) {
      return i.classList.contains("is-active");
    });
    idx = (idx + delta + items.length) % items.length;
    updateActive(items[idx]);
  }
  function activateActive() {
    var el = panel.querySelector(".tp-cmdk__item.is-active") || getItems()[0];
    if (el) activateItem(el);
  }

  function activateItem(el) {
    var action = el.getAttribute("data-action");
    var type = el.getAttribute("data-type");
    var url = el.getAttribute("data-url") || el.getAttribute("data-target");
    var id = el.getAttribute("data-id");
    // Los ítems de acción tienen el label como texto directo (sin <div> interno);
    // los resultados lo llevan en un <div>. Buscar ambos y no explotar si falta.
    var labelEl =
      el.querySelector(".tp-cmdk__item-label div") ||
      el.querySelector(".tp-cmdk__item-label");
    var label =
      el.getAttribute("data-label") ||
      (labelEl ? labelEl.textContent.trim() : "");

    if (action === "theme-toggle") {
      var cur = document.documentElement.getAttribute("data-theme") || "dark";
      window.Talentus.setTheme(cur === "dark" ? "light" : "dark");
      close();
      window.tpToast &&
        window.tpToast({
          title: "Tema cambiado",
          variant: "info",
          duration: 2000,
        });
      return;
    }
    if (action === "density-toggle") {
      var d =
        document.documentElement.getAttribute("data-density") || "comfortable";
      window.Talentus.setDensity(
        d === "comfortable" ? "compact" : "comfortable",
      );
      close();
      window.tpToast &&
        window.tpToast({
          title: "Densidad cambiada",
          variant: "info",
          duration: 2000,
        });
      return;
    }
    if (action === "go" && url) {
      pushRecent({ type: "action", id: "go:" + url, label: label, url: url });
      window.location.href = url;
      return;
    }
    if (action === "result" && type === "device") {
      pushRecent({ type: type, id: id, label: label, url: url, subtitle: "" });
      // Si ya estamos en la página Live, seleccionar el vehículo EN EL SITIO
      // (abre drawer + centra el mapa) sin recargar la página.
      if (window.tpLive && typeof window.tpLive.selectDevice === "function") {
        window.tpLive.selectDevice(parseInt(id, 10));
        close();
        return;
      }
      if (url) {
        window.location.href = url;
        return;
      }
    }
    if (action === "result" && url) {
      pushRecent({ type: type, id: id, label: label, url: url, subtitle: "" });
      window.location.href = url;
      return;
    }
    if (action === "result" && type === "device" && !url) {
      // Fallback: sin tpLive ni url → evento custom.
      document.dispatchEvent(
        new CustomEvent("tp:cmdk:select", {
          detail: { type: type, id: id, label: label },
        }),
      );
      pushRecent({ type: type, id: id, label: label, url: "" });
      close();
      return;
    }
    close();
  }

  /* ---------- helpers ---------- */
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
  function escapeAttr(s) {
    return escapeHtml(s);
  }

  // Init bindings for the static action items
  bindItemClicks(actionsGroup);
})();
