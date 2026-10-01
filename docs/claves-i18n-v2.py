# -*- coding: utf-8 -*-
"""Toda clave que el JS de la v2 pide a tpT() tiene que estar publicada.

El puente (partials/i18n.blade.php) manda 'ui' entero pero solo una LISTA
EXPLICITA de front/global/attr, para no cargar ~1.500 claves por pagina. Si
el JS pide una que no esta, tpT() devuelve la clave tal cual y al usuario le
sale "975front.hour_short", que es justo lo que paso.

Esto compara lo que se pide con lo que se publica.
"""
import io
import os
import re
import subprocess

JS = "resources/assets/ui-v2/js"
BLADES = "resources/views/ui/v2"
PUENTE = "resources/views/ui/v2/partials/i18n.blade.php"

# ------------------------------------------------- lo que el puente publica ---
src = io.open(PUENTE, encoding="utf-8").read()


def lista(nombre):
    m = re.search(r"\$" + nombre + r"\s*=\s*\[(.*?)\];", src, re.S)
    return set(re.findall(r"'([^']+)'", m.group(1))) if m else set()


publicado = {
    "front": lista("tpFront"),
    "global": lista("tpGlobal"),
    "attr": lista("tpAttr"),
}

# 'ui' va entero: se lee del fichero de idioma
ui = subprocess.run(
    ["php", "-r", "$a=require 'resources/lang/es/ui.php'; echo implode(\"\\n\", array_keys($a));"],
    capture_output=True, text=True).stdout.split("\n")
publicado["ui"] = set(x for x in ui if x)

print("  publicado por el puente:")
for k in ("ui", "front", "global", "attr"):
    print("    %-8s %d claves" % (k, len(publicado[k])))

# ------------------------------------------------------- lo que el JS pide ---
RE_TPT = re.compile(r"""tpT\(\s*["']([a-z_]+)\.([\w.]+)["']""")
pedidas = {}
for base, ext in ((JS, ".js"), (BLADES, ".blade.php")):
    for root, _, names in os.walk(base):
        for n in sorted(names):
            if not n.endswith(ext):
                continue
            p = os.path.join(root, n).replace("\\", "/")
            t = io.open(p, encoding="utf-8", errors="replace").read()
            for m in RE_TPT.finditer(t):
                dom, clave = m.group(1), m.group(2)
                pedidas.setdefault((dom, clave), []).append(
                    "%s:%d" % (p, t[:m.start()].count("\n") + 1))

print("\n  claves distintas que pide el JS: %d" % len(pedidas))

faltan = []
for (dom, clave), donde in sorted(pedidas.items()):
    if dom not in publicado:
        faltan.append((dom, clave, donde, "dominio no publicado"))
    elif clave not in publicado[dom]:
        faltan.append((dom, clave, donde, "no esta en la lista"))

print("\n" + "=" * 72)
if not faltan:
    print("  TODAS LAS CLAVES LLEGAN AL NAVEGADOR")
else:
    print("  CLAVES QUE NO LLEGAN AL NAVEGADOR: %d" % len(faltan))
print("=" * 72)
for dom, clave, donde, por in faltan:
    print("  %-8s %-26s %-22s %s" % (dom, clave, por, donde[0]))
    for d in donde[1:4]:
        print("  %-8s %-26s %-22s %s" % ("", "", "", d))
