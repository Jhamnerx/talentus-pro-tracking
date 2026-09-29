# Revisión en navegador de la UI v2

> Encargo para un agente con acceso a navegador. Léelo entero antes de abrir
> la primera página: la sección **Datos** evita que reportes decenas de
> fallos que no lo son.

---

## 1 · Qué hay que hacer

Talentus Pro Tracking es una plataforma de rastreo GPS (Laravel 9, fork de
GPSWox 3.7.13). Se está migrando la interfaz a una versión nueva que convive
con la antigua:

- **UI v2** (la nueva) — todo bajo `/ui/v2/*`
- **Legacy** (la antigua) — el resto del sitio, y sigue funcionando

Tu trabajo es **recorrer toda la UI v2 en el navegador y comprobar que hace
lo mismo que el legacy**, sin regresiones. No es una revisión de diseño: es
comprobar que los datos salen, que los formularios guardan, que los listados
paginan y filtran, y que nada revienta.

Entrega un informe de fallos accionable. No arregles código.

---

## 2 · Datos: léelo o reportarás fallos falsos

Esta es una copia de desarrollo y **los datos están congelados en el
pasado**. Si pruebas con los rangos por defecto («hoy», «últimas 24 h»)
todo saldrá vacío y parecerá roto. No lo está.

| Hecho | Valor |
| ----- | ----- |
| Fecha del sistema | **29 de septiembre de 2026** |
| Última posición de cualquier equipo | **1 de julio de 2026** |
| Equipos dados de alta | 1.530 (1.509 con última posición) |
| Equipos con **historial real** | **uno solo: el 219** |

### El equipo 219 es el único que sirve para historial

- Nombre: **`T6G-611 - TOYOTA ETIOS`**
- Tiene **342.463 posiciones**, del **27-dic-2025** al **1-jul-2026**
- Días con más datos, buenos para probar: **2026-03-18** (3.076 posiciones),
  **2026-03-04**, **2026-04-07**, **2026-02-12**, **2026-02-27**

Los demás equipos tienen última posición pero **no tienen tabla de
posiciones con datos**. Las posiciones viven en tablas por dispositivo
(`positions_<id>`), y solo existen cuatro; únicamente la del 219 tiene
volumen.

**Regla práctica:** para historial, reproducción de recorrido, reportes y
cualquier cosa que dependa de posiciones, usa **siempre el equipo 219 y una
fecha de marzo o abril de 2026**. Si con esos parámetros sale vacío,
entonces sí es un fallo.

### Volúmenes del resto

| Dato | Filas |
| ---- | ----- |
| Eventos | 1.211.105 |
| Geocercas | 1.098 |
| Alertas | 8.614 |
| Usuarios | 778 |
| Servicios / mantenimientos | 115 |
| Reportes guardados | 43 |
| Rutas | 9 |
| Tareas | 1 |
| Gastos | 1 |

Tareas y gastos tienen **una sola fila**: un listado con un elemento es
correcto, no es un fallo.

---

## 3 · Acceso

- URL base: **`http://talentus-pro-tracking.test`**
- Entrada a la versión nueva: **`/ui/v2/live`**

**Las credenciales te las tiene que dar la persona que te encarga esto.** No
intentes adivinarlas ni crear usuarios.

Pide, si puede ser, **dos cuentas**: una de administrador y una de usuario
normal, porque media interfaz se muestra u oculta según permisos y hay
partes que solo se ven con una u otra.

---

## 4 · Reglas de seguridad

La base tiene datos que parecen reales (nombres de clientes, placas,
teléfonos). Trátalos como si lo fueran.

1. **No borres nada que no hayas creado tú.** Hay botones de eliminar por
   todas partes, incluido uno que borra *todos* los eventos. No los toques
   sobre registros existentes.
2. Para probar altas y ediciones, **crea tus propios registros**, con un
   prefijo reconocible (`QA-` en el nombre), y **bórralos al terminar**.
3. **No envíes comandos a equipos** (`/ui/v2/commands`): son SMS o GPRS
   reales hacia hardware. Llega hasta el formulario, comprueba que valida, y
   no confirmes el envío.
