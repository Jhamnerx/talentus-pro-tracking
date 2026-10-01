# -*- coding: utf-8 -*-
"""Auditoria de accesibilidad de botones y elementos interactivos de la UI v2.

Cubre las dos fuentes de marcado: los blades y las cadenas de JS, que en
esta app generan buena parte de los controles (listas, tablas, menus).

Comprobaciones, por la prioridad del skill:
  1 nombre accesible en botones de solo icono
  2 anillo de foco quitado sin reemplazo
  3 tamaño de destino por debajo de 24x24 CSS px (WCAG 2.2 AA)
  4 controles falsos: click sobre div/span sin rol ni foco
  5 iconos decorativos sin aria-hidden dentro de un control con texto
  6 estado de los conmutadores (aria-pressed / aria-expanded)
  7 prefers-reduced-motion
  8 campos de formulario sin etiqueta
"""
import io
import os
import re
import json
from collections import defaultdict

BLADES = "resources/views/ui/v2"
JS = "resources/assets/ui-v2/js"
CSS = "resources/assets/ui-v2/css"

hallazgos = defaultdict(list)


def anota(regla, fichero, linea, detalle):
    hallazgos[regla].append({"f": fichero, "l": linea, "d": detalle[:150]})


def ficheros(base, ext):
    for root, _, names in os.walk(base):
        for n in sorted(names):
            if n.endswith(ext):
                p = os.path.join(root, n)
                yield p.replace("\\", "/"), io.open(p, encoding="utf-8", errors="replace").read()


def linea_de(texto, pos):
    return texto[:pos].count("\n") + 1


# ---------------------------------------------------------------------------
# 1 · botones de solo icono sin nombre accesible
# ---------------------------------------------------------------------------
RE_BOTON = re.compile(r"<button\b[^>]*>(.*?)</button>", re.S | re.I)
RE_A_BOTON = re.compile(r"<a\b[^>]*(?:class=\"[^\"]*tp-btn|role=\"button\")[^>]*>(.*?)</a>", re.S | re.I)
RE_TEXTO = re.compile(r">([^<>{}]*[A-Za-zÁÉÍÓÚÑáéíóúñ0-9][^<>{}]*)<")


def tiene_nombre(abre, dentro):
    # El valor puede venir concatenado en JS: title="' + esc(...) + '"
    for attr in ("aria-label", "aria-labelledby", "title"):
        # re.S: el valor puede partirse en varias lineas al concatenarse
        m = re.search(attr + r'\s*=\s*(.{0,60})', abre, re.I | re.S)
        if m and re.search(r'[A-Za-z0-9\u00c0-\u024f]',
                           re.sub(r'^["\'\s+]*', '', m.group(1))):
            return True
    # Texto visible: se quitan iconos y etiquetas y se mira que quede algo.
    if texto_visible(dentro):
        return True
    return False


def texto_visible(dentro):
    """¿Queda texto para leer una vez fuera iconos, etiquetas y comillas?"""
    sin_svg = re.sub(r"<svg\b.*?</svg>", "", dentro, flags=re.S | re.I)
    sin_svg = re.sub(r"<i\b[^>]*>.*?</i>", "", sin_svg, flags=re.S | re.I)

    # trans()/tpT()/{{ }} -> el texto llega en ejecucion, cuenta como nombre
    if re.search(r"(trans\(|tpT\(|T\(|\{\{)", sin_svg):
        return True

    plano = re.sub(r"<[^>]*>", " ", sin_svg)      # fuera el resto de etiquetas
    plano = re.sub(r"[\"\'+;]", " ", plano)        # fuera la fontaneria de JS
    return bool(re.search(r"[A-Za-z0-9\u00c0-\u024f]", plano))


def revisa_controles(fichero, texto):
    for m in list(RE_BOTON.finditer(texto)) + list(RE_A_BOTON.finditer(texto)):
        todo = m.group(0)
        abre = todo[: todo.find(">") + 1]
        dentro = m.group(1)
        if "<svg" not in dentro.lower() and "<i " not in dentro.lower():
            continue  # sin icono: si no hay texto, lo coge otra regla
        if not tiene_nombre(abre, dentro):
            anota("nombre-accesible", fichero, linea_de(texto, m.start()),
                  re.sub(r"\s+", " ", abre))
        # 5 · icono decorativo sin ocultar, en un control que YA tiene texto
        hay_texto = texto_visible(dentro)
        for svg in re.finditer(r"<svg\b[^>]*>", dentro, re.I):
            if hay_texto and "aria-hidden" not in svg.group(0).lower():
                anota("icono-decorativo", fichero, linea_de(texto, m.start()),
                      re.sub(r"\s+", " ", svg.group(0)))
                break


