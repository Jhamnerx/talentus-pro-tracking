# Estado de los planes del proyecto · 19-oct-2026

Revisión de cada plan o informe con tareas contra el código y el historial de git (rama `seguridad`, al día con `origin`).

## Resumen

| Plan / informe | Fecha | Estado | Pendiente |
| --- | --- | --- | --- |
| Reportes 904/905/906 (prioridad de geocercas) | 17-jun | ✅ Terminado (5 de 6 tareas; la 6 eran tests que se quitaron por decisión) | Nada |
| Tipos/estados de vehículo y límites por geocerca | jun | ✅ Terminado | Export/import GEXP de límites (ver legacy QA #10) |
| Plan de migración Legacy → UI v2 (Olas 0 a 6) | 19-ago | 🟡 Olas 1-6 completas; Ola 0 cerrada salvo F8 y F18 | F8 colores de icono (`disabled` en vez de ocultar) · F18 validadores de bloqueo e historial paginado · Vídeo a bordo y Suscripciones aplazados por el cliente |
| Hitos kilométricos | 21-jul | ✅ Terminado (migración `2026_07_21`, `tp-hitos.js`) | Nada |
| QA UI v2 (29/30-sep) | 30-sep | ✅ 17 hallazgos corregidos | Nada |
| Accesibilidad UI v2 | 1-oct | 🟡 6 de 8 criterios corregidos | Rejilla de horario de alertas con `role=grid` (3-4 h) · 49 campos sin etiqueta (aplazado) · 2 trozos de código muerto |
| QA legacy `/objects` vs columnas nuevas | 6-oct | 🟡 3 de 18 corregidos | Ver tabla abajo |
| Operaciones (control de vueltas) | 6 a 14-oct | ✅ Terminado y fusionado en `nuevo-tema` (`8b6dc288`) | 4 pendientes conocidos de la guía §7 |
| Seguridad · Control de flota | 14 a 19-oct | 🟡 Tareas 1 a 9 hechas; Task 10 parcial | Merge a `nuevo-tema`, PDF del manual, confirmar pasos en el servidor |
| Revisión QA del sistema (19-oct) | 19-oct | 🟡 7 correcciones subidas | Permisos del usuario de prueba, socket 9002, mejoras UX listadas |
| Clasificación de vehículo por usuario | 19-oct | ✅ Tasks 1 a 7 hechas (rama `clasificacion-por-usuario`) | Merge a `nuevo-tema` tras pruebas · migración de borrado de `devices.vehicle_type_id`/`vehicle_state_id` (versión posterior) |

## 1. Reportes 904/905/906 · `docs/superpowers/plans/2026-06-17-overspeed-geofence-reports.md`

| Task | Estado | Evidencia |
| --- | --- | --- |
| 1 `PolygonHelper::getArea()` | ✅ | `Tobuli/Helpers/PolygonHelper.php:187` |
| 2 `Geofence::approxAreaSqMeters()` / `pickBySpeedLimitPriority()` | ✅ | `Tobuli/Entities/Geofence.php:284, 321` |
| 3 `AppendSpeedLimitGeofenceByPriority` | ✅ | acción creada; ampliada el 23-jun con tipo/estado de vehículo |
| 4 Conectar a 905/906 sin tocar el motor compartido | ✅ | V2 y Buenaventura usan ByPriority; `RagGeofencesReport` intacto |
| 5 Reescribir 904 (toggle `only_violations`, un evento por geocerca) | ✅ | `OverspeedsInGeofenceCustomReport.php:22, 37, 72-113` |
| 6 Verificación y tests | ⚪ Tests eliminados a propósito (`477ab5ae`, «sin tests automatizados») | Smoke manual sin rastro en git |

Ninguna casilla del plan está marcada; el trabajo está hecho.

## 2. Seguridad · Control de flota · `docs/superpowers/plans/2026-10-14-seguridad-flota.md`

| Task | Estado |
| --- | --- |
| 1 Migración y entidades | ✅ |
| 2 `SecurityShift` | ✅ |
| 3 `AppendSpeedLimitGeofenceOrStatic` | ✅ |
| 4 `SecurityDailyCalculator` + `NoDataDuration` | ✅ |
| 5 `security:aggregate` + scheduler + permiso + flag | ✅ |
| 6 `SecurityLiveStatus` / `SecuritySummary` | ✅ |
| 7 Controlador, rutas, rail | ✅ |
| 8 Página, JS, CSS, i18n | ✅ |
| 9 Excel 4 hojas con gráficos | ✅ |
| 10.1 Capítulo del manual | ✅ (4 capturas) |
| 10.2 Guía de actualización §8 | ✅ |
| 10.3 Merge `--no-ff` a `nuevo-tema` | ⏳ Pendiente: 26 commits por fusionar (`nuevo-tema` sigue en `f01f3beb`) |
| 10.4 Pasos en el servidor de pruebas | 🟡 Desplegado y funcionando (se usó en la QA del 19-oct); falta confirmar `config:cache` y el recálculo completo |

Notas:
- El PDF del manual no está en git y es anterior al HTML; regenerarlo antes de entregar.
- `docs/Dashboard_FM_Seguridad.xlsx` sigue sin versionar: decidir si se sube o se ignora.
- Al fusionar entrarán también 5 commits que no son del módulo: selector de geocercas en alertas, avisos, correcciones QA y el informe. Es lo esperado: todos van a pruebas.

## 3. Plan de migración Legacy → UI v2 · `plan de migracion.md`

- Olas 1 a 6: completas el 19-ago.
- Ola 0 (S1-S9, D1-D16, F1-F19): cerrada el 25-ago según `docs/TALENTUS_MIGRATION_STATUS.md §3`. El archivo del plan no se actualizó y aún muestra F8, F9, F10, F12, F14, F17, F18 y F19 sin marcar. Verificación de hoy en la sección 3.1.
- Fuera de alcance por decisión del cliente: vídeo a bordo (decoder JT808/Howen) y suscripciones/planes (ERP). Administración y páginas públicas se quedan en el legacy.

### 3.1 Verificación de los F sin marcar

| Item | Estado | Evidencia |
| --- | --- | --- |
| F8 Colores del icono cuando el tipo no es «Flecha» | ✅ Corregido 19-oct (rama `correcciones-oct`) | los selects de color solo se ocultan, como el legacy, y viajan siempre |
| F9 Pestaña Sensores para todos, icono de mapeo, importar calibración | ✅ (matiz) | gate `perm('devices','edit')` en `DevicesController.php:1383`; mapeo e importación portados. Quien solo tiene «ver» no la ve |
| F10 Modal de beacons | ✅ | `api.beacons.store/update`, `BeaconFormValidator` + `DeviceService` (19-ago) |
| F12 `template_webhook` y `listsTranslations()` | ✅ | `DevicesController.php:3140-3158`, `device-widgets.blade.php:140`, `ShellController.php:256, 294` |
| F14 Equivalente de ObjectsList | ✅ | `fleet.js:116-339` columnas configurables y colores por rango; `api.fleet.columns` GET/PUT |
| F17 Pestaña Historial + `configure_device`/APN en el alta | ✅ | `partials/historial.blade.php`, `principal.blade.php:104-131` |
| F18 Validadores de LockStatus e historial paginado | 🟡 Parcial | `lockCommand` valida a mano sin `LockStatusTableValidator`/`UnlockValidator`; `lockHistory` sin paginar |
| F19 Popups del admin | ✅ | `layout.blade.php:103, 137` + `partials/admin-popups` |

Conclusión: la Ola 0 está cerrada salvo F8 (pendiente) y F18 (parcial). Conviene actualizar `plan de migracion.md §1.3` marcando F9, F10, F12, F14, F17 y F19.

## 4. Accesibilidad UI v2 · `docs/accesibilidad-ui-v2.md`

Corregido: botones sin nombre, foco, destino táctil, movimiento reducido, iconos decorativos, elementos con click (6 criterios).

Pendiente:
- ~~Rejilla del horario de alertas~~ ✅ 19-oct: `role=grid/row/gridcell`, `aria-selected`, tabindex itinerante y flechas/Espacio.
- 49 campos de formulario sin etiqueta (18 en `sensor-form.blade.php`, 31 en otros 20 blades). Aplazado.
- Código muerto: `fleet.js` (`[data-status]`) borrado el 19-oct; el de `live.js` (`.tp-live__paneltab`) no se localizó en las líneas indicadas, queda por revisar.

## 5. QA legacy `/objects` · `docs/QA-LEGACY-OBJECTS-informe.md` (6-oct, 18 fallos)

| # | Fallo | Estado |
| --- | --- | --- |
| 1 | Corredor no se puede crear en legacy (`polyline`) | ✅ `geofences.js:86` |
| 2 | La v2 convertía el corredor en polígono | ✅ `live-zones.js` rama `line` |
| 3 | `suspended_at` nunca se guardaba | ✅ `DeviceModalHelper.php:557` (`forceFill`) |
| 4 | Vaciar «Velocidad por defecto» guarda 0 | ✅ 19-oct: validador `nullable|numeric|min:1`; la v2 manda `null` al vaciar |
| 5 | Admin con tipos propios borra el tipo de vehículo ajeno | 🟡 El select incluye el tipo actual (`DeviceModalHelper.php:465`), pendiente confirmar que no se pierde al guardar |
| 6 | Editar forma con capa de geocercas oculta | ❌ Sin cambios |
| 7 | Al legacy le faltan campos nuevos (`cost_per_km`, `marker_*`) | ❌ Sin cambios |
| 8 | Columnas nuevas del device sin validar | ✅ 19-oct: reglas para `cost_per_km`, `route_color_type`, `route_color`, rangos JSON y `marker_*` |
| 9 | Límites por tipo aceptan cualquier cosa; tipo inexistente da 500 | ✅ 19-oct: `min:1` y comprobación de tipo/estado del dueño antes de escribir (422, sin guardado a medias) |
| 10 | Export GEXP sin límites de velocidad | ✅ 19-oct: el `.gexp` lleva `speed_limit`, `additional` y los límites por tipo/estado por nombre; el importador los resuelve o crea los tipos |
| 11 | Borrar geocerca usada como punto de control sin aviso | ❌ |
| 12 | «Velocidad por tipo» descolocado en legacy | ✅ 19-oct |
| 13 | Polígono se guarda con `radius = 100` | ✅ 19-oct |
| 14 | Mensajes poco claros / `TypeError` en `changeType` | ✅ 19-oct (atributo `polygon` traducido, guardia sin geometría) |
| 15 | Textos fijos sin `trans()` en Devices legacy | ✅ 19-oct (operador SIM, colores del recorrido, pestañas) |
| 16 | Aviso de vídeo en alta nueva | ✅ 19-oct |
| 17 | Primer icono de POI roto | ❌ Confirmado hoy también en el servidor de pruebas (2 iconos sin archivo) |
| 18 | Estado del vehículo no visible en lista legacy | ❌ |

## 6. Operaciones · `docs/guia-actualizacion-operaciones.md §7`

Módulo terminado y fusionado. Pendientes conocidos, no bloqueantes:
- `round` → `floor` en `StopDurationAlertCheck` (decisión pendiente, afecta a todas las alertas de parada).
- ~~Confirmar si el motivo autoriza o solo explica~~ ✅ decidido 19-oct: solo explica; casilla «Autorizar esta parada» al justificar.
- Validar conteo de vueltas contra el control manual del cliente.
- Sin pruebas automatizadas (decisión del proyecto).

## 7. Revisión QA del 19-oct · `docs/qa/revision-sistema-2026-10-19.md`

7 correcciones subidas (`e5410671`). Pendiente del lado del servidor: permisos del usuario de prueba (IMEI, SIM, borrar), socket 9002, recálculo de Seguridad del 4 al 19-oct. Mejoras UX sin asignar: ver sección 3 del informe.

## 8. Clasificación de vehículo por usuario · `docs/superpowers/plans/2026-10-19-clasificacion-vehiculo-por-usuario.md`

| Task | Estado | Evidencia |
| --- | --- | --- |
| 1 Migración `2026_10_20_000001` y entidad `DeviceVehicleClassification`; `Device::classifications/classificationFor/withClassificationFor` | ✅ | `5147c972`, `699f1fc4` |
| 2 `DeviceClassificationService` (validate/set/setState/clear) y `Geofence::speedLimitForDevice` | ✅ | `0971386d` |
| 3 Formularios legacy/v2/API y los dos «Cambiar estado» pasan por el service | ✅ | `76140c63`, `059bfe28` |
| 4 Mapa (transformer, incl. Compartir), sidebar legacy y ficha muestran la clasificación del usuario actuante | ✅ | `cc1aadf5` |
| 5 Seguridad: filtro «Tipo», tablas y Excel por usuario | ✅ | `c0ca0ed0` |
| 6 Alertas, historial (`AppendSpeedLimitGeofence*`) y reportes 904-906/RAG resuelven con el dueño de la geocerca | ✅ | `81d44ec5` |
| 7 Documentación (`CLAUDE.md`, guía §9, plan, este informe) | ✅ | commit de documentación |

Regla fijada: **el dueño de la geocerca resuelve** el límite; para mostrar se usa el usuario actual. Las columnas `devices.vehicle_type_id`/`vehicle_state_id` quedan sin leer ni escribir y se borrarán en una migración posterior (preparada aparte, no incluida). Pendiente: merge a `nuevo-tema` tras la validación en pruebas y resolver de paso el fallo legacy #5 y #18 de la sección 5, que este cambio deja cubiertos (el select solo ofrece tipos propios y el badge del sidebar lee la clasificación del usuario).

## Hecho el 19-oct (rama `correcciones-oct`)

- Merge de `seguridad` en `nuevo-tema` (`e3db698e`).
- Operaciones: el motivo solo explica y hay casilla «Autorizar esta parada».
- F8, fallos legacy 4, 8, 9, 10, 12, 13, 14, 15, 16, rejilla de horario accesible, código muerto de `fleet.js`.
- Página pública de Compartir con los controles, iconos y tema de la v2 (`Layouts/sharing.blade.php` + `sharing-public.css`).

Siguen abiertos: fallos legacy 5, 6, 7, 11, 17 (archivos de icono en el servidor) y 18; F18 (validadores de bloqueo); 49 campos sin etiqueta.

## Orden sugerido

1. Fusionar `seguridad` en `nuevo-tema` tras la aprobación en pruebas, y regenerar el PDF del manual.
2. Cerrar F8 (colores de icono) y los fallos legacy 4, 8, 9 y 10 (validación y GEXP): afectan a datos y a las dos interfaces.
3. Resto de fallos legacy (6, 7, 11 a 18): cosméticos o de mensajes.
4. Accesibilidad: rejilla de horario y campos sin etiqueta cuando haya hueco.
5. Decidir sobre `round`/`floor` en paradas y sobre el código muerto detectado.