4. **No envíes correos ni SMS de prueba** desde las pantallas que lo ofrecen.
5. **No toques código ni configuración.** Solo navegador.
6. No publiques capturas con datos personales fuera de este informe.

Si dudas si una acción es destructiva, no la hagas y anótalo como «sin
probar, requiere confirmación».

---

## 5 · Páginas a revisar

Son 21. Para cada una: que cargue sin error, que los datos salgan, que los
filtros y la paginación respondan, que los formularios guarden, y que la
consola del navegador no tenga errores.

| # | Página v2 | Equivalente legacy | Qué mirar con atención |
| - | --------- | ------------------ | ---------------------- |
| 1 | `/ui/v2/live` | `/objects` | Mapa, marcadores, panel lateral de equipos, seguimiento, capas (geocercas, POIs, rutas), agrupación, tablero del vehículo en el panel de información |
| 2 | `/ui/v2/fleet` | `/objects` (vista lista) | Tabla de flota, columnas configurables, orden, filtros por estado |
| 3 | `/ui/v2/monitor` | — | Cuadrícula de seguimiento; probar los distintos tamaños de rejilla |
| 4 | `/ui/v2/dashboard` | `/dashboard` | Widgets, rangos de fecha, ranking de conducción |
| 5 | `/ui/v2/history` | `/history` | **Equipo 219 + marzo/abril 2026.** Reproducción, gráfica, listado de paradas y recorridos, exportación |
| 6 | `/ui/v2/events` | `/events` | 1,2 M de filas: paginación y filtros son lo crítico aquí |
| 7 | `/ui/v2/alerts` | `/alerts` | Alta y edición de reglas (asistente por pasos), horario, notificaciones |
| 8 | `/ui/v2/zones` | `/geofences` | Dibujar polígono y círculo, editar vértices, grupos, límites por tipo de vehículo |
| 9 | `/ui/v2/routes/table` | `/routes` | Listado, dibujo de ruta, importar y exportar |
| 10 | `/ui/v2/reports` | `/reports` | Generar un reporte con el 219 y marzo 2026; reportes guardados; programación |
| 11 | `/ui/v2/maintenance` | `/maintenance` | 115 servicios: listado, alta, renovación |
| 12 | `/ui/v2/expenses` | `/expenses` | Solo 1 fila; probar el alta (ojo al formato de fecha) |
| 13 | `/ui/v2/tasks` | `/tasks` | Solo 1 fila; alta, conjuntos de tareas |
| 14 | `/ui/v2/drivers` | `/drivers` | Alta, asignación a vehículo |
| 15 | `/ui/v2/sharing` | `/sharing` | Crear enlace de compartir y abrirlo en ventana privada |
| 16 | `/ui/v2/commands` | `/send_command` | **Solo hasta el formulario. No enviar.** |
| 17 | `/ui/v2/chat` | `/chat` | Selección de vehículo, conversación |
| 18 | `/ui/v2/call-actions` | — | Alta y edición |
| 19 | `/ui/v2/route-schedules` | — | Programaciones, puntos de control, modal de puntos |
| 20 | `/ui/v2/copilot` | — | Preguntas en lenguaje natural; comprueba que las sugerencias llevan a la página correcta |
| 21 | `/ui/v2/legacy` | — | Debe devolver a la interfaz antigua |

### Elementos comunes, en todas las páginas

- **Barra superior**: buscador global, bandeja de notificaciones, menú de
  usuario, paleta de comandos (atajo de teclado)
- **Menú lateral (rail)**: plegar y desplegar, que recuerde el estado
- **Modales**: abrir, validar campos vacíos, guardar, cancelar, cerrar con
  Escape
- **Modo oscuro y modo claro**: alterna y comprueba que no queda texto
  ilegible ni fondos en blanco
- **Móvil**: repite el recorrido a ~390 px de ancho. El menú se abre con el
  botón de la barra superior y la lista de vehículos con el tirador

---

## 6 · Traducción

