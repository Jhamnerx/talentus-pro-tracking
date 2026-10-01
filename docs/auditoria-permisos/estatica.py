# -*- coding: utf-8 -*-
"""Igual que antes, pero resolviendo los ayudantes privados del controlador.

La primera pasada daba 43 falsos positivos porque no veia dos patrones muy
comunes: $this->gate('edit') y $this->helper($data)->create(). Los dos son
metodos privados del propio controlador que si comprueban dentro. Aqui se
mapean primero y luego una llamada a ellos cuenta como gate.
"""
import io
import os
import re
import json

CTRL = "app/Http/Controllers/Ui/V2"

RE_METODO = re.compile(r"^\s*(?:public|protected|private) function (\w+)\s*\(", re.M)
RE_PUBLICO = re.compile(r"^\s*public function (\w+)\s*\(", re.M)

RE_GATE = re.compile(
    r"""perm\(\s*['"][\w.]+['"]|checkException\(|->can\(|isAdmin\(|isManager\(|isGod\(|abort\(\s*40[13]"""
)
RE_HELPER_LEGACY = re.compile(r"ModalHelper")
RE_ESCRIBE = re.compile(
    r"""->(create|store|update|edit|destroy|delete|remove|save|saveSettings|sync\w*|attach|detach|insert|setSettings)\s*\(|"""
    r"""DB::table\([^)]*\)\s*->\s*(insert|update|delete)|::create\(|->push\(\)"""
)
NOMBRE_ESCRIBE = re.compile(
    r"^(store|update|destroy|delete|remove|save|post|set|add|copy|import|clean|clear|toggle|"
    r"assign|unassign|send|renew|reset|bulk|move|reorder|archive|restore|lock|unlock|cut)",
    re.I,
)


def trozos(src):
    pos = [(m.start(), m.group(1)) for m in RE_METODO.finditer(src)]
    for i, (ini, nombre) in enumerate(pos):
        fin = pos[i + 1][0] if i + 1 < len(pos) else len(src)
        yield nombre, ini, src[ini:fin]


filas = []
for f in sorted(os.listdir(CTRL)):
    if not f.endswith(".php"):
        continue
    src = io.open(os.path.join(CTRL, f), encoding="utf-8", errors="replace").read()

    # 1) que metodos del fichero comprueban permisos dentro
    protegen = set()
    cuerpos = {}
    for nombre, ini, cuerpo in trozos(src):
        cuerpos[nombre] = cuerpo
        if RE_GATE.search(cuerpo) or RE_HELPER_LEGACY.search(cuerpo):
            protegen.add(nombre)

    # 2) propagar: si un metodo llama a uno que protege, queda protegido
    for _ in range(3):
        for nombre, cuerpo in cuerpos.items():
            if nombre in protegen:
                continue
            for p in protegen:
                if re.search(r"\$this->%s\s*\(" % re.escape(p), cuerpo):
                    protegen.add(nombre)
                    break

    publicos = {m.group(1) for m in RE_PUBLICO.finditer(src)}
    for nombre, ini, cuerpo in trozos(src):
        if nombre not in publicos:
            continue
        if not (RE_ESCRIBE.search(cuerpo) or NOMBRE_ESCRIBE.match(nombre)):
            continue
        filas.append({
            "ctrl": f[:-4],
            "metodo": nombre,
            "gate": nombre in protegen,
            "linea": src[:ini].count("\n") + 1,
        })

sin = [r for r in filas if not r["gate"]]
print("=" * 76)
print("  METODOS PUBLICOS DE ESCRITURA EN LA v2 (resolviendo ayudantes privados)")
print("=" * 76)
print("  total ...................... %d" % len(filas))
print("  con permiso comprobado ..... %d" % (len(filas) - len(sin)))
print("  SIN COMPROBAR .............. %d" % len(sin))
print()
print("-" * 76)
for r in sin:
    print("  %-26s %-32s :%d" % (r["ctrl"], r["metodo"], r["linea"]))

json.dump(filas, io.open("metodos2.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
