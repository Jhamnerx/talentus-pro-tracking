# -*- coding: utf-8 -*-
"""Genera la pagina de estado de la migracion a la UI v2.

Los datos no se transcriben a mano: las 106 funcionalidades salen de
inventario.json, extraido de la seccion 1.2 del plan. El resto (QA, pendiente,
calendario) viene de las secciones 2, 4 y 5 del mismo documento y de los
conteos reales del repositorio.
"""
import io
import os
import re
import json
import html

S = os.path.dirname(os.path.abspath(__file__))
SALIDA = os.path.join(S, "estado-migracion.html")

inv = json.load(io.open(os.path.join(S, "inventario.json"), encoding="utf-8"))

# ================================================================= datos ===

RESUMEN = [
    ("106", "funcionalidades entregadas", "repartidas en 11 modulos"),
    ("324", "commits en la rama", "31-may a 30-sep-2026"),
    ("33", "defectos corregidos", "32 de QA + 1 posterior; 0 abiertos"),
    ("32", "migraciones por aplicar", "17 de este trabajo, 15 de la base"),
]

QA_CUBIERTO = [
    (u"Las 21 paginas de la v2",
     u"Todas cargan. Cada una comparada con su equivalente del sistema clasico."),
    (u"Tema claro y tema oscuro",
     u"Recorrido completo en los dos."),
    (u"Castellano e ingles",
     u"Medido pagina a pagina: cero palabras sin traducir en ninguno de los dos."),
    (u"Movil a 390 px de ancho",
     u"Menu, lista de vehiculos y panel lateral."),
    (u"Altas, ediciones y borrados",
     u"Gasto, tarea, conductor, alerta, geocerca, accion de llamada, "
     u"programacion de ruta y enlace compartido."),
    (u"Historial del equipo 219",
     u"195,5 km, 29 viajes, 30 paradas, 2.976 puntos. Coincide con el sistema clasico."),
    (u"Permisos",
     u"Cuenta de administrador y cuenta de usuario normal."),
]

QA_NO_CUBIERTO = [
    (u"Los ~130 tipos de reporte",
     u"Solo se genero uno de principio a fin. Hace falta muestreo dirigido."),
    (u"Integraciones regulatorias",
     u"OSINERGMIN, SUTRAN, WiseTrack, Comsatel, Sidersales y EPS Center."),
    (u"Roles intermedios",
     u"Gestor, operador, supervisor y demo."),
    (u"Envio de comandos a equipos",
     u"Son SMS y GPRS reales contra hardware. Solo se llego al formulario."),
    (u"Descarga e importacion de ficheros",
     u"Exportar rutas, eventos y flota; importar rutas y geocercas."),
    (u"Dibujo con arrastre",
     u"Edicion de vertices de geocerca y trazado de rutas sobre el mapa."),
]

QA_PATRONES = [
    (u"`try/catch` mudo que convierte un error en un dato falso", 2,
     u"Editar una alerta le borraba sus 1.429 vehiculos. El Co-Pilot mostraba "
     u"la flota entera a cero."),
    (u"Consultas sin limite superior de fecha", 4,
     u"El tablero contaba desde el inicio del rango **hasta hoy**: 3.016 eventos "
     u"de riesgo donde habia 1.150. La grafica, que si se ajustaba al eje, tenia razon."),
    (u"Zona horaria: se guarda en la del usuario y se lee en la de la aplicacion", 1,
     u"Un reporte guardado volvia con las fechas corridas un dia, y uno "
     u"programado cubria el dia equivocado."),
    (u"Esquema heredado de MySQL", 1,
     u"`device_expenses.date` tenia `ON UPDATE CURRENT_TIMESTAMP`: editar la "
     u"cantidad de un gasto le borraba la fecha."),
]

QA_EXTRA = [
    (u"Tres defectos los introdujo la propia tanda de traduccion del 29-sep",
     u"`trans()` dentro de una `const` —sintaxis valida, fatal al cargar la clase— "
     u"tiro el tablero entero. `php -l` paso limpio en los 25 controladores."),
    (u"Un defecto 33 aparecio despues, fuera de la revision",
     u"Al pulsar Generar, un reporte en HTML se abria en una pestaña nueva en vez "
     u"de descargarse. La v2 pedia la ruta con `view=1`, que devuelve la vista sin "
     u"cabecera de descarga; el sistema clasico nunca manda ese parametro. De paso, "
     u"en formato CSV salia una pagina en blanco. Corregido el 1-oct."),
    (u"De ahi salieron dos cosas que antes no existian",
     u"Versionado automatico de assets por `filemtime` (las 100 referencias a JS y "
     u"CSS llevaban el numero a mano) y una **prueba de humo** que lanza una peticion "
     u"real a las 21 paginas y 16 endpoints: `php artisan uiv2:smoke`, 37 de 37."),
]