for f, t in ficheros(BLADES, ".blade.php"):
    revisa_controles(f, t)
for f, t in ficheros(JS, ".js"):
    revisa_controles(f, t)

# ---------------------------------------------------------------------------
# 2 · anillo de foco
# ---------------------------------------------------------------------------
for f, t in ficheros(CSS, ".css"):
    for m in re.finditer(r"outline\s*:\s*(none|0)\b", t, re.I):
        ini = t.rfind("}", 0, m.start()) + 1
        bloque = t[ini:m.end() + 400]
        # ¿hay reemplazo cerca? (:focus-visible con outline o box-shadow)
        if not re.search(r":focus-visible[^{]*\{[^}]*(outline|box-shadow)", bloque, re.I):
            sel = re.sub(r"\s+", " ", t[ini:t.find("{", ini)]).strip()[:90]
            anota("foco-sin-anillo", f, linea_de(t, m.start()), sel or "(selector)")

# cuantos :focus-visible hay en total, como contexto
focus_visible = sum(len(re.findall(r":focus-visible", t)) for _, t in ficheros(CSS, ".css"))

# ---------------------------------------------------------------------------
# 3 · tamaño de destino
# ---------------------------------------------------------------------------
RE_REGLA = re.compile(r"([^{}]+)\{([^{}]*)\}", re.S)
for f, t in ficheros(CSS, ".css"):
    for m in RE_REGLA.finditer(t):
        sel, cuerpo = m.group(1).strip(), m.group(2)
        if not re.search(r"(btn|icon|close|chip|kebab|toggle|tab|dot|action)", sel, re.I):
            continue
        w = re.search(r"(?:^|;|\s)(?:width|min-width)\s*:\s*(\d+(?:\.\d+)?)px", cuerpo)
        h = re.search(r"(?:^|;|\s)(?:height|min-height)\s*:\s*(\d+(?:\.\d+)?)px", cuerpo)
        if w and h:
            fw, fh = float(w.group(1)), float(h.group(1))
            if fw < 24 or fh < 24:
                anota("destino-pequeno", f, linea_de(t, m.start()),
                      "%s -> %gx%g px" % (re.sub(r"\s+", " ", sel)[:70], fw, fh))

# ---------------------------------------------------------------------------
# 4 · controles falsos: click en algo que no es boton ni enlace
# ---------------------------------------------------------------------------
for f, t in ficheros(JS, ".js"):
    for m in re.finditer(r'(\w+)\.addEventListener\(\s*["\']click["\']', t):
        ln = linea_de(t, m.start())
        ctx = t[max(0, m.start() - 400):m.start()]
        # si el elemento se saca de un selector de boton/enlace, bien
        if re.search(r'(querySelector\w*\(\s*["\'][^"\']*(button|\ba\b|\[data-tp-btn\]|\.tp-btn)|createElement\(\s*["\'](button|a)["\'])',
                     ctx, re.I):
            continue
        # Delegacion: el listener va en un contenedor y dentro se busca el
        # control real con closest()/matches(). Eso es correcto; lo que
        # importa es la semantica de ESE control, que juzga la regla del
        # marcado, no esta.
        cuerpo = t[m.start():m.start() + 900]
        if re.search(r'\.(closest|matches)\(', cuerpo):
            continue
        anota("control-sin-semantica", f, ln, m.group(0))

for f, t in ficheros(BLADES, ".blade.php"):
    for m in re.finditer(r"<(div|span|li|td|tr)\b[^>]*\bonclick\s*=", t, re.I):
        abre = t[m.start():t.find(">", m.start()) + 1]
        if "tabindex" in abre.lower() and "role=" in abre.lower():
            continue
        anota("control-sin-semantica", f, linea_de(t, m.start()),
              re.sub(r"\s+", " ", abre)[:120])

