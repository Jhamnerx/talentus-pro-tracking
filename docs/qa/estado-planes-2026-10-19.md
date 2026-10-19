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
| F8 Colores del icono cuando el tipo no es «Flecha» | ❌ Pendiente | `device-modal.js:265-271` sigue poniendo `disabled` a los selects de color (el legacy solo los oculta), así que `icon_colors` no viaja con tipo `icon`/`rotating` |
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
- Rejilla del horario de alertas (`alerts.js:920-961`): 672 celdas sin estado expuesto. Hace falta `role="grid"` con navegación por flechas. Estimación 3-4 h.
- 49 campos de formulario sin etiqueta (18 en `sensor-form.blade.php`, 31 en otros 20 blades). Aplazado.
- Código muerto detectado: `fleet.js:899` (`[data-status]`) y `live.js:3311, 3344` (`.tp-live__paneltab`). Decidir si se borra.

## 5. QA legacy `/objects` · `docs/QA-LEGACY-OBJECTS-informe.md` (6-oct, 18 fallos)

| # | Fallo | Estado |
| --- | --- | --- |
| 1 | Corredor no se puede crear en legacy (`polyline`) | ✅ `geofences.js:86` |
| 2 | La v2 convertía el corredor en polígono | ✅ `live-zones.js` rama `line` |
| 3 | `suspended_at` nunca se guardaba | ✅ `DeviceModalHelper.php:557` (`forceFill`) |
| 4 | Vaciar «Velocidad por defecto» guarda 0 | ❌ `GeofenceService::normalize` no pone null; validador `numeric` sin `nullable` |
| 5 | Admin con tipos propios borra el tipo de vehículo ajeno | 🟡 El select incluye el tipo actual (`DeviceModalHelper.php:465`), pendiente confirmar que no se pierde al guardar |
| 6 | Editar forma con capa de geocercas oculta | ❌ Sin cambios |
| 7 | Al legacy le faltan campos nuevos (`cost_per_km`, `marker_*`) | ❌ Sin cambios |
| 8 | Columnas nuevas del device sin validar | ❌ `DeviceFormValidator` no tiene reglas para `cost_per_km`, `route_color_type`, `marker_*` |
| 9 | Límites por tipo aceptan cualquier cosa; tipo inexistente da 500 | ❌ Solo `nullable|numeric`, sin `exists` |
| 10 | Export GEXP sin límites de velocidad | ❌ `GeofencesExportController` sigue sin `speed_limit`/`vehicleSpeedLimits` |
| 11 | Borrar geocerca usada como punto de control sin aviso | ❌ |
| 12 | «Velocidad por tipo» descolocado en legacy | ❌ |
| 13 | Polígono se guarda con `radius = 100` | ❌ `toggleCorridorWidth` sin `prop('disabled')` |
| 14 | Mensajes poco claros / `TypeError` en `changeType` | ❌ |
| 15 | Textos fijos sin `trans()` en Devices legacy | ❌ |
| 16 | Aviso de vídeo en alta nueva | ❌ `advanced.blade.php:163` sin `!$item->exists` |
| 17 | Primer icono de POI roto | ❌ Confirmado hoy también en el servidor de pruebas (2 iconos sin archivo) |
| 18 | Estado del vehículo no visible en lista legacy | ❌ |

## 6. Operaciones · `docs/guia-actualizacion-operaciones.md §7`

Módulo terminado y fusionado. Pendientes conocidos, no bloqueantes:
- `round` → `floor` en `StopDurationAlertCheck` (decisión pendiente, afecta a todas las alertas de parada).
- Confirmar con el cliente si el motivo de parada autoriza o solo explica.
- Validar conteo de vueltas contra el control manual del cliente.
- Sin pruebas automatizadas (decisión del proyecto).

## 7. Revisión QA del 19-oct · `docs/qa/revision-sistema-2026-10-19.md`

7 correcciones subidas (`e5410671`). Pendiente del lado del servidor: permisos del usuario de prueba (IMEI, SIM, borrar), socket 9002, recálculo de Seguridad del 4 al 19-oct. Mejoras UX sin asignar: ver sección 3 del informe.

## Orden sugerido

1. Fusionar `seguridad` en `nuevo-tema` tras la aprobación en pruebas, y regenerar el PDF del manual.
2. Cerrar F8 (colores de icono) y los fallos legacy 4, 8, 9 y 10 (validación y GEXP): afectan a datos y a las dos interfaces.
3. Resto de fallos legacy (6, 7, 11 a 18): cosméticos o de mensajes.
4. Accesibilidad: rejilla de horario y campos sin etiqueta cuando haya hueco.
5. Decidir sobre `round`/`floor` en paradas y sobre el código muerto detectado.