PENDIENTE = [
    {
        "grupo": u"Claves y datos del servidor",
        "nota": u"Nada de esto se arregla programando, pero desde dentro **parece** un "
                u"fallo de la aplicacion. Conviene cerrarlo antes del piloto.",
        "items": [
            (u"API de Places no habilitada para la clave de Google",
             u"La clave ya esta puesta en *Ajustes del servidor*. Falta habilitar la API "
             u"de Places para esa clave en la consola de Google Cloud. Sin ella, el "
             u"autocompletado de direcciones en Tareas no sugiere nada.",
             u"Fase 0", u"30 min"),
            (u"Clave de Roads vacia (`speedlimit_key`)",
             u"Los reportes que usan el limite de via caen al limite de la geocerca.",
             u"Fase 0", u"30 min"),
            (u"Faltan 2 de 105 ficheros de icono de mapa",
             u"Son iconos subidos por un usuario: las filas estan en la tabla pero los "
             u"ficheros no se copiaron a esta maquina. Confirmar que en produccion si estan.",
             u"Fase 0", u"1 h"),
            (u"Worker de la cola en produccion",
             u"Sin el, los avisos emergentes no salen. La cola de desarrollo se vacio "
             u"el 30-sep: eran 26 trabajos que nadie iba a procesar.",
             u"Fase 3", u"30 min"),
        ],
    },
    {
        "grupo": u"Deuda tecnica antes de salir",
        "nota": u"Ninguno bloquea, pero cada punto es una trampa para quien venga detras.",
        "items": [
            (u"Subir los 267 commits al remoto",
             u"Cuatro meses de trabajo viven en una sola maquina. Produccion no depende "
             u"de ellos —corre la version clasica— pero una perdida de disco se lo lleva "
             u"todo. Es lo primero del calendario y no admite espera.",
             u"Fase 0, dia 1", u"2 h"),
            (u"Actualizar `gulp-clean-css`",
             u"La version 4.2.1 descarta en silencio las at-rules que no conoce: toda "
             u"`@container` desaparece del CSS minificado sin un solo aviso.",
             u"Fase 0", u"medio dia"),
            (u"Fijar los cortes responsive como tokens",
             u"Ocho breakpoints sueltos, con parejas a 6 px de distancia. Ya causo un "
             u"fallo real.",
             u"Fase 0", u"1 dia"),
            (u"Validar `template_color` al guardarlo",
             u"`saveSettings()` acepta cualquier valor por POST. El respaldo en cascada "
             u"evita el daño visual, pero la validacion sigue faltando.",
             u"Fase 0", u"2 h"),
            (u"Sin suite de pruebas automaticas",
             u"Cada regresion se detecta a mano o en produccion. La prueba de humo cubre "
             u"que las paginas respondan, no que los datos sean correctos.",
             u"Despues de salir", u"a decidir"),
        ],
    },
    {
        "grupo": u"Aplazado por decision del cliente",
        "nota": u"Ninguno de los dos bloquea la salida.",
        "items": [
            (u"Video a bordo",
             u"La interfaz esta terminada y gateada por vehiculo, hasta 6 canales. Falta "
             u"el decodificador JT808/Howen. Hoy no hay equipos compatibles en la flota.",
             u"Sin fecha", u"—"),
            (u"Suscripciones y planes",
             u"Los pagos pasan al ERP de Talentus. A la espera de definir la integracion.",
             u"Sin fecha", u"—"),
        ],
    },
]