La interfaz se acaba de traducir por completo (vistas, controladores y
JavaScript). Conviene comprobarlo:

1. Cambia el idioma a **inglés** desde los ajustes de cuenta.
2. Recorre las páginas principales.
3. Reporta: texto que siga en castellano, y texto que salga como
   **identificador crudo** (por ejemplo `ui.vehicle_not_found` o
   `front.address` literal en pantalla). Lo segundo significa clave que falta.

Hay **ocho cadenas que se dejaron a propósito** sin traducir porque no son
texto visible (clases CSS, fragmentos de atributo, un prefijo de consola y el
parámetro `y,traffic`). Si te topas con alguna, no la reportes.

---

## 7 · Qué cuenta como fallo, y con qué gravedad

| Gravedad | Qué es |
| -------- | ------ |
| **Bloqueante** | La página no carga, error 500, pantalla en blanco, no se puede completar la acción principal |
| **Alto** | Los datos no salen o salen mal (cifras distintas del legacy), un formulario no guarda, se pierde información |
| **Medio** | Filtro u orden que no responde, paginación rota, error en consola que no impide usar la página |
| **Bajo** | Texto sin traducir, desalineación, icono equivocado, contraste pobre |

**La comparación con el legacy es la prueba de fondo.** Cuando una cifra te
parezca rara, abre la página equivalente del legacy con los mismos filtros y
compara. Si difieren, es un fallo de alto nivel aunque la v2 no dé ningún
error.

---

## 8 · Cómo entregar el informe

Una tabla, ordenada de más grave a menos:

| # | Gravedad | Página | Qué falla | Cómo reproducirlo | Esperado | Obtenido |
| - | -------- | ------ | --------- | ----------------- | -------- | -------- |

Para cada fallo:

- **Cómo reproducirlo**: pasos numerados, con los valores exactos que usaste
  (equipo, fechas, filtros). Sin esto el fallo no se puede arreglar.
- **Consola**: pega el error del navegador si lo hay, con el fichero y la
  línea.
- **Red**: si una llamada falla, di la URL, el método y el código de estado.
- **Captura**: adjunta una si el problema es visual.

Al final, añade:

- **Qué probaste y salió bien** (para saber qué quedó cubierto)
- **Qué no pudiste probar y por qué** (falta de permisos, acción destructiva,
  datos insuficientes)

---

## 9 · Ya conocido: no lo reportes

- **Datos congelados en julio de 2026** y un solo equipo con historial. Ver
  §2.
- **Tareas y gastos con una sola fila**. Es lo que hay en la base.
- **El servidor de sockets puede no estar arrancado** (`socket/socket.js`,
  puertos 9001/9002). Si ves `ERR_CONNECTION_REFUSED` contra esos puertos, es
  eso: el tiempo real (avisos emergentes, fotos de cámara, chat) no llegará.
  Anótalo una vez y sigue; no lo repitas en cada página.
- **Las 8 cadenas sin traducir a propósito**. Ver §6.
- **El decodificador de vídeo a bordo no está integrado**: la pestaña avisa
  de ello. Es lo previsto.
- **Seis cuentas guardan un tema inválido** (`airpatrol`). Ya hay un respaldo
  que sirve `light-green`, así que no deberías ver ninguna página sin
  estilos. **Si la ves, eso sí es un fallo y hay que reportarlo.**

---

## 10 · Orden sugerido

1. Entrar, recorrer las 21 páginas **sin tocar nada**, solo mirando que
   cargan y que hay datos. Anota los bloqueantes.
2. Segunda vuelta por las cuatro pantallas de más peso —`live`, `history`,
   `reports`, `events`— probando filtros, rangos y exportaciones.
3. Tercera vuelta de altas y ediciones, con tus propios registros `QA-`.
4. Vuelta en inglés (§6).
5. Vuelta en móvil (~390 px).
6. Vuelta en modo oscuro.
7. Limpiar los registros `QA-` que hayas creado.
8. Escribir el informe.

Si el tiempo no da para todo, prioriza 1, 2 y 3, y di explícitamente qué
pasos quedaron sin hacer.