# ---------------------------------------------------------------------------
# 6 · conmutadores sin estado expuesto
# ---------------------------------------------------------------------------
for f, t in ficheros(JS, ".js"):
    for m in re.finditer(r"classList\.toggle\(\s*[\"'](is-active|is-on|is-open|active|open)[\"']", t):
        ctx = t[max(0, m.start() - 300):m.start() + 300]
        if re.search(r"aria-(pressed|expanded|selected|checked)", ctx, re.I):
            continue
        anota("estado-no-expuesto", f, linea_de(t, m.start()), m.group(0))

# ---------------------------------------------------------------------------
# 7 · movimiento reducido
# ---------------------------------------------------------------------------
reduced = []
for f, t in ficheros(CSS, ".css"):
    n = len(re.findall(r"prefers-reduced-motion", t))
    anim = len(re.findall(r"(transition|animation)\s*:", t))
    if anim:
        reduced.append((f, n, anim))

# ---------------------------------------------------------------------------
# 8 · campos sin etiqueta
# ---------------------------------------------------------------------------
for f, t in ficheros(BLADES, ".blade.php"):
    for m in re.finditer(r"<(input|select|textarea)\b([^>]*)>", t, re.I):
        attrs = m.group(2)
        tipo = re.search(r'type\s*=\s*["\']([^"\']+)', attrs)
        if tipo and tipo.group(1).lower() in ("hidden", "submit", "button", "image"):
            continue
        if re.search(r'(aria-label|aria-labelledby)', attrs, re.I):
            continue
        # Un id solo vale si algo lo etiqueta de verdad.
        mid = re.search(r'\bid\s*=\s*["\']([^"\']+)', attrs)
        if mid and re.search(r'<label[^>]*\bfor\s*=\s*["\']' + re.escape(mid.group(1)), t, re.I):
            continue
        # o si el campo va dentro de un <label> ... </label>
        ini = t.rfind('<label', 0, m.start())
        if ini != -1 and t.find('</label>', ini) > m.end():
            continue
        if re.search(r'\btitle\s*=\s*["\']?\s*[^"\'\s>]', attrs, re.I):
            continue
        anota("campo-sin-etiqueta", f, linea_de(t, m.start()),
              re.sub(r"\s+", " ", m.group(0))[:110])

# ---------------------------------------------------------------------------
# informe
# ---------------------------------------------------------------------------
ORDEN = [
    ("nombre-accesible", "Boton de solo icono sin nombre accesible", "CRITICO"),
    ("control-sin-semantica", "Click sobre un elemento sin rol ni foco", "CRITICO"),
    ("foco-sin-anillo", "outline:none sin reemplazo visible", "CRITICO"),
    ("destino-pequeno", "Destino tactil por debajo de 24x24 px", "ALTO"),
    ("estado-no-expuesto", "Conmutador sin aria-pressed/expanded", "ALTO"),
    ("campo-sin-etiqueta", "Campo de formulario sin etiqueta", "ALTO"),
    ("icono-decorativo", "Icono decorativo sin aria-hidden", "MEDIO"),
]

print("=" * 78)
print("  ACCESIBILIDAD · UI v2 · botones y elementos interactivos")
print("=" * 78)
total = 0
for clave, titulo, sev in ORDEN:
    n = len(hallazgos[clave])
    total += n
    print("  %-8s %-46s %4d" % (sev, titulo, n))
print("  %-8s %-46s %4d" % ("", "TOTAL", total))

print("\n  contexto: %d reglas :focus-visible en el CSS de la v2" % focus_visible)
print("  prefers-reduced-motion por fichero con animaciones:")
for f, n, anim in sorted(reduced, key=lambda x: -x[2])[:8]:
    print("    %-46s %2d bloques / %3d transiciones" % (os.path.basename(f), n, anim))

for clave, titulo, sev in ORDEN:
    rs = hallazgos[clave]
    if not rs:
        continue
    print("\n" + "-" * 78)
    print("  [%s] %s  (%d)" % (sev, titulo, len(rs)))
    print("-" * 78)
    por_fichero = defaultdict(list)
    for r in rs:
        por_fichero[r["f"]].append(r)
    for f in sorted(por_fichero, key=lambda x: -len(por_fichero[x])):
        print("   %s  (%d)" % (f, len(por_fichero[f])))
        for r in por_fichero[f][:4]:
            print("      :%-5s %s" % (r["l"], r["d"]))
        if len(por_fichero[f]) > 4:
            print("      ... y %d mas" % (len(por_fichero[f]) - 4))

json.dump(hallazgos, io.open("a11y.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