LIMITACIONES = [
    (u"El puente de traducciones pesa ~54 KB por pagina",
     u"1.106 claves, y se manda entero en todas. Si molesta, se parte por pagina."),
    (u"Unas 30 cadenas se tradujeron como fragmentos de una concatenacion",
     u"Funcionan, pero no permiten reordenar la frase en otro idioma. Pasarlas a "
     u"marcadores exige reescribir cada sitio donde se usan."),
    (u"El Co-Pilot reconoce la pregunta solo en castellano",
     u"Las expresiones regulares que la interpretan no son texto que se muestre, sino "
     u"reconocimiento de entrada. Hacerlo multiidioma es otro trabajo."),
    (u"Los mensajes de evento generados al vuelo no son buscables",
     u"La columna `events.message` esta vacia para algunos tipos y el texto se compone "
     u"al mostrarlo. El sistema clasico tiene la misma limitacion."),
    (u"Las etiquetas de idioma no se van a unificar",
     u"`es/validation.php` guarda varias en la raiz y `en/validation.php` las mismas "
     u"dentro de `attributes`. Es inconsistente y ya ha costado 18 claves rotas, pero el "
     u"sistema clasico las lee con la forma que tienen hoy y reordenarlas lo romperia. La "
     u"regla es **añadir la clave en el nivel donde la pide quien la consume**, sin mover "
     u"lo que ya esta."),
]

FASES = [
    (u"0", u"Consolidacion", u"lun 5-oct", u"vie 9-oct", u"1 semana",
     u"Subir la rama, congelar funcionalidades, levantar preproduccion y medir las "
     u"32 migraciones contra una copia de la base real."),
    (u"1", u"Pruebas sistematicas", u"lun 12-oct", u"vie 23-oct", u"2 semanas",
     u"Modulo por modulo, con lista escrita, en los dos temas y los dos idiomas. "
     u"Criterio de salida: cero defectos bloqueantes abiertos; los medios, documentados "
     u"y aceptados."),
    (u"2", u"Piloto en produccion", u"lun 26-oct", u"vie 6-nov", u"2 semanas",
     u"Un grupo reducido de usuarios con la v2 y datos reales, con vuelta atras por usuario."),
    (u"3", u"Salida", u"mar 10-nov", None, u"1 noche",
     u"Fusion a `main`, migraciones y ventana de 60 a 90 minutos."),
    (u"4", u"Estabilizacion", u"mar 10-nov", u"vie 20-nov", u"1,5 semanas",
     u"Correccion de lo que aparezca con la flota completa encima."),
]

FASE1 = [
    (u"Mapa en vivo, monitoreo de grupo y flota", 2, True),
    (u"Alertas, eventos, notificaciones y tiempo real", 2, True),
    (u"Reportes: los ~130 tipos, por muestreo dirigido", 2, False),
    (u"Historial, combustible y telemetria", 1, True),
    (u"Rutas, geocercas, POI y programaciones", 1, True),
    (u"Mantenimiento, gastos, tareas, conductores y chat", 1, True),
    (u"Integraciones regulatorias (OSINERGMIN, SUTRAN, WiseTrack…)", 1, False),
    (u"Roles: admin, gestor, operador, supervisor y demo", 1, False),
]

MESES = [
    (u"mayo", u"2", u"Arranque: shell, paginas y modales del tema nuevo."),
    (u"junio", u"55", u"Mapa en vivo y cambio de motor de mapas a Leaflet, el mismo del "
                      u"sistema clasico."),
    (u"julio", u"71", u"Widgets del vehiculo, reportes y ficha de equipo."),
    (u"agosto", u"102", u"Monitoreo de grupo, flota, tablero y los modulos nuevos."),
    (u"septiembre", u"94", u"Tiempo real por socket, traduccion completa, revision de QA "
                           u"y correcciones."),
]

DECISIONES = [
    (u"¿Se confirma el 10 de noviembre como fecha objetivo?",
     u"La propuesta inicial era el 3; se corrio una semana por las tres funcionalidades "
     u"que entraron tras la congelacion."),
    (u"¿Quienes forman el grupo piloto de la Fase 2?", None),
    (u"¿Hay entorno de preproduccion disponible, o hay que montarlo en Fase 0?", None),
    (u"¿Se acepta salir sin video a bordo ni integracion con el ERP?", None),
    (u"¿Quien habilita la clave de Google Places?",
     u"Sin eso, el autocompletado de direcciones parece roto desde dentro."),
    (u"¿La revision del 30-sep cubre parte de la Fase 1, o se repite entera con el equipo?",
     None),
]

# ============================================================== plantilla ===

RE_FUERTE = re.compile(r"\*\*(.+?)\*\*", re.S)
RE_CODIGO = re.compile(r"`(.+?)`", re.S)
RE_CURSIVA = re.compile(r"\*(.+?)\*", re.S)


