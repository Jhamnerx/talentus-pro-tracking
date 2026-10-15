# Guía de actualización — merge `operaciones` → `nuevo-tema` (12-oct-2026)

Cubre todo lo que entra con el merge commit `8b6dc288` (54 commits, 135 archivos).
Sirve tanto para el servidor de pruebas (`nuevo-tema`) como, más adelante, para
producción (MariaDB 10.5.29) cuando `nuevo-tema` pase a `master`.

Tres bloques entraron juntos:

| Bloque | Qué es | Necesita algo en el servidor |
| ------ | ------ | ---------------------------- |
| **A. Módulo de Operaciones** (control de vueltas), fases 1–3 + tipo «ruta» | Entidades, 5 migraciones, comando programado, reportes 910–913, alerta nueva, página v2 | **Sí**: migraciones, `.env`, cron, reiniciar `insert:run`, permiso por usuario |
| **B. Arreglos de la UI v2** (live, monitor, historial, dashboard, fichas) | Solo código y assets ya compilados | Limpiar caché de vistas |
| **C. Plugins del admin que no hacían nada** (SIM, Eventos recientes) | Código | Revisar que el admin los tenga como quiere |

---

## 1. Antes de empezar

1. **Backup de la base de datos.** Las migraciones solo crean tablas nuevas y
   añaden columnas (no borran nada), pero `geofences` gana una columna + índice.
2. Confirmar la versión del motor: todas las migraciones usan tipos portables
   (`dateTime`, JSON como `text`, FKs `unsignedInteger`), probadas en MySQL 8 y
   pensadas para MariaDB 10.5. No usan funciones espaciales.
3. Anotar los **procesos de larga vida** que corren en el servidor: `insert:run`
   (guarda posiciones desde Redis) y los workers `queue:work`. Ambos cargan el
   código PHP en memoria y hay que reiniciarlos para que vean los cambios.

---

## 2. Pasos de despliegue (en orden)

### 2.1 Código

```bash
git fetch origin
git checkout nuevo-tema
git pull origin nuevo-tema
composer install --no-dev --optimize-autoloader
```

No hay dependencias Composer nuevas; el `composer install` solo regenera el
autoload para que encuentre las clases nuevas (`Tobuli\Entities\Operation*`,
`Tobuli\Services\Operations\*`, `App\Console\Commands\OperationsProcessCommand`).

### 2.2 Variable de entorno

Añadir al `.env`:

```ini
OPERATIONS_ENABLED=true
```

Es el **interruptor de despliegue y de vuelta atrás** (`config('tobuli.operations_enabled')`).
Apagado (valor por defecto), `PositionsWriter` no hace ninguna consulta ni
evaluación por el módulo, el comando `operations:process` sale sin tocar nada,
y la página sigue visible solo para quien tenga el permiso. Se puede desplegar
con `false`, correr migraciones, y encenderlo cuando el cliente esté listo.

### 2.3 Migraciones

```bash
php artisan migrate --force
```

Las cinco que entran, en orden:

| Migración | Qué hace |
| --------- | -------- |
| `2026_10_06_000001_add_category_to_geofences_table` | `geofences.category` string(20) nullable + índice. Idempotente (`hasColumn`). |
| `2026_10_06_000002_create_operations_tables` | Crea `operations`, `operation_shifts`, `operation_points`, `operation_cycles`, `operation_legs`, `operation_visits`, `operation_stops`, `operation_device_state`, `operation_recalculations`. |
| `2026_10_07_000001_create_operation_stop_reasons_table` | Crea `operation_stop_reasons` y añade `reason_id`, `note`, `justified_by`, `justified_at` a `operation_stops` (fase 2). |
| `2026_10_09_000001_add_kind_to_operations_table` | `operations.kind` (`haul` acarreo / `route` ruta). |
| `2026_10_11_000001_add_worked_hours_deductions_to_operations_table` | `operations.worked_hours_deductions` (JSON como texto, fase 3). |

No hay seeders. El catálogo de motivos de parada (`operation_stop_reasons`) se
siembra solo, por usuario, la primera vez que abre «Motivos» en la página.

### 2.4 Cachés y procesos

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

Después reiniciar lo que tenga PHP cargado en memoria:

