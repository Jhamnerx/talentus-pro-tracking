# -*- coding: utf-8 -*-
"""Separa controles de contenedores entre los elementos marcados.

Un gancho data-* puede aparecer en un closest() por dos motivos muy
distintos:

  e.target.closest("[data-x]")   el usuario pulsa AHI  -> es un control
  otraVar.closest("[data-x]")    se sube desde un boton interior para
                                 acotar la fila/tarjeta -> es un contenedor

Solo el primero necesita foco y teclado. Mezclarlos inflaba la cuenta.
"""
import io
import json
import os
import re

JS = "resources/assets/ui-v2/js"
TODO = "\n".join(
    io.open(os.path.join(r, n), encoding="utf-8", errors="replace").read()
    for r, _, ns in os.walk(JS) for n in ns if n.endswith(".js"))

h2 = json.load(io.open("a11y2.json", encoding="utf-8"))

RE_TARGET = re.compile(r"e\.target\.closest\(\s*[\"']\[(data-[\w-]+)\]")
RE_OTRO = re.compile(r"(\w+)\.closest\(\s*[\"']\[(data-[\w-]+)\]")

son_destino = set(RE_TARGET.findall(TODO))
# tambien cuenta si el gancho se compara con matches() sobre e.target
son_destino |= set(re.findall(r"e\.target\.matches\(\s*[\"']\[(data-[\w-]+)\]", TODO))

controles, contenedores = [], []
for x in h2["click"]:
    (controles if x["hook"] in son_destino else contenedores).append(x)

print("=" * 72)
print("  ELEMENTOS NO INTERACTIVOS CON CLICK, SEPARADOS")
print("=" * 72)
print("  controles de verdad (e.target.closest) .... %d   <- hay que arreglarlos" % len(controles))
print("  contenedores para acotar (var.closest) .... %d   <- correctos, no son hallazgos" % len(contenedores))
print()
print("  CONTROLES")
print("  " + "-" * 68)
for x in sorted(controles, key=lambda a: (a["f"], a["l"])):
    print("   %-30s :%-5s <%s %s>" % (x["f"].split("/")[-1], x["l"], x["tag"], x["hook"]))
print()
print("  CONTENEDORES (se descartan)")
print("  " + "-" * 68)
for x in sorted(contenedores, key=lambda a: (a["f"], a["l"])):
    print("   %-30s :%-5s <%s %s>" % (x["f"].split("/")[-1], x["l"], x["tag"], x["hook"]))

json.dump({"controles": controles, "contenedores": contenedores},
          io.open("a11y3.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