def t(s):
    """Escapa y traduce el poco Markdown que traen los textos del plan."""
    s = html.escape(str(s), quote=True)
    s = RE_FUERTE.sub(r"<strong>\1</strong>", s)
    s = RE_CODIGO.sub(r"<code>\1</code>", s)
    s = RE_CURSIVA.sub(r"<em>\1</em>", s)
    return s


P = []
w = P.append

w("""<title>Migracion UI v2</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap">
<style>
/* Informe de estado. Banda de cifras arriba y luego una sola columna con
   tablas densas: es un documento para leer y proyectar, no un panel.
   La identidad sale de los tokens del propio producto (tokens.css,
   --brand-600 #1b43a3), no de una paleta inventada para la ocasion. */

:root {
  --azul:        #1b43a3;
  --azul-claro:  #e8edfa;
  --tinta:       #0d1322;
  --tinta-2:     #475069;
  --tinta-3:     #727b94;
  --papel:       #f6f7fb;
  --superficie:  #ffffff;
  --linea:       #dde2ed;
  --linea-2:     #ecf0f7;
  --hecho:       #0f7250;
  --hecho-bg:    #e2f3ec;
  --espera:      #9c5c00;
  --espera-bg:   #fbeeda;
  --parado:      #727b94;
  --parado-bg:   #eceef4;
  --fase-tinta:  #ffffff;

  --display: "Source Serif 4", Georgia, "Times New Roman", serif;
  --texto:   "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
  --dato:    "IBM Plex Mono", ui-monospace, "SFMono-Regular", Consolas, monospace;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    color-scheme: dark;
    --azul:        #86a7f2;
    --azul-claro:  #1a2440;
    --tinta:       #e9edf7;
    --tinta-2:     #aab3cb;
    --tinta-3:     #828ba4;
    --papel:       #0a0e18;
    --superficie:  #141a28;
    --linea:       #293145;
    --linea-2:     #1d2433;
    --hecho:       #4cc49b;
    --hecho-bg:    #12302a;
    --espera:      #e3a845;
    --espera-bg:   #332613;
    --parado:      #7e879f;
    --parado-bg:   #212838;
    --fase-tinta:  #0a0e18;
  }
}

:root[data-theme="dark"] {
  color-scheme: dark;
  --azul:        #86a7f2;
  --azul-claro:  #1a2440;
  --tinta:       #e9edf7;
  --tinta-2:     #aab3cb;
  --tinta-3:     #828ba4;
  --papel:       #0a0e18;
  --superficie:  #141a28;
  --linea:       #293145;
  --linea-2:     #1d2433;
  --hecho:       #4cc49b;
  --hecho-bg:    #12302a;
  --espera:      #e3a845;
  --espera-bg:   #332613;
  --parado:      #7e879f;
  --parado-bg:   #212838;
  --fase-tinta:  #0a0e18;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  background: var(--papel);
  color: var(--tinta);
  font-family: var(--texto);
  font-size: 15px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
}

code {
  font-family: var(--dato);
  font-size: .89em;
  background: var(--linea-2);
  border-radius: 3px;
  padding: .5px 4px;
  overflow-wrap: break-word;
}

.marco {
  max-width: 1060px;
  margin: 0 auto;
  padding-inline: 20px;
  padding-block: 40px 64px;
}

/* ------------------------------------------------------------ cabecera -- */
.cab { border-bottom: 2px solid var(--tinta); padding-bottom: 22px; margin-bottom: 30px; }
.cab__eyebrow {
  font-family: var(--dato); font-size: 11.5px; letter-spacing: .14em;
  text-transform: uppercase; color: var(--azul); margin: 0 0 10px;
}
.cab h1 {
  font-family: var(--display); font-weight: 700;
  font-size: clamp(31px, 5.5vw, 48px); line-height: 1.06;
  margin: 0 0 14px; letter-spacing: -.018em; text-wrap: balance;
}
.cab__sub { margin: 0; color: var(--tinta-2); max-width: 66ch; font-size: 15.5px; }
.cab__meta {
  margin-top: 18px; display: flex; flex-wrap: wrap; gap: 6px 20px;
  font-family: var(--dato); font-size: 12.5px; color: var(--tinta-3);
}
.cab__meta b { color: var(--tinta); font-weight: 600; }

/* -------------------------------------------------------------- cifras -- */
.cifras {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(168px, 1fr));
  gap: 1px; background: var(--linea); border: 1px solid var(--linea);
  border-radius: 10px; overflow: hidden; margin-bottom: 46px;
}
.cifra { background: var(--superficie); padding: 16px 18px; }
.cifra__n {
  font-family: var(--dato); font-size: 31px; font-weight: 600; line-height: 1;
  color: var(--azul); font-variant-numeric: tabular-nums;
}
.cifra__t { margin-top: 8px; font-size: 13.5px; font-weight: 600; }
.cifra__p { font-size: 12.5px; color: var(--tinta-3); }

/* ----------------------------------------------------------- secciones -- */
section { margin-bottom: 54px; }
.sec-cab { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 7px; }
.sec-cab h2 {
  font-family: var(--display); font-weight: 600; font-size: 26px;
  margin: 0; letter-spacing: -.012em;
}
.sec-cab .n {
  font-family: var(--dato); font-size: 11.5px; font-weight: 600; color: var(--azul);
  border: 1px solid currentColor; border-radius: 4px; padding: 1px 7px;
}
.sec-int { margin: 0 0 24px; color: var(--tinta-2); max-width: 72ch; }

/* ------------------------------------------------------ bloque y tabla -- */
.bloque { border-top: 1px solid var(--linea); padding-top: 14px; margin-top: 24px; }
.bloque__cab {
  display: flex; align-items: baseline; justify-content: space-between;
  gap: 14px; margin-bottom: 9px;
}
.bloque__cab h3 { font-size: 15.5px; font-weight: 600; margin: 0; }
.bloque__n {
  font-family: var(--dato); font-size: 12px; color: var(--tinta-3);
  white-space: nowrap; font-variant-numeric: tabular-nums;
}
.bloque__nota { margin: 0 0 12px; color: var(--tinta-2); font-size: 13.5px; max-width: 72ch; }

.tabla-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
td, th { text-align: left; vertical-align: top; padding: 6px 12px 6px 0; }
td:last-child, th:last-child { padding-right: 0; }
tbody tr + tr td { border-top: 1px solid var(--linea-2); }
th {
  font-family: var(--dato); font-size: 10.5px; letter-spacing: .1em;
  text-transform: uppercase; color: var(--tinta-3); font-weight: 500;
  border-bottom: 1px solid var(--linea); padding-bottom: 7px;
}
.fecha {
  font-family: var(--dato); font-size: 12.5px; color: var(--tinta-2);
  white-space: nowrap; font-variant-numeric: tabular-nums;
}
.dur {
  font-family: var(--dato); font-size: 12.5px; white-space: nowrap;
  font-variant-numeric: tabular-nums; color: var(--tinta-2);
}
.nota { color: var(--tinta-2); font-size: 13.5px; }

/* --------------------------------------------------------------- pill --- */
.pill {
  display: inline-block; font-family: var(--dato); font-size: 10.5px;
  font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
  padding: 2.5px 8px; border-radius: 999px; white-space: nowrap;
}
.pill--si  { color: var(--hecho);  background: var(--hecho-bg); }
.pill--no  { color: var(--espera); background: var(--espera-bg); }
.pill--off { color: var(--parado); background: var(--parado-bg); }

/* ----------------------------------------------------------- dos cajas -- */
.dos { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width: 720px) { .dos { grid-template-columns: 1fr; } }
.caja {
  background: var(--superficie); border: 1px solid var(--linea);
  border-radius: 10px; padding: 18px 20px;
}
.caja__t { display: flex; align-items: center; gap: 9px; margin-bottom: 3px; flex-wrap: wrap; }
.caja__t h3 { margin: 0; font-size: 14.5px; font-weight: 600; }
.caja__p { margin: 0 0 14px; font-size: 13px; color: var(--tinta-3); }
.lista { margin: 0; padding: 0; list-style: none; }
.lista li { padding: 10px 0; border-top: 1px solid var(--linea-2); }
.lista li:first-child { border-top: 0; padding-top: 0; }
.lista li:last-child { padding-bottom: 0; }
.lista b { display: block; font-weight: 600; font-size: 14px; margin-bottom: 1px; }
.lista span { color: var(--tinta-2); font-size: 13.5px; }

/* ------------------------------------------------------------ patrones -- */
.patron {
  display: grid; grid-template-columns: 54px 1fr; gap: 16px;
  padding: 14px 0; border-top: 1px solid var(--linea-2);
}
.patron:first-of-type { border-top: 0; padding-top: 2px; }
.patron__n {
  font-family: var(--dato); font-size: 23px; font-weight: 600; color: var(--azul);
  line-height: 1.05; font-variant-numeric: tabular-nums;
}
.patron__n small {
  display: block; font-size: 9.5px; letter-spacing: .09em; text-transform: uppercase;
  color: var(--tinta-3); font-weight: 500; margin-top: 2px;
}
.patron b { display: block; font-size: 14.5px; font-weight: 600; margin-bottom: 2px; }
.patron span { color: var(--tinta-2); font-size: 13.5px; }

/* --------------------------------------------------------------- fases -- */
.fase {
  display: grid; grid-template-columns: 46px 1fr; gap: 16px;
  padding: 16px 0; border-top: 1px solid var(--linea);
}
.fase__n {
  font-family: var(--dato); font-size: 18px; font-weight: 600;
  color: var(--fase-tinta); background: var(--azul);
  width: 34px; height: 34px; border-radius: 8px;
  display: grid; place-items: center;
}
.fase__t { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.fase__t h3 { margin: 0; font-size: 16px; font-weight: 600; }
.fase p { margin: 6px 0 0; color: var(--tinta-2); font-size: 13.5px; max-width: 72ch; }

/* ---------------------------------------------------------- decisiones -- */
.dec { counter-reset: d; margin: 0; padding: 0; list-style: none; }
.dec li {
  counter-increment: d; position: relative;
  padding: 11px 0 11px 34px; border-top: 1px solid var(--linea-2);
}
.dec li:first-child { border-top: 0; }
.dec li::before {
  content: counter(d); position: absolute; left: 0; top: 12px;
  font-family: var(--dato); font-size: 12px; font-weight: 600; color: var(--azul);
  background: var(--azul-claro); width: 22px; height: 22px; border-radius: 50%;
  display: grid; place-items: center;
}
.dec b { font-weight: 600; font-size: 14.5px; }
.dec span { display: block; color: var(--tinta-2); font-size: 13.5px; }

/* ----------------------------------------------------------------- pie -- */
.pie {
  margin-top: 58px; padding-top: 20px; border-top: 1px solid var(--linea);
  font-family: var(--dato); font-size: 12.5px; color: var(--tinta-3);
  display: flex; flex-wrap: wrap; gap: 6px 22px;
}
</style>
""")