- **`insert:run`** (obligatorio para el módulo): es el proceso que escribe
  posiciones, y ahí se engancha `OperationChecker`. Si corre bajo supervisor,
  `supervisorctl restart <programa>`; si corre por el cron del scheduler, matar
  el proceso vivo y dejar que el cron lo levante.
- **Workers de cola** (`php artisan queue:restart`).
- **OPcache / PHP-FPM** si está activo (`systemctl reload php-fpm` o equivalente).

### 2.5 Cron

No hay nada nuevo que añadir al crontab: el comando se registró en el scheduler
de Laravel (`app/Console/Kernel.php`):

```
operations:process   cada minuto, withoutOverlapping
```

Solo verificar que el cron de `schedule:run` sigue activo. Comprobar que corre:

```bash
php artisan operations:process
```

Debe terminar sin error y sin salida cuando no hay operaciones (o con el módulo
apagado). También acepta `--legs` y `--rebuilds` para forzar solo una parte.

### 2.6 Assets

**No hay que compilar nada.** Los archivos compilados de la UI v2
(`public/assets/ui/v2/css/*`, `public/assets/ui/v2/js/*`, incluidos los nuevos
`tp-operations.js`, `operations.css`, `tp-map-layers.js`,
`lib/Leaflet.Marker.SlideTo.js`) y el bundle legacy `public/assets/js/app.js`
vienen en el repositorio.

Solo si en el servidor se recompila por costumbre: `gulp ui-v2` y `gulp scripts`.
El `gulpfile.js` cambió (clean-css con `tidySelectors: false`, y SlideTo añadido
al bundle de librerías); recompilar con un gulpfile viejo volvería a romper el
modo oscuro de los calendarios.

### 2.7 Traducciones

Los archivos `resources/lang/{es,en}/{front,ui,validation}.php` y
`resources/original_lang/*` ya vienen con las claves. Si el servidor usa
`php artisan server:translations` como parte del deploy, correrlo como siempre;
no hace falta nada más.

---

## 3. Configuración funcional después del deploy

### 3.1 Permiso `operations` (por usuario)

Es un módulo nuevo en `config/permissions.php` (`view`, `edit`, `remove`).
**No se siembra en `user_permissions`**: ningún usuario lo tiene hasta que el
admin lo marque en su ficha (Admin → Usuarios → editar → Permisos → «Operaciones»).

Gobierna: la entrada «Operaciones» en el rail de la v2, todas las rutas
`ui/v2/operations*`, y la visibilidad de los reportes 910–913 (vía
`isUserEnabled()` del reporte).

Para abrirlo a todos los usuarios de golpe (no recomendado durante el piloto):

```bash
php artisan tinker --execute="app(\Tobuli\Services\PermissionService::class)->addToAll('operations');"
```

### 3.2 Alta de una operación (lo hace el cliente o quien lo configure)

1. **Geocercas con categoría.** En el editor de geocercas (v2 y legacy) hay un
   selector «Categoría»: `carga`, `descarga`, `inicio_ruta`, `fin_ruta`,
   `grifo`, `taller`, `descanso`. Las de carga/descarga (o inicio/fin de ruta)
   son los puntos de la operación; `grifo` alimenta el reporte 913 y el
   descuento «Abastecimiento» del 912; `grifo/taller/descanso` son las zonas
   donde una parada cuenta como autorizada.
2. **Grupo de vehículos** con las unidades de la operación.
3. **Operación** (página Operaciones → Nueva): nombre, tipo (acarreo o ruta),
   grupo, puntos de carga/descarga, turnos (admiten cruzar medianoche),
   umbrales (permanencia mínima, duración de parada, horas máximas por tramo),
   categorías autorizadas y descuentos de horas trabajadas.
4. Desde ese momento las posiciones nuevas generan vueltas. Para el pasado,
   «Recalcular» con un rango: encola un recálculo que `operations:process`
   ejecuta.

Reglas que cambian comportamiento existente:

- **Una geocerca o un grupo usados por una operación no se pueden borrar**
  (v2 y legacy devuelven error de validación). Primero hay que quitarlos de la
  operación.
- Las geocercas **móviles** no se aceptan como punto.
- Editar la geometría de una geocerca que es punto crea una **versión nueva del
  punto** (las vueltas pasadas se quedan con la geometría con la que se
  calcularon).

### 3.3 Alerta «Parada en operación»

