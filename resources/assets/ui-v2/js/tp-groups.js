/* =====================================================================
 * tp-groups.js · Gestor de grupos (geocercas y POIs)
 * ---------------------------------------------------------------------
 * Paridad con GeofencesGroups/PoisGroups del legacy: crear y editar un
 * grupo asignándole elementos, y eliminarlo (los elementos quedan sin
 * grupo, nunca se borran).
 *
 * Uso:
 *   tpGroups.open({
 *     title: "Grupos de geocercas",
 *     base: "/ui/v2/api/zone-groups",  // POST · PUT/{id} · DELETE/{id}
 *     itemsKey: "geofences",           // clave del payload con los ids
 *     groups: [{id, title}],
 *     items: [{id, name, color}],      // universo asignable
 *     groupOf: function (item) {…},    // → id de grupo del item (o 0)
 *     onSaved: function () {…},        // recargar el feed del módulo
 *   });
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function toast(msg, kind) {
    if (window.tpModal && tpModal.toast) tpModal.toast(msg, kind);
  }

  /* ---------- Formulario de un grupo (crear / editar) ---------- */
  function openForm(cfg, group, afterSave) {
    var isEdit = !!group;
    var checked = {};
    if (isEdit) {
      (cfg.items || []).forEach(function (it) {
        if (Number(cfg.groupOf(it) || 0) === Number(group.id)) checked[it.id] = true;
      });
    }

    var body =
      '<div class="tp-rgroup-form">' +
      '<div class="tp-field"><label class="tp-field__label">' + tpT('ui.title') + '</label>' +
      '<input type="text" class="tp-input" data-grp-title placeholder="Insertar título…" value="' +
      esc(isEdit ? group.title : "") + '"></div>' +
      '<div class="tp-field"><div class="tp-rgroup-form__bar">' +
      '<label class="tp-field__label" style="margin:0;">' + esc(cfg.itemsLabel || "Elementos") + "</label>" +
      '<div class="tp-rgroup-form__sel">' +
      '<button type="button" class="tp-linkbtn" data-grp-all>' + tpT('ui.select_all_n') + '</button>' +
      '<button type="button" class="tp-linkbtn" data-grp-none>Deseleccionar todo</button>' +
      "</div></div>" +
      '<div class="tp-input-wrap tp-mt-2"><input type="text" class="tp-input tp-input--sm" data-grp-search placeholder="' + tpT('ui.search_ellipsis') + '"></div>' +
      '<div class="tp-rgroup-list" data-grp-list></div></div>' +
      "</div>";

    tpModal.open({
      title: isEdit ? tpT('ui.edit_group') : tpT('ui.new_group'),
      size: "md",
      body: body,
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('global.save'),
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var title = (dialog.querySelector("[data-grp-title]").value || "").trim();
            if (!title) {
              toast(tpT('ui.title_required'), "warning");
              return false;
            }
            var ids = [];
            dialog.querySelectorAll("[data-grp-item]:checked").forEach(function (c) {
              ids.push(parseInt(c.value, 10));
            });

            var payload = { title: title };
            payload[cfg.itemsKey] = ids;

            btn.disabled = true;
            tpModal
              .fetchJSON(isEdit ? cfg.base + "/" + group.id : cfg.base, {
                method: isEdit ? "PUT" : "POST",
                json: payload,
              })
              .then(function (res) {
                btn.disabled = false;
                if (res && res.ok && res.data && res.data.ok) {
                  toast(tpT('ui.changes_saved'), "success");
                  tpModal.close(dialog);
                  if (typeof afterSave === "function") afterSave();
                } else {
                  var errs = (res.data && res.data.errors) || {};
                  var first = Object.keys(errs)[0];
                  toast(tpModal.firstError(errs, tpT('ui.save_failed')), "error");
                }
              })
              .catch(function () {
                btn.disabled = false;
                toast(tpT('ui.network_error'), "error");
              });
            return false;
          },
        },
      ],
      onMount: function (dialog) {
        var listEl = dialog.querySelector("[data-grp-list]");
        var render = function (q) {
          q = (q || "").toLowerCase();
          listEl.innerHTML = (cfg.items || [])
            .filter(function (it) {
              return !q || String(it.name || "").toLowerCase().indexOf(q) !== -1;
            })
            .map(function (it) {
              return (
                '<label class="tp-rgroup-item"><input type="checkbox" data-grp-item value="' + it.id + '"' +
                (checked[it.id] ? " checked" : "") + ">" +
                (it.color ? '<span class="tp-zone-row__swatch" style="background:' + esc(it.color) + '"></span>' : "") +
                "<span>" + esc(it.name || "#" + it.id) + "</span></label>"
              );
            })
            .join("");
        };
        render("");

        // La selección persiste al filtrar: se recuerda antes de re-render.
        var remember = function () {
          listEl.querySelectorAll("[data-grp-item]").forEach(function (c) {
            checked[c.value] = c.checked;
          });
        };
        dialog.querySelector("[data-grp-search]").addEventListener("input", function (e) {
          remember();
          render(e.target.value);
        });
        dialog.querySelector("[data-grp-all]").addEventListener("click", function () {
          listEl.querySelectorAll("[data-grp-item]").forEach(function (c) { c.checked = true; });
          remember();
        });
        dialog.querySelector("[data-grp-none]").addEventListener("click", function () {
          listEl.querySelectorAll("[data-grp-item]").forEach(function (c) { c.checked = false; });
          remember();
        });
        listEl.addEventListener("change", remember);
      },
    });
  }

  /* ---------- Listado de grupos ---------- */
  function open(cfg) {
    if (!window.tpModal) return;

    var dlg = tpModal.open({
      title: cfg.title || tpT('front.groups'),
      size: "md",
      body: '<div data-grp-index></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-grp-index]");

        var counts = function (id) {
          return (cfg.items || []).filter(function (it) {
            return Number(cfg.groupOf(it) || 0) === Number(id);
          }).length;
        };

        var render = function () {
          var rows = (cfg.groups || []).length
            ? (cfg.groups || [])
                .map(function (g) {
                  return (
                    '<div class="tp-acc-row"><span>' + esc(g.title) +
                    ' <span class="tp-text-xs tp-text-muted">(' + counts(g.id) + ")</span></span>" +
                    '<span class="tp-row" style="gap:6px;">' +
                    '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-grp-edit="' + g.id + '">' + tpT('global.edit') + '</button>' +
                    '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-grp-del="' + g.id + '" style="color:#ef4444;">' + tpT('global.delete') + '</button>' +
                    "</span></div>"
                  );
                })
                .join("")
            : '<div class="tp-text-muted tp-text-sm">' + tpT('ui.no_groups_yet') + '</div>';

          host.innerHTML =
            '<div class="tp-row" style="justify-content:flex-end;margin-bottom:8px;">' +
            '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-grp-add>' + tpT('ui.new_group_plus') + '</button></div>' +
            rows;
        };
        render();

        // Cada cambio recarga el feed del módulo y cierra este modal: la
        // lista de grupos y las asignaciones se re-pintan desde el servidor.
        var reload = function () {
          tpModal.close(dialog);
          if (typeof cfg.onSaved === "function") cfg.onSaved();
        };

        host.addEventListener("click", function (e) {
          var add = e.target.closest("[data-grp-add]");
          if (add) return openForm(cfg, null, reload);

          var ed = e.target.closest("[data-grp-edit]");
          if (ed) {
            var gid = parseInt(ed.getAttribute("data-grp-edit"), 10);
            var g = (cfg.groups || []).filter(function (x) { return Number(x.id) === gid; })[0];
            return openForm(cfg, g, reload);
          }

          var del = e.target.closest("[data-grp-del]");
          if (del) {
            var did = del.getAttribute("data-grp-del");
            tpModal
              .confirm({
                title: tpT('ui.delete_group'),
                message: tpT('ui.delete_group_note'),
                danger: true,
                confirmLabel: tpT('global.delete'),
              })
              .then(function (ok) {
                if (!ok) return;
                tpModal.fetchJSON(cfg.base + "/" + did, { method: "DELETE" }).then(function (res) {
                  if (res && res.ok && res.data && res.data.ok) {
                    toast(tpT('ui.group_deleted'), "success");
                    reload();
                  } else {
                    toast(tpT('ui.delete_failed'), "error");
                  }
                });
              });
          }
        });
      },
    });

    return dlg;
  }

  window.tpGroups = { open: open };
})();