# ------------------------------------------------------------- cabecera ---
w('<div class="marco">')
w('<header class="cab">')
w('<p class="cab__eyebrow">Talentus Pro Tracking &middot; rama <code>nuevo-tema</code></p>')
w('<h1>Migracion a la interfaz v2</h1>')
w('<p class="cab__sub">Estado del trabajo a 30 de septiembre de 2026. Produccion sigue '
  'corriendo la version clasica, sin tocar: la v2 convive bajo <code>/ui/v2/*</code> y no '
  'sustituye nada todavia. El despliegue sera una <strong>fusion a <code>main</code></strong> '
  'cuando lo pendiente este cerrado, no un goteo de cambios.</p>')
w('<div class="cab__meta">'
  '<span>Periodo <b>31-may &rarr; 30-sep-2026</b></span>'
  '<span>Salida propuesta <b>mar 10-nov-2026</b></span>'
  '<span>Vehiculos en la cuenta <b>1.429</b></span>'
  '</div>')
w('</header>')

# --------------------------------------------------------------- cifras ---
w('<div class="cifras">')
for n, titulo, pie in RESUMEN:
    w('<div class="cifra"><div class="cifra__n">%s</div>'
      '<div class="cifra__t">%s</div><div class="cifra__p">%s</div></div>'
      % (t(n), t(titulo), t(pie)))