Tipo nuevo `operation_stop` en el modal de alertas (v2 y legacy), con umbral en
segundos (`operation_stop_seconds`, por defecto 60). Solo dispara en vehículos
que están en un tramo de una operación activa, fuera de zona autorizada. No
necesita nada en la base: usa las columnas de `alerts` existentes. Hay que
**crearla por usuario** como cualquier alerta.

### 3.4 Reportes nuevos (visibles solo con el permiso `operations`)

| ID | Nombre | Muestra |
| -- | ------ | ------- |
| 910 | Control de vueltas | Una fila por vuelta: tiempos en cada punto, ida, vuelta, ciclo, paradas; totales por día o turno. Es el que abre «Exportar» en la pestaña Vueltas. |
| 911 | Paradas en operación | Una fila por parada dentro de un tramo, con estado (autorizada / no / dudosa) y justificación. |
| 912 | Horas trabajadas netas | Por día: horas de motor, descuentos activos, horas netas, combustible y consumo por hora. |
| 913 | Abastecimiento | Visitas a geocercas `grifo`: entrada, salida, duración. |

Registrados en `Tobuli/Reports/ReportManager::$types`; no hay tabla de tipos
que actualizar.

---

## 4. Verificación tras el deploy

```bash
php artisan migrate:status | grep 2026_10
php artisan schedule:list | grep operations
php artisan operations:process
```

Y en la interfaz, con un usuario que tenga el permiso:

1. El rail de la v2 muestra «Operaciones»; la página abre con las tres pestañas
   (En vivo, Vueltas, Operaciones).
2. Editor de geocercas: aparece el selector «Categoría» y el filtro por
   categoría en la lista de zonas.
3. Modal de alertas: aparece el tipo «Parada en operación».
4. Reportes: los cuatro nuevos están en la lista; un reporte sin datos debe
   devolver una página vacía, no un error (`4d6711a5`).
5. Intentar borrar una geocerca usada por una operación debe fallar con el
   mensaje correspondiente.

Si el módulo está encendido y hay una operación activa, en un par de minutos la
pestaña «En vivo» debe mostrar el estado de cada vehículo (en carga, ida, en
descarga, vuelta).

---

## 5. Qué más entra en el mismo merge (bloques B y C)

No piden pasos de despliegue, pero conviene saberlo para las pruebas:

**UI v2**

- Monitor: capas del mapa, resplandor del seleccionado, color por velocidad y
  animación de movimiento del marcador (plugin del admin
  `device_move_animation`, el mismo que usa el legacy).
- Live: colores de estado alineados con el legacy (una sola paleta), estado
  «Detenido» en el mapa, icono de encendido con `acc.svg`/`acc_off.svg`, unidad
  sin transmitir ya no sale como viva, dirección cacheada por posición.
- Historial: hora libre `HH:MM` en el rango (sin redondeo a 5 min), «última
  hora» abre la última hora, popup del punto conserva dirección y botón «más».
- Dashboard: no confunde «detenido» con «ralentí»; el modal de tablero abría
  vacío con `id="undefined"`.
- Ficha de vehículo: el editor de tramos de color por velocidad ya no reescribe
  el primer «desde» a 0; el primer tramo por defecto en `config/tobuli.php`
  empieza en 1 kph (a 0 el vehículo está parado y no se colorea).
- Etiqueta fija de la geocerca con nombre y tamaño en el mapa.

**Plugins del admin que no funcionaban** (revisar cómo los tiene marcados el
admin, porque ahora sí obedecen):

- «Renovar la fecha de vencimiento de la SIM después de renovar la suscripción»:
  la clave estaba mal escrita (`_renew_sim_expiration`) y el listener nunca
  actuaba. Ahora, si está marcado, al renovar una suscripción renueva la SIM.
- «Eventos recientes» (widget): salía siempre aunque estuviera apagado. Ahora
  respeta el interruptor.

**Repositorio**

- `public/images/device_images` pasa a `.gitignore` (fotos subidas de cada
  instalación). Si en el servidor ese directorio tenía archivos versionados,
  `git pull` no los borra; quedan como están.

---

## 6. Vuelta atrás

- **Rápida (sin tocar código):** `OPERATIONS_ENABLED=false` + `config:clear` +
  reiniciar `insert:run`. El módulo deja de evaluar posiciones y el comando
  programado no hace nada. Las tablas y la página quedan, sin efecto.
