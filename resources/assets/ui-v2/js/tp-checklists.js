/* =====================================================================
 * tp-checklists.js · Listas de verificación (addon checklists)
 * ---------------------------------------------------------------------
 * Dos superficies:
 *   tpChecklists.templates()          → CRUD de plantillas
 *   tpChecklists.forService(id, name) → checklists de un servicio
 *
 * Reglas del legacy que se respetan tal cual (las impone el backend):
 *   · una actividad necesita resultado (pass/fail) y, salvo permiso
 *     checklist_optional_image, al menos una foto para marcarse;
 *   · no se puede firmar con actividades pendientes.
 * ===================================================================== */
(function () {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  var BASE = "/ui/v2/api";

  function fail(res, fallback) {
    var errs = (res.data && res.data.errors) || {};
    var first = Object.keys(errs)[0];
    tpModal.toast(
      first ? [].concat(errs[first])[0] : (res.data && res.data.message) || fallback,
      "error",
    );
  }

  /* ================= Plantillas ================= */

  function templateForm(types, item, afterSave) {
    var isEdit = !!(item && item.id);
    var rows = (item && item.rows) || [];

    var rowHtml = function (id, activity) {
      return (
        '<div class="tp-row" style="gap:6px;margin-bottom:6px;" data-tpl-row>' +
        '<input type="text" class="tp-input" value="' + esc(activity || "") + '"' +
        (id ? ' data-tpl-existing="' + id + '"' : " data-tpl-new") + ' placeholder="Actividad…">' +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-del style="color:#ef4444;flex:0 0 auto;">×</button>' +
        "</div>"
      );
    };

    tpModal.open({
      title: isEdit ? "Editar plantilla" : "Nueva plantilla",
      size: "md",
      body:
        '<div class="tp-form">' +
        '<label class="tp-form__field"><span class="tp-form__label">Nombre</span>' +
        '<input type="text" class="tp-input" data-tpl-name value="' + esc(item ? item.name : "") + '"></label>' +
        '<label class="tp-form__field"><span class="tp-form__label">Tipo</span>' +
        '<select class="tp-input" data-tpl-type>' +
        types
          .map(function (t) {
            return '<option value="' + t.id + '"' + (item && Number(item.type) === Number(t.id) ? " selected" : "") + ">" + esc(t.label) + "</option>";
          })
          .join("") +
        "</select></label>" +
        '<div class="tp-form__field"><span class="tp-form__label">Actividades</span>' +
        '<div data-tpl-rows>' + (rows.length ? rows.map(function (r) { return rowHtml(r.id, r.activity); }).join("") : rowHtml(null, "")) + "</div>" +
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-add style="align-self:flex-start;">＋ Añadir actividad</button>' +
        "</div></div>",
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (d) {
            var name = (d.querySelector("[data-tpl-name]").value || "").trim();
            if (!name) {
              tpModal.toast("El nombre es obligatorio.", "warning");
              return false;
            }

            var existing = {};
            var news = [];
            d.querySelectorAll("[data-tpl-row] input").forEach(function (inp) {
              var v = (inp.value || "").trim();
              if (!v) return;
              var id = inp.getAttribute("data-tpl-existing");
              if (id) existing[id] = v;
              else news.push(v);
            });

            if (!Object.keys(existing).length && !news.length) {
              tpModal.toast("Añade al menos una actividad.", "warning");
              return false;
            }

            tpModal
              .fetchJSON(BASE + "/checklist-templates" + (isEdit ? "/" + item.id : ""), {
                method: isEdit ? "PUT" : "POST",
                json: { name: name, type: d.querySelector("[data-tpl-type]").value, rows: existing, new_rows: news },
              })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Plantilla guardada.", "success");
                  tpModal.close(d);
                  if (typeof afterSave === "function") afterSave();
                } else fail(res, "No se pudo guardar.");
              });
            return false;
          },
        },
      ],
      onMount: function (d) {
        var host = d.querySelector("[data-tpl-rows]");
        d.querySelector("[data-tpl-add]").addEventListener("click", function () {
          host.insertAdjacentHTML("beforeend", rowHtml(null, ""));
        });
        host.addEventListener("click", function (e) {
          var del = e.target.closest("[data-tpl-del]");
          if (!del) return;
          if (host.querySelectorAll("[data-tpl-row]").length > 1) del.closest("[data-tpl-row]").remove();
          else del.closest("[data-tpl-row]").querySelector("input").value = "";
        });
      },
    });
  }

  function templates() {
    if (!window.tpModal) return;

    tpModal.open({
      title: "Plantillas de listas de verificación",
      size: "md",
      body: '<div data-tpl-index><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-tpl-index]");
        var types = [];

        function load() {
          tpModal.fetchJSON(BASE + "/checklist-templates").then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<div class="tp-text-muted tp-text-sm">No disponible.</div>';
              return;
            }
            types = res.data.types || [];
            var items = res.data.items || [];
            host.__items = items;

            host.innerHTML =
              (res.data.can_edit
                ? '<div class="tp-row" style="justify-content:flex-end;margin-bottom:8px;">' +
                  '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-tpl-new>＋ Nueva plantilla</button></div>'
                : "") +
              (items.length
                ? items
                    .map(function (t) {
                      return (
                        '<div class="tp-acc-row"><span><b>' + esc(t.name) + "</b><br>" +
                        '<span class="tp-text-xs tp-text-muted">' + esc(t.type_label) + " · " + t.rows.length + " actividad(es)</span></span>" +
                        (res.data.can_edit
                          ? '<span class="tp-row" style="gap:6px;">' +
                            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-edit="' + t.id + '">Editar</button>' +
                            '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-tpl-rm="' + t.id + '" style="color:#ef4444;">Eliminar</button></span>'
                          : "") +
                        "</div>"
                      );
                    })
                    .join("")
                : '<div class="tp-text-muted tp-text-sm">Todavía no hay plantillas.</div>');
          });
        }
        load();

        host.addEventListener("click", function (e) {
          if (e.target.closest("[data-tpl-new]")) return templateForm(types, null, load);

          var ed = e.target.closest("[data-tpl-edit]");
          if (ed) {
            var tid = parseInt(ed.getAttribute("data-tpl-edit"), 10);
            var t = (host.__items || []).filter(function (x) { return x.id === tid; })[0];
            return templateForm(types, t, load);
          }

          var rm = e.target.closest("[data-tpl-rm]");
          if (rm) {
            tpModal
              .confirm({ title: "Eliminar plantilla", message: "¿Eliminar esta plantilla y sus actividades?", danger: true, confirmLabel: "Eliminar" })
              .then(function (ok) {
                if (!ok) return;
                tpModal
                  .fetchJSON(BASE + "/checklist-templates/" + rm.getAttribute("data-tpl-rm"), { method: "DELETE" })
                  .then(function (res) {
                    if (res.ok && res.data && res.data.ok) {
                      tpModal.toast("Plantilla eliminada.", "success");
                      load();
                    } else fail(res, "No se pudo eliminar.");
                  });
              });
          }
        });
      },
    });
  }

  /* ================= Checklists de un servicio ================= */

  function forService(serviceId, serviceName) {
    if (!window.tpModal) return;

    tpModal.open({
      title: "Listas de verificación" + (serviceName ? " · " + serviceName : ""),
      size: "lg",
      body: '<div data-chk-index><div class="tp-text-muted tp-text-sm">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (dialog) {
        var host = dialog.querySelector("[data-chk-index]");
        var meta = { outcomes: [], imageOptional: false, canEdit: false };

        function load() {
          tpModal.fetchJSON(BASE + "/services/" + serviceId + "/checklists").then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<div class="tp-text-muted tp-text-sm">No disponible.</div>';
              return;
            }
            var d = res.data;
            meta = { outcomes: d.outcomes || [], imageOptional: !!d.image_optional, canEdit: !!d.can_edit };

            var head =
              d.can_add && (d.templates || []).length
                ? '<div class="tp-row" style="gap:6px;margin-bottom:10px;">' +
                  '<select class="tp-input tp-input--sm" data-chk-tpl style="flex:1 1 auto;">' +
                  d.templates.map(function (t) {
                    return '<option value="' + t.id + '">' + esc(t.name) + " (" + esc(t.type_label) + ")</option>";
                  }).join("") +
                  "</select>" +
                  '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-chk-add>＋ Añadir</button></div>'
                : "";

            var body = (d.items || []).length
              ? d.items
                  .map(function (c) {
                    return (
                      '<section class="tp-chk" data-chk="' + c.id + '">' +
                      '<header class="tp-chk__head"><b>' + esc(c.name) + "</b>" +
                      (c.signed
                        ? '<span class="tp-text-xs" style="color:#16a34a;">Firmada ' + esc(c.completed_at || "") + "</span>"
                        : '<span class="tp-row" style="gap:6px;">' +
                          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-chk-sign="' + c.id + '">Firmar</button>' +
                          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-chk-rm="' + c.id + '" style="color:#ef4444;">Eliminar</button></span>') +
                      "</header>" +
                      '<ul class="tp-chk__rows">' +
                      c.rows
                        .map(function (r) {
                          return (
                            '<li class="tp-chk__row' + (r.completed ? " is-done" : "") + '">' +
                            '<label class="tp-chk__act"><input type="checkbox" data-chk-row="' + r.id + '"' +
                            (r.completed ? " checked" : "") + (c.signed || !meta.canEdit ? " disabled" : "") + ">" +
                            "<span>" + esc(r.activity) + "</span></label>" +
                            '<span class="tp-chk__side">' +
                            (c.signed || !meta.canEdit
                              ? '<span class="tp-text-xs tp-text-muted">' + esc(r.outcome || "—") + "</span>"
                              : '<select class="tp-input tp-input--sm" data-chk-outcome="' + r.id + '" style="width:auto;">' +
                                '<option value="">Resultado…</option>' +
                                meta.outcomes.map(function (o) {
                                  return '<option value="' + esc(o.id) + '"' + (r.outcome === o.id ? " selected" : "") + ">" + esc(o.label) + "</option>";
                                }).join("") +
                                "</select>" +
                                (meta.imageOptional
                                  ? ""
                                  : '<label class="tp-btn tp-btn--ghost tp-btn--sm" style="cursor:pointer;">📷' +
                                    '<input type="file" accept="image/*" hidden data-chk-img="' + r.id + '"></label>')) +
                            ((r.images || []).length
                              ? '<span class="tp-text-xs tp-text-muted">' + r.images.length + " foto(s)</span>"
                              : "") +
                            "</span></li>"
                          );
                        })
                        .join("") +
                      "</ul>" +
                      (c.notes ? '<p class="tp-text-xs tp-text-muted" style="margin:6px 0 0;">' + esc(c.notes) + "</p>" : "") +
                      "</section>"
                    );
                  })
                  .join("")
              : '<div class="tp-text-muted tp-text-sm">Este servicio no tiene listas de verificación.</div>';

            host.innerHTML = head + body;
          });
        }
        load();

        host.addEventListener("change", function (e) {
          var chk = e.target.closest("[data-chk-row]");
          if (chk) {
            return tpModal
              .fetchJSON(BASE + "/checklist-rows/" + chk.getAttribute("data-chk-row"), {
                method: "PUT",
                json: { completed: chk.checked ? 1 : 0 },
              })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) load();
                else {
                  fail(res, "No se pudo actualizar.");
                  chk.checked = !chk.checked;
                }
              });
          }

          var out = e.target.closest("[data-chk-outcome]");
          if (out && out.value) {
            return tpModal
              .fetchJSON(BASE + "/checklist-rows/" + out.getAttribute("data-chk-outcome"), {
                method: "PUT",
                json: { outcome: out.value },
              })
              .then(function (res) {
                if (!res.ok || !res.data || !res.data.ok) fail(res, "No se pudo guardar el resultado.");
              });
          }

          var img = e.target.closest("[data-chk-img]");
          if (img && img.files && img.files[0]) {
            var fd = new FormData();
            fd.append("file", img.files[0]);
            return tpModal
              .fetchJSON(BASE + "/checklist-rows/" + img.getAttribute("data-chk-img") + "/image", { method: "POST", body: fd })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) {
                  tpModal.toast("Foto subida.", "success");
                  load();
                } else fail(res, "No se pudo subir la foto.");
              });
          }
        });

        host.addEventListener("click", function (e) {
          if (e.target.closest("[data-chk-add]")) {
            var sel = host.querySelector("[data-chk-tpl]");
            return tpModal
              .fetchJSON(BASE + "/services/" + serviceId + "/checklists", { method: "POST", json: { template_id: sel.value } })
              .then(function (res) {
                if (res.ok && res.data && res.data.ok) load();
                else fail(res, "No se pudo crear.");
              });
          }

          var rm = e.target.closest("[data-chk-rm]");
          if (rm) {
            return tpModal
              .confirm({ title: "Eliminar lista", message: "¿Eliminar esta lista de verificación?", danger: true, confirmLabel: "Eliminar" })
              .then(function (ok) {
                if (!ok) return;
                tpModal.fetchJSON(BASE + "/checklists/" + rm.getAttribute("data-chk-rm"), { method: "DELETE" }).then(function (res) {
                  if (res.ok && res.data && res.data.ok) load();
                  else fail(res, "No se pudo eliminar.");
                });
              });
          }

          var sign = e.target.closest("[data-chk-sign]");
          if (sign) {
            var cid = sign.getAttribute("data-chk-sign");
            tpModal.open({
              title: "Firmar lista",
              size: "sm",
              body:
                '<div class="tp-form"><label class="tp-form__field"><span class="tp-form__label">Notas</span>' +
                '<textarea class="tp-input" rows="3" data-sign-notes></textarea></label>' +
                '<label class="tp-form__field"><span class="tp-form__label">Firma (nombre de quien revisa)</span>' +
                '<input type="text" class="tp-input" data-sign-value></label>' +
                '<small class="tp-form__hint">No se puede firmar si quedan actividades sin completar.</small></div>',
              buttons: [
                { label: "Cancelar", variant: "ghost", close: true },
                {
                  label: "Firmar",
                  variant: "primary",
                  close: false,
                  onClick: function (d) {
                    tpModal
                      .fetchJSON(BASE + "/checklists/" + cid + "/sign", {
                        method: "POST",
                        json: {
                          notes: d.querySelector("[data-sign-notes]").value,
                          signature: d.querySelector("[data-sign-value]").value,
                        },
                      })
                      .then(function (res) {
                        if (res.ok && res.data && res.data.ok) {
                          tpModal.toast("Lista firmada.", "success");
                          tpModal.close(d);
                          load();
                        } else fail(res, "No se pudo firmar.");
                      });
                    return false;
                  },
                },
              ],
            });
          }
        });
      },
    });
  }

  window.tpChecklists = { templates: templates, forService: forService };
})();
