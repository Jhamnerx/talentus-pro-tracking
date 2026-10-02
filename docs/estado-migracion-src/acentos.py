# -*- coding: utf-8 -*-
"""Pone los acentos que falten en el HTML generado.

Dos listas. PALABRAS son sustituciones de palabra completa y sin ambiguedad.
FRASES son los casos donde la misma forma puede llevar o no acento segun lo
que signifique ("esta puesta" = verbo, "esta maquina" = demostrativo), y por
eso se tocan por contexto exacto, no por palabra.

Solo se escribe dentro de los nodos de texto (entre > y <) y en el <title>:
nunca en un nombre de clase, un atributo ni una propiedad CSS.
"""
import io
import os
import re
import sys

LETRA = u"\\w\u00c0-\u024f"

# ------------------------------------------------- palabra completa, segura ---
PALABRAS = {
    u"migracion": u"migración", u"Migracion": u"Migración",
    u"version": u"versión", u"fusion": u"fusión", u"Fusion": u"Fusión",
    u"vehiculo": u"vehículo", u"vehiculos": u"vehículos", u"Vehiculos": u"Vehículos",
    u"modulo": u"módulo", u"modulos": u"módulos", u"Modulo": u"Módulo",
    u"pagina": u"página", u"paginas": u"páginas",
    u"clasico": u"clásico", u"ingles": u"inglés",
    u"movil": u"móvil", u"Movil": u"Móvil",
    u"numero": u"número", u"revision": u"revisión",
    u"comparacion": u"comparación",
    u"Encontro": u"Encontró", u"encontro": u"encontró",
    u"correccion": u"corrección", u"Correccion": u"Corrección",
    u"genero": u"generó", u"Envio": u"Envío", u"llego": u"llegó",
    u"importacion": u"importación", u"Edicion": u"Edición",
    u"vertices": u"vértices",
    u"limite": u"límite", u"limites": u"límites",
    u"habia": u"había", u"grafica": u"gráfica",
    u"tenia": u"tenía", u"razon": u"razón",
    u"aplicacion": u"aplicación",
    u"volvia": u"volvía", u"cubria": u"cubría",
    u"dia": u"día", u"dias": u"días", u"Dias": u"Días",
    u"traduccion": u"traducción", u"valida": u"válida", u"tiro": u"tiró",
    u"existian": u"existían", u"automatico": u"automático",
    u"peticion": u"petición", u"vacia": u"vacía", u"maquina": u"máquina",
    u"produccion": u"producción", u"Produccion": u"Producción",
    u"tecnica": u"técnica", u"detras": u"detrás", u"perdida": u"pérdida",
    u"causo": u"causó", u"validacion": u"validación",
    u"automaticas": u"automáticas", u"regresion": u"regresión",
    u"Despues": u"Después", u"despues": u"después",
    u"decision": u"decisión", u"integracion": u"integración",
    u"concatenacion": u"concatenación", u"limitacion": u"limitación",
    u"raiz": u"raíz", u"romperia": u"rompería",
    u"dedicacion": u"dedicación", u"Consolidacion": u"Consolidación",
    u"preproduccion": u"preproducción", u"sistematicas": u"sistemáticas",
    u"atras": u"atrás", u"Estabilizacion": u"Estabilización",
    u"cubrio": u"cubrió", u"rapido": u"rápido", u"telemetria": u"telemetría",
    u"concentro": u"concentró", u"corrio": u"corrió",
    u"congelacion": u"congelación",
    u"Quienes": u"Quiénes", u"Quien": u"Quién",
    u"estan": u"están", u"sera": u"será", u"mas": u"más",
    u"aqui": u"aquí", u"ahi": u"ahí", u"todavia": u"todavía",
    u"incomoda": u"incómoda", u"repartio": u"repartió",
    u"deberia": u"debería", u"auditoria": u"auditoría",
    u"Auditoria": u"Auditoría", u"comprobacion": u"comprobación",
    u"configuracion": u"configuración", u"ubicacion": u"ubicación",
    u"posicion": u"posición", u"posiciones": u"posiciones",
    u"accion": u"acción", u"programacion": u"programación",
    u"estabilizacion": u"estabilización",
}

# -------------------------------------- mismo escrito, significado distinto ---
# "esta puesta" es el verbo; "esta maquina" es el demostrativo y no lleva tilde.
FRASES = [
    (u"corrección, esta en", u"corrección, está en"),
    (u"el número esta mal", u"el número está mal"),
    (u"La clave ya esta puesta", u"La clave ya está puesta"),
    (u"La interfaz esta terminada", u"La interfaz está terminada"),
    (u"</code> esta vacía", u"</code> está vacía"),
    (u"lo que ya esta.", u"lo que ya está."),
    (u"<code>php -l</code> paso limpio", u"<code>php -l</code> pasó limpio"),
    (u"<th>Que paso</th>", u"<th>Qu\u00e9 pas\u00f3</th>"),
    (u'width:26%">Que<', u'width:26%">Qu\u00e9<'),
    (u"<th>Cuando</th>", u"<th>Cu\u00e1ndo</th>"),
    (u"límite de via caen", u"límite de vía caen"),
    (u"se vacio el 30-sep", u"se vació el 30-sep"),
    (u"que si se ajustaba", u"que sí se ajustaba"),
    (u"en producción si están", u"en producción sí están"),
]

PATRON = {p: re.compile(u"(?<![%s])%s(?![%s])" % (LETRA, re.escape(p), LETRA))
          for p in PALABRAS}


def acentua(texto):
    for p, r in PALABRAS.items():
        texto = PATRON[p].sub(r, texto)
    return texto


ruta = sys.argv[1]
s = io.open(ruta, encoding="utf-8").read()
cabeza, cuerpo = s.split("</style>", 1)

# el <title> va en la cabeza; el CSS no se toca
cabeza = re.sub(r"<title>(.*?)</title>",
                lambda m: "<title>%s</title>" % acentua(m.group(1)), cabeza)

# en el cuerpo, solo los nodos de texto
cuerpo = re.sub(r">([^<>]+)<", lambda m: ">%s<" % acentua(m.group(1)), cuerpo)

for viejo, nuevo in FRASES:
    n = cuerpo.count(viejo)
    if n == 0:
        # puede que una PALABRA ya lo haya cambiado; se comprueba el resultado
        if cuerpo.count(nuevo) == 0:
            print(u"  SIN ANCLA: %s" % viejo)
        continue
    cuerpo = cuerpo.replace(viejo, nuevo)

s = cabeza + "</style>" + cuerpo
io.open(ruta, "w", encoding="utf-8", newline="").write(s)
print("  acentuado: %s (%d KB)" % (os.path.basename(ruta), os.path.getsize(ruta) // 1024))