w('</div>')

# ------------------------------------------------------------ entregado ---
w('<section>')
w('<div class="sec-cab"><span class="n">A</span><h2>Lo entregado</h2>'
  '<span class="fecha">106 funcionalidades</span></div>')
w('<p class="sec-int">Agrupado por modulo, con la fecha de entrega en la rama. Donde solo '
  'aparece el mes, el trabajo se repartio en varias sesiones de ese mes. Todas las fechas '
  'son de 2026.</p>')
for g in inv:
    w('<div class="bloque">')
    w('<div class="bloque__cab"><h3>%s</h3><span class="bloque__n">%d</span></div>'
      % (t(g["modulo"]), len(g["items"])))
    w('<div class="tabla-wrap"><table><tbody>')
    for it in g["items"]:
        w('<tr><td>%s</td><td class="fecha">%s</td></tr>' % (t(it["que"]), t(it["fecha"])))
    w('</tbody></table></div></div>')
w('</section>')

# ------------------------------------------------------------------ qa ---
w('<section>')
w('<div class="sec-cab"><span class="n">B</span><h2>Lo que QA ya probo</h2>'
  '<span class="fecha">30-sep-2026</span></div>')
w('<p class="sec-int">Se encargo una revision completa en navegador de las 21 paginas, con '
  'guion escrito (<code>docs/QA-UI-V2.md</code>) y comparacion contra el sistema clasico. '
  'Encontro <strong>32 defectos</strong> en dos vueltas —27 y 5 mas en el re-test— y hoy no '
  'queda ninguno abierto. Es trabajo de la Fase 1 adelantado: conviene descontarlo de ese '
  'bloque. El informe, con el commit de cada correccion, esta en '
  '<code>docs/QA-UI-V2-informe.md</code>.</p>')

