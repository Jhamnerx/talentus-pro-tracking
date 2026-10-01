# -*- coding: utf-8 -*-
"""Clasifica los campos sin etiqueta por COMO se les puede poner una.

En esta base no hay un solo <label for>: 129 <label> envuelven su campo
-eso vale- y 90 <span class="tp-form__label"> son texto visible que no esta
asociado a nada.

Tres formas, de mas a menos automatizable:

  A  tiene placeholder con trans(): el texto ya existe, se copia a
     aria-label. Mecanico y exacto.
  B  tiene un <span class="tp-form__label"> justo encima: se puede convertir
     ese span en <label for> y dar un id al campo. Mecanico pero toca dos
     elementos, hay que mirar el diff.
  C  ni una cosa ni la otra: hay que leer el contexto y decidir el texto.

Sin --aplicar solo cuenta.
"""
import io
import os
import re
import sys
from collections import defaultdict

APLICAR = "--aplicar" in sys.argv
BLADES = "resources/views/ui/v2"

RE_CAMPO = re.compile(r"<(input|select|textarea)\b([^>]*)>", re.I)
RE_PH_TRANS = re.compile(r"""placeholder\s*=\s*["']\{\{\s*(trans\([^}]*?\))\s*\}\}["']""")
RE_SPAN_LABEL = re.compile(r'<span class="tp-form__label"[^>]*>(.*?)</span>', re.S)

grupos = defaultdict(list)

for root, _, names in os.walk(BLADES):
    for n in sorted(names):
        if not n.endswith(".blade.php"):
            continue
        ruta = os.path.join(root, n).replace("\\", "/")
        t = io.open(ruta, encoding="utf-8").read()
        original = t

        # de atras hacia delante: insertar desplaza lo que viene despues
        for m in sorted(RE_CAMPO.finditer(t), key=lambda x: x.start(), reverse=True):
            attrs = m.group(2)
            tipo = re.search(r'type\s*=\s*["\']([^"\']+)', attrs)
            if tipo and tipo.group(1).lower() in ("hidden", "submit", "button", "image"):
                continue
            if re.search(r"aria-label|aria-labelledby", attrs, re.I):
                continue
            # ¿lo envuelve un <label>?
            ini = t.rfind("<label", 0, m.start())
            if ini != -1 and t.find("</label>", ini) > m.end():
                continue

            ph = RE_PH_TRANS.search(attrs)
            antes = t[max(0, m.start() - 300):m.start()]
            span = RE_SPAN_LABEL.findall(antes)

            if ph:
                grupos["A·placeholder"].append((ruta, t[:m.start()].count("\n") + 1))
                if APLICAR:
                    ins = m.start() + len("<" + m.group(1))
                    t = t[:ins] + ' aria-label="{{ ' + ph.group(1) + ' }}"' + t[ins:]
            elif span:
                grupos["B·span-label"].append((ruta, t[:m.start()].count("\n") + 1))
            else:
                grupos["C·sin-texto"].append((ruta, t[:m.start()].count("\n") + 1))

        if APLICAR and t != original:
            io.open(ruta, "w", encoding="utf-8", newline="").write(t)

print("=" * 70)
print("  %s" % ("APLICADO (solo el grupo A)" if APLICAR else "RECUENTO"))
print("=" * 70)
for k in sorted(grupos):
    print("  %-18s %3d" % (k, len(grupos[k])))
print("  %-18s %3d" % ("total", sum(len(v) for v in grupos.values())))

for k in sorted(grupos):
    porf = defaultdict(int)
    for f, _ in grupos[k]:
        porf[f.split("/")[-1]] += 1
    print("\n  %s" % k)
    for f, n in sorted(porf.items(), key=lambda x: -x[1])[:6]:
        print("     %-44s %3d" % (f, n))
