# -*- coding: utf-8 -*-
"""Grupo 1 fino: que elementos con click NO son controles de verdad.

El escaner general marca cualquier <div>/<tr>/<span> cuyo gancho data-*
aparezca en un closest(). Eso da 111 falsos positivos. Aqui se aplican las
cuatro distinciones que de verdad deciden, y que bajaron la cifra a 6:

  1. Los ganchos se recogen POR FICHERO. Si se recogen globalmente,
     data-idx usado como destino de click en device-modal.js marca
     cualquier data-idx de cualquier otro fichero.

  2. Solo cuenta e.target.closest("[x]") -el usuario pulsa ahi-. Un
     otraVar.closest("[x]") es subir desde un boton interior para acotar la
     fila: eso es un contenedor, y es correcto.

  3. El atributo tiene que TERMINAR en el gancho: con \\b, "data-sensor"
     casaba dentro de "data-sensor-input".

  4. Si el elemento ya contiene un control -un <button>, un <input>, algo
     con rol y tabindex-, el teclado llega por ahi dentro.

Uso:  python docs/accesibilidad-ui-v2.src/control-o-contenedor.py
"""
import io
import json
import os
import re

JS = "resources/assets/ui-v2/js"
BLADES = "resources/views/ui/v2"

RE_CLICK = re.compile(r'addEventListener\(\s*["\']click["\']')
RE_TARGET = re.compile(r'e\.target\.(?:closest|matches)\(\s*["\']\[(data-[\w-]+)\]')
INTERACTIVO = ("button", "a", "input", "select", "textarea", "summary", "label")
ROL_CONTROL = r'role\s*=\s*["\'](button|option|switch|tab|menuitem|checkbox|radio)'


def ficheros():
    for base, ext in ((JS, ".js"), (BLADES, ".blade.php")):
        for root, _, names in os.walk(base):
            for n in sorted(names):
                if n.endswith(ext):
                    p = os.path.join(root, n).replace("\\", "/")
                    yield p, io.open(p, encoding="utf-8", errors="replace").read()


fuentes = list(ficheros())

# (1) ganchos de click, por fichero
ganchos_de = {}
for f, t in fuentes:
    g = set()
    for m in RE_CLICK.finditer(t):
        # (2) solo e.target.closest/matches
        for mm in RE_TARGET.finditer(t[m.start():m.start() + 1500]):
            g.add(mm.group(1))
    ganchos_de[f] = g

# un blade puede pintar un gancho declarado en cualquier JS
ganchos_js = set()
for f, g in ganchos_de.items():
    if f.endswith(".js"):
        ganchos_js |= g

hallazgos = []
for f, t in fuentes:
    propios = ganchos_de[f] | (ganchos_js if f.endswith(".blade.php") else set())
    for g in propios:
        # (3) el atributo termina aqui
        for m in re.finditer(r"<(\w+)([^>]*\b" + re.escape(g) + r"(?=[\s=>\"'])[^>]*)>", t):
            tag, attrs = m.group(1).lower(), m.group(2)
            if tag in INTERACTIVO or re.search(ROL_CONTROL, attrs, re.I):
                continue
            # (4) ¿el teclado llega por un control de dentro?
            resto = t[m.end():m.end() + 900]
            dentro = bool(re.search(
                r'<(button|input|select|a\s)\b|' + ROL_CONTROL + r'[^>]*tabindex', resto, re.I))
            hallazgos.append({
                "f": f, "l": t[:m.start()].count("\n") + 1, "tag": tag, "hook": g,
                "teclado_dentro": dentro,
                "d": re.sub(r"\s+", " ", m.group(0))[:110],
            })

vistos, unicos = set(), []
for x in hallazgos:
    k = (x["f"], x["l"])
    if k not in vistos:
        vistos.add(k)
        unicos.append(x)

reales = [x for x in unicos if not x["teclado_dentro"]]
con_control = [x for x in unicos if x["teclado_dentro"]]

print("=" * 74)
print("  ELEMENTOS CON CLICK QUE NO SON CONTROLES")
print("=" * 74)
print("  sin ningun control dentro ... %d   <- hay que arreglarlos" % len(reales))
print("  con un control dentro ....... %d   <- el teclado ya llega ahi" % len(con_control))
print()
for x in sorted(reales, key=lambda a: (a["f"], a["l"])):
    print("   %-28s :%-5s <%s %s>" % (x["f"].split("/")[-1], x["l"], x["tag"], x["hook"]))
if con_control:
    print("\n  con control dentro (se descartan):")
    for x in sorted(con_control, key=lambda a: (a["f"], a["l"])):
        print("   %-28s :%-5s <%s %s>" % (x["f"].split("/")[-1], x["l"], x["tag"], x["hook"]))

json.dump(reales, io.open("a11y-grupo1.json", "w", encoding="utf-8"),
          ensure_ascii=False, indent=1)