w('<div class="dos">')
w('<div class="caja">'
  '<div class="caja__t"><span class="pill pill--si">cubierto</span>'
  '<h3>Probado y corregido</h3></div>'
  '<p class="caja__p">Nada pendiente en estos frentes.</p><ul class="lista">')
for ti, de in QA_CUBIERTO:
    w('<li><b>%s</b><span>%s</span></li>' % (t(ti), t(de)))
w('</ul></div>')
w('<div class="caja">'
  '<div class="caja__t"><span class="pill pill--no">sin cubrir</span>'
  '<h3>Queda para la Fase 1</h3></div>'
  '<p class="caja__p">La revision no llego aqui.</p><ul class="lista">')
for ti, de in QA_NO_CUBIERTO:
    w('<li><b>%s</b><span>%s</span></li>' % (t(ti), t(de)))
w('</ul></div>')
w('</div>')

w('<div class="bloque">')
w('<div class="bloque__cab"><h3>Los patrones que mas se repitieron</h3>'
  '<span class="bloque__n">8 de los 32</span></div>')
w('<p class="bloque__nota">Ordenados por impacto. Los cuatro son errores de los que no avisa '
  'nadie: la aplicacion responde con un numero, y el numero esta mal.</p>')
for ti, n, de in QA_PATRONES:
    w('<div class="patron"><div class="patron__n">%d<small>casos</small></div>'
      '<div><b>%s</b><span>%s</span></div></div>' % (n, t(ti), t(de)))
w('</div>')

w('<div class="bloque"><div class="bloque__cab"><h3>Y una nota incomoda</h3></div>')
w('<ul class="lista">')
for ti, de in QA_EXTRA:
    w('<li><b>%s</b><span>%s</span></li>' % (t(ti), t(de)))
w('</ul></div>')
w('</section>')

# ------------------------------------------------------------ pendiente ---
w('<section>')
w('<div class="sec-cab"><span class="n">C</span><h2>Lo pendiente</h2></div>')
w('<p class="sec-int">Con la fase en la que toca hacerlo y el tiempo estimado. Nada de esto '
  'bloquea la salida salvo que se decida lo contrario.</p>')
for b in PENDIENTE:
    w('<div class="bloque">')
    w('<div class="bloque__cab"><h3>%s</h3><span class="bloque__n">%d</span></div>'
      % (t(b["grupo"]), len(b["items"])))
    w('<p class="bloque__nota">%s</p>' % t(b["nota"]))
    w('<div class="tabla-wrap"><table><thead><tr>'
      '<th style="width:26%">Que</th><th>Detalle</th><th>Cuando</th><th>Estimado</th>'
      '</tr></thead><tbody>')
    for que, det, cuando, dur in b["items"]:
        w('<tr><td><strong>%s</strong></td><td class="nota">%s</td>'
          '<td class="fecha">%s</td><td class="dur">%s</td></tr>'
          % (t(que), t(det), t(cuando), t(dur)))
    w('</tbody></table></div></div>')

