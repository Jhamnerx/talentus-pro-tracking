# -*- coding: utf-8 -*-
"""Las dos comprobaciones que salian infladas, hechas bien.

CLICK SIN SEMANTICA. Mirar el addEventListener no sirve: casi siempre el
elemento se saca por id o por atributo y resulta ser un <button>. El metodo
correcto es al reves: se recogen los ganchos data-* que SI reciben un click
y luego se busca con que etiqueta se pintan en el marcado. Un <tr> o un
<div> con click no recibe foco ni responde a Enter.

DESTINO PEQUEÑO. El selector tenia que contener una palabra interactiva,
pero eso coge .tp-events__legend-dot (un punto de leyenda) o un ::after.
Ahora solo cuenta si el selector termina en un control de verdad y no es
pseudo-elemento ni un svg interior.
"""
import io
import os
import re
import json
from collections import defaultdict

JS = "resources/assets/ui-v2/js"
BLADES = "resources/views/ui/v2"
CSS = "resources/assets/ui-v2/css"


def ficheros(base, ext):
    for root, _, names in os.walk(base):
        for n in sorted(names):
            if n.endswith(ext):
                p = os.path.join(root, n).replace("\\", "/")
                yield p, io.open(p, encoding="utf-8", errors="replace").read()


def linea(t, pos):
    return t[:pos].count("\n") + 1


fuentes = list(ficheros(JS, ".js")) + list(ficheros(BLADES, ".blade.php"))
TODO = "\n".join(t for _, t in fuentes)

# ------------------------------------------------- 1 · click sin semantica ---
# ganchos que reciben un click: closest("[data-x]") / matches("[data-x]")
ganchos = set()
# Solo los ganchos que de verdad reciben un click: los que se resuelven con
# closest()/matches() DENTRO de un manejador de click. Un querySelector
# corriente apunta a contenedores de pintado, no a controles.
for h in re.finditer(r'addEventListener\(\s*["\']click["\']', TODO):
    bloque = TODO[h.start():h.start() + 1500]
    for m in re.finditer(r'\.(?:closest|matches)\(\s*["\']\[(data-[\w-]+)\]', bloque):
        ganchos.add(m.group(1))

INTERACTIVO = ("button", "a", "input", "select", "textarea", "summary", "label")
malos = []
for f, t in fuentes:
    for g in ganchos:
        for m in re.finditer(r"<(\w+)([^>]*\b" + re.escape(g) + r"\b[^>]*)>", t):
            tag, attrs = m.group(1).lower(), m.group(2)
            if tag in INTERACTIVO:
                continue
            if re.search(r'role\s*=\s*["\'](button|link|menuitem|tab|option|checkbox|switch)', attrs, re.I):
                if "tabindex" in attrs.lower():
                    continue
            malos.append({"f": f, "l": linea(t, m.start()), "tag": tag, "hook": g,
                          "d": re.sub(r"\s+", " ", m.group(0))[:120]})

# dedupe por fichero+linea
vistos, click_reales = set(), []
for x in malos:
    k = (x["f"], x["l"])
    if k in vistos:
        continue
    vistos.add(k)
    click_reales.append(x)

# ------------------------------------------------------ 2 · destino pequeño ---
# solo controles de verdad, no pseudo-elementos ni svg/puntos interiores
CONTROL = re.compile(
    r"(^|[\s,])\.[\w-]*(btn|button|kebab|close|toggle|chip|tab|action|menu__item|row-menu)[\w-]*"
    r"(:[a-z-]+)?\s*(,|$)", re.I)
EXCLUIR = re.compile(r"(::?(after|before)|\bsvg\b|__icon|__dot|-dot\b|__label)", re.I)

RE_REGLA = re.compile(r"([^{}]+)\{([^{}]*)\}", re.S)
destinos = []
for f, t in ficheros(CSS, ".css"):
    for m in RE_REGLA.finditer(t):
        sel, cuerpo = " ".join(m.group(1).split()), m.group(2)
        if not CONTROL.search(sel) or EXCLUIR.search(sel):
            continue
        w = re.search(r"(?:^|;|\s)(?:width|min-width)\s*:\s*(\d+(?:\.\d+)?)px", cuerpo)
        h = re.search(r"(?:^|;|\s)(?:height|min-height)\s*:\s*(\d+(?:\.\d+)?)px", cuerpo)
        if not (w and h):
            continue
        fw, fh = float(w.group(1)), float(h.group(1))
        if fw >= 24 and fh >= 24:
            continue
        # ¿lo rescata un padding?
        pad = re.search(r"padding\s*:\s*(\d+(?:\.\d+)?)px", cuerpo)
        destinos.append({"f": f, "l": linea(t, m.start()), "sel": sel[:72],
                         "w": fw, "h": fh, "pad": float(pad.group(1)) if pad else 0})

print("=" * 76)
print("  REVISION FINA DE LAS DOS CATEGORIAS INFLADAS")
print("=" * 76)
print("  ganchos data-* que reciben click: %d" % len(ganchos))
print()
print("  [1] Elementos NO interactivos con click  (%d)" % len(click_reales))
print("  " + "-" * 72)
por_tag = defaultdict(int)
for x in click_reales:
    por_tag[x["tag"]] += 1
print("      por etiqueta: %s" % ", ".join("%s x%d" % kv for kv in sorted(por_tag.items(), key=lambda a: -a[1])))
print()
for x in sorted(click_reales, key=lambda a: (a["f"], a["l"]))[:22]:
    print("   %-44s :%-5s <%s %s>" % (os.path.basename(x["f"]), x["l"], x["tag"], x["hook"]))
if len(click_reales) > 22:
    print("   ... y %d mas" % (len(click_reales) - 22))

print()
print("  [2] Destinos por debajo de 24x24 px  (%d)" % len(destinos))
print("  " + "-" * 72)
for d in sorted(destinos, key=lambda a: a["w"] * a["h"])[:20]:
    extra = "  (+%gpx de relleno)" % d["pad"] if d["pad"] else ""
    print("   %-26s :%-5s %-44s %gx%g%s"
          % (os.path.basename(d["f"]), d["l"], d["sel"], d["w"], d["h"], extra))

json.dump({"click": click_reales, "destinos": destinos},
          io.open("a11y2.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