- **Completa:** `git revert -m 1 8b6dc288` en `nuevo-tema` revierte los tres
  bloques de una vez. Las migraciones tienen `down()`; `php artisan migrate:rollback --step=5`
  elimina las tablas y columnas (se pierden las vueltas calculadas).

---

## 7. Pendientes conocidos (no bloquean el deploy)

- `round` → `floor` en `StopDurationAlertCheck` (afecta a todas las alertas de
  duración de parada, no solo a Operaciones; decisión pendiente).
- Confirmar con el cliente si cada motivo de parada **autoriza** la parada o
  solo la explica.
- Validar los conteos de vueltas contra el control manual del cliente antes de
  darlo por bueno.
- No hay pruebas automatizadas del módulo (decisión del proyecto); la validación
  es manual con recálculo sobre rangos conocidos.

---

## 8. Módulo «Seguridad · Control de flota» (rama `seguridad`, 15-oct-2026)

Entra en un merge posterior al de Operaciones. Es el dashboard que reproduce el
Excel `Dashboard_FM_Seguridad.xlsx` del cliente: agregados diarios por unidad y
turno, estado en vivo, gráficos y exportación al mismo Excel.

### 8.1 Pasos en el servidor

```bash
git pull
php artisan migrate --force
```

Migración `2026_10_15_000001_create_security_tables`: crea `security_settings`
(ajustes por usuario) y `security_daily_stats` (agregados). Solo crea tablas.

En el `.env`:

```ini
SECURITY_ENABLED=true
```

Es el interruptor del módulo igual que `OPERATIONS_ENABLED`: apagado, el
comando programado no hace nada. Después, `php artisan config:cache` (o
`config:clear` si no se cachea) y `php artisan view:clear`.

No hay dependencias nuevas ni hay que compilar: los bundles de la v2 van en
el repositorio. No hace falta tocar el crontab: `security:aggregate` ya está
en el scheduler (cada hora, minuto 20, recalcula ayer y hoy de los usuarios
que tengan el permiso y hayan abierto la pantalla al menos una vez).

### 8.2 Permiso y carga inicial

1. Admin → Usuarios → Editar → Permisos → **Seguridad** (ver, editar). Sin
   «editar» el usuario consulta pero no cambia ajustes ni recalcula.
2. El usuario abre **Seguridad** en el menú (eso crea sus ajustes por defecto:
   turno Día 07:00–19:00, vigencia 10 min, parada 1 min, hueco de señal 10 min,
   sin límite manual).
3. Carga inicial del histórico desde consola (hasta 31 días también se puede
   desde el botón «Recalcular» de la pantalla):

```bash
php artisan security:aggregate --user=<id> --from=2026-09-01 --to=$(date +%F)
```

### 8.3 Qué muestra y de dónde sale

| Dato | Fuente |
| --- | --- |
| km, horas de motor, horas en movimiento, paradas, máx. GPS | Pipeline de historial por turno |
| Sobrevelocidad | Eventos de alertas (exceso y exceso en geocerca) **más** tramos calculados: dentro de geocerca manda el límite de la geocerca; fuera, el límite manual del filtro (vacío = no se cuenta). Sin duplicar |
| Salidas de geocerca | Cada salida de cualquier geocerca del usuario (cerrada dentro del turno) |
| Desconexiones | Eventos `power_cut` y `offline_duration` + huecos de señal mayores al ajuste |
| Cinturón | Solo unidades con sensor de tipo «cinturón»; sin sensor, celda vacía |
| Estado en vivo | Última posición: vencida si supera la vigencia; movimiento ≥ 1 km/h; stand by = motor encendido |

### 8.4 Verificación

- La pantalla carga con 8 tarjetas, tabla de estado actual, resumen diario,
  detalle por unidad y 4 gráficos.
- «Exportar a Excel» descarga un libro con las hojas Seguridad, Operaciones,
  Datos y Telemetría y los 4 gráficos; las cifras coinciden con la pantalla.
- Cambiar el límite manual o el turno muestra el aviso de recalcular; tras
  «Recalcular» cambian los excesos.

### 8.5 Vuelta atrás

`SECURITY_ENABLED=false` detiene el cálculo; `php artisan migrate:rollback --step=1 --force`
elimina las dos tablas (se pierden los agregados, que se regeneran con el comando).