w('<div class="bloque">')
w('<div class="bloque__cab"><h3>Limitaciones que se dejan documentadas</h3>'
  '<span class="bloque__n">%d</span></div>' % len(LIMITACIONES))
w('<p class="bloque__nota">No son defectos: son decisiones o limites conocidos, escritos '
  'aqui para no volver a investigarlos.</p>')
w('<ul class="lista">')
for ti, de in LIMITACIONES:
    w('<li><b>%s</b><span>%s</span></li>' % (t(ti), t(de)))
w('</ul></div>')
w('</section>')

# -------------------------------------------------------------- pruebas ---
w('<section>')
w('<div class="sec-cab"><span class="n">D</span><h2>Plan de pruebas y calendario</h2>'
  '<span class="fecha">5-oct &rarr; 20-nov</span></div>')
w('<p class="sec-int">Cinco fases hasta la salida. El calendario asume dedicacion continua y '
  'que la Fase 1 no destape un defecto estructural; si lo destapa, la fecha se mueve. Es '
  'preferible mover la fecha que salir con un defecto conocido.</p>')
for n, titulo, desde, hasta, dur, det in FASES:
    rango = t(desde) + ("" if hasta is None else " &rarr; " + t(hasta))
    w('<div class="fase"><div class="fase__n">%s</div><div>'
      '<div class="fase__t"><h3>%s</h3><span class="fecha">%s</span>'
      '<span class="pill pill--off">%s</span></div><p>%s</p></div></div>'
      % (t(n), t(titulo), rango, t(dur), t(det)))

w('<div class="bloque">')
w('<div class="bloque__cab"><h3>Fase 1, bloque a bloque</h3>'
  '<span class="bloque__n">11 dias</span></div>')
w('<p class="bloque__nota">Cada bloque se recorre en tema claro y oscuro y en los dos '
  'idiomas. Lo marcado como <span class="pill pill--si">visto</span> lo cubrio la revision '
  'del 30-sep y deberia ir mas rapido de lo estimado.</p>')
w('<div class="tabla-wrap"><table><thead><tr>'
  '<th>Bloque</th><th>Dias</th><th>Estado</th></tr></thead><tbody>')
for titulo, dias, visto in FASE1:
    p = ('<span class="pill pill--si">visto</span>' if visto
         else '<span class="pill pill--no">sin ver</span>')
    w('<tr><td>%s</td><td class="dur">%d</td><td>%s</td></tr>' % (t(titulo), dias, p))
w('</tbody></table></div></div>')
w('</section>')

# ------------------------------------------------------------ actividad ---
w('<section>')
w('<div class="sec-cab"><span class="n">E</span><h2>Actividad por mes</h2>'
  '<span class="fecha">324 commits</span></div>')
w('<p class="sec-int">Agosto concentro los modulos nuevos y septiembre la estabilizacion y '
  'las correcciones de QA.</p>')
w('<div class="tabla-wrap"><table><thead><tr>'
  '<th style="width:14%">Mes</th><th style="width:10%">Commits</th><th>Que paso</th>'
  '</tr></thead><tbody>')
for mes, n, det in MESES:
    w('<tr><td class="fecha">%s</td><td class="dur">%s</td><td class="nota">%s</td></tr>'
      % (t(mes), t(n), t(det)))
w('</tbody></table></div>')
w('</section>')

# ----------------------------------------------------------- decisiones ---
w('<section>')
w('<div class="sec-cab"><span class="n">F</span><h2>Decisiones que hacen falta</h2></div>')
w('<p class="sec-int">Seis preguntas abiertas. Las cuatro primeras condicionan el '
  'calendario.</p>')
w('<ul class="dec">')
for pregunta, nota in DECISIONES:
    w('<li><b>%s</b>%s</li>'
      % (t(pregunta), "" if nota is None else "<span>%s</span>" % t(nota)))
w('</ul>')
w('</section>')

w('<footer class="pie">'
  '<span>Talentus Pro Tracking</span>'
  '<span>Rama <code>nuevo-tema</code>, 267 commits sin subir al remoto</span>'
  '<span>Generado el 30-sep-2026</span>'
  '</footer>')
w('</div>')

io.open(SALIDA, "w", encoding="utf-8", newline="").write("\n".join(P))
print("escrito %s  (%d KB)" % (SALIDA, os.path.getsize(SALIDA) // 1024))
