# Revisión del módulo legacy `/objects` frente a los campos nuevos de la BD

> Informe del agente de navegador · 6 de octubre de 2026 · usuario `admin@talentustechnology.com`
> Encargo: comprobar altas, ediciones y borrados del legacy (geocercas, dispositivos, rutas, POIs)
> y que el legacy sea compatible con las columnas y tablas añadidas en 2026.
> Formato de la tabla: el de `docs/QA-UI-V2.md` §8, con una columna más para el arreglo.
> **No se ha tocado código.** Todos los registros de prueba llevan el prefijo `QA-`.

---

## 0 · Resumen

- **POIs, rutas, puntos de control y programaciones de ruta**: alta, edición y borrado funcionan en
  el legacy y lo guardado se lee igual en la v2. Sin fallos.
- **Geocercas**: polígono y círculo funcionan, con sus límites por tipo y estado de vehículo. El
  **corredor no se puede crear desde el legacy** (fallo 1) y la **v2 lo convierte en polígono al
  editarlo** (fallo 2).
- **Dispositivos**: el formulario legacy guarda bien tipo y estado de vehículo, operador SIM,
  canales de vídeo y colores del recorrido. **No borra** los campos que solo edita la v2
  (`cost_per_km` y los cinco `marker_*`): se comprobó guardando desde el legacy y releyendo en la v2.
  Lo que falla es que **`suspended_at` nunca se guarda** (fallo 3) y que al legacy le faltan
  campos (fallo 7).
- La validación de casi todas las columnas nuevas es floja o inexistente (fallos 8 y 9). Afecta
  a las dos interfaces porque comparten `DeviceModalHelper` y `GeofenceService`.

### Aviso antes de seguir probando en esta copia

`TALENTUS_URL` está definido en el `.env` local. `DeviceModalHelper::create()` y `edit()` llaman a
`TalentusDeviceSyncService::pushDevice()`, que envía un webhook a Talentus ERP **cada vez que se
guarda un dispositivo con placa**. Guardar un vehículo real desde esta copia de desarrollo empuja
su `id` local al ERP. Por eso el dispositivo de prueba se creó **sin placa** y no se guardó ningún
dispositivo existente. Conviene vaciar `TALENTUS_URL` en local.

---

## 1 · Fallos

| # | Gravedad | Página | Qué falla | Cómo reproducirlo | Esperado | Obtenido | Arreglo propuesto |
| - | -------- | ------ | --------- | ----------------- | -------- | -------- | ----------------- |
| 1 | **Alto** | `/objects` → Geocercas → `+` | **No se puede crear una geocerca de tipo «Corredor (línea)».** La línea dibujada se descarta. | 1. Geocercas → `+`. 2. Tipo = «Corredor (línea)». 3. Clic en 3 puntos del mapa y clic en el último para terminar. 4. Guardar. | La línea queda en el mapa con sus vértices y se guarda con `type=line`. | Al terminar, la línea desaparece. Guardar envía `polygon=[]` y responde `POST /geofences` **422** «El polygon campo es requerido cuando Tipo es line.» | `resources/assets/js/controller/geofences.js:84`: el manejador de `L.Draw.Event.CREATED` solo acepta `"polygon"` y `"circle"`; Leaflet.draw emite `"polyline"`. Cambiar a `if (type === "polygon" \|\| type === "circle" \|\| type === "polyline")`. `store()` y `update()` ya tratan `L.Polyline`. Recompilar con gulp (`public/assets/js/app.js`). |
| 2 | **Alto** | `/ui/v2/live?panel=zones` (rompe datos creados en legacy) | **Editar un corredor en la v2 lo convierte en polígono.** | 1. Tener un corredor (`type=line`, 3 puntos, radio 300). 2. v2 → Geocercas → lápiz → Guardar sin cambiar nada. 3. `GET /ui/v2/api/zones`. | Sigue siendo `type=line`. | Queda `type=polygon` con los 3 puntos del eje: deja de ser corredor. Además la lista lo rotula «Polígono · 3 pts» y el filtro solo tiene Polígonos y Círculos. | `resources/assets/ui-v2/js/live-zones.js:541-544`: el `else` fuerza `payload.type = "polygon"`. Añadir la rama `else if (g.type === "line") { payload.type = "line"; payload.polygon = normCoords(g.coordinates); if (payload.radius == null) payload.radius = g.radius; }`. Rotular «Corredor» en las líneas 186-190 y 272, y añadir el filtro en `resources/views/ui/v2/pages/live.blade.php:341`. Recompilar y **subir el `?v=`** del script. |
| 3 | **Alto** | Editar dispositivo (legacy y v2) | **La columna `devices.suspended_at` nunca se escribe.** | 1. Editar el dispositivo y desmarcar «Activo» → Guardar. 2. Volver a marcarlo → Guardar. 3. Pestaña Historial. | La reactivación dice «Estuvo suspendida desde dd/mm/aaaa» y `suspended_at` queda con la fecha mientras está suspendida. | Se crean los dos registros de Historial, pero el motivo es solo «Unidad reactivada.»: `suspended_at` seguía en `NULL`. | `ModalHelpers/DeviceModalHelper.php:542` y `:562` usan `$item->updateQuietly(['suspended_at' => …])` y la columna **no está en `Device::$fillable`**, así que Eloquent la descarta en silencio. Usar `$item->forceFill(['suspended_at' => now()])->saveQuietly();` (y `null` en la reactivación), o añadir `'suspended_at'` a `$fillable` en `Tobuli/Entities/Device.php`. |
| 4 | **Alto** | Editar geocerca (legacy) | **Vaciar «Velocidad por defecto» guarda `0` en lugar de quitar el límite.** | 1. Geocerca con límite 60. 2. Editar, borrar el valor, Guardar. 3. `GET /ui/v2/api/zones`. | `speed_limit = null` (sin límite). | `speed_limit = 0`. La v2 lo trata como un límite real de 0 km/h (`live-zones.js`, `limitFor`). En la v2, por código, el límite no se puede quitar: si el campo va vacío no se envía (`live-zones.js:466-467`). | `Tobuli/Services/GeofenceService.php:105-107`: `if ($data['speed_limit'] === '' \|\| $data['speed_limit'] === null) { $data['speed_limit'] = null; } else { …reverse() }`. En la v2 enviar `payload.speed_limit = sl === "" ? null : parseFloat(sl)`. En `GeofenceFormValidator` usar `nullable\|numeric\|min:0`. |
| 5 | **Alto** (por código, sin reproducir) | Editar dispositivo (legacy y v2) | **Un administrador puede borrar sin querer el tipo y el estado de vehículo de un dispositivo de otro usuario.** | 1. El cliente A crea un tipo de vehículo y lo asigna a su dispositivo. 2. Un administrador **que tenga tipos propios** abre ese dispositivo y pulsa Guardar. | El tipo y el estado se conservan. | El desplegable solo carga los tipos del usuario que edita (`VehicleType::where('user_id', $this->user->id)`), el tipo ajeno no aparece, se envía `vehicle_type_id=''` y queda en `NULL`. No lo reproduje: habría que guardar un dispositivo real de un cliente, y eso dispara el webhook a Talentus ERP. | `ModalHelpers/DeviceModalHelper.php:243` y `:458`: cargar los tipos de los dueños del dispositivo (o añadir siempre el tipo actual del dispositivo). Alternativa: no pintar los `select` si el valor actual no está en la lista (`Tobuli/Views/Frontend/Devices/partials/advanced.blade.php:22-44` y `resources/views/ui/v2/modals/devices/partials/informacion.blade.php:70-99`). |
| 6 | **Medio** | `/objects` → Geocercas → ⋮ → Editar | **Con la capa «geocercas» oculta en el mapa no se puede editar la forma; con un círculo ni siquiera abre el formulario.** | 1. Tener desactivado «mostrar geocercas» (`app.settings.showGeofences = 0`, como está la cuenta admin). 2. ⋮ → Editar sobre un círculo. | Se abre el formulario y la forma aparece editable. | Círculo: no pasa nada. Consola: `TypeError: Cannot read properties of undefined (reading 'layerPointToLatLng')` en `Geofences._this.edit` (`app.js:10743`). Polígono y corredor: abre el formulario pero la forma no se pinta y no hay vértices que arrastrar. | `resources/assets/js/controller/geofences.js:433-449`: antes de `fitBounds`, `if (!_this.map().hasLayer(tmpItem.getLayer())) _this.map().addLayer(tmpItem.getLayer());` y retirar la capa al cancelar si la capa general está oculta. |
| 7 | **Medio** | Formularios legacy | **Al legacy le faltan campos de columnas nuevas.** No los borra, pero no se pueden ver ni editar. | Abrir Editar dispositivo y Configuración → Tipos de vehículo → Editar. | Mismos campos que la v2. | Faltan: `cost_per_km`; `marker_colors_enabled`, `marker_speed_ranges`, `marker_geofence_margin`, `marker_geofence_near_color`, `marker_geofence_over_color`; `route_sensor_id` (el radio «Por sensor» está fijo en `disabled`); `vehicle_states.label` y `color`. `days_history` no tiene formulario **en ninguna** de las dos interfaces aunque lo usa `CleanDevicesCommand`. | Dispositivo: añadir `cost_per_km` tras la fila de combustible en `Devices/partials/advanced.blade.php` (~línea 299) y el bloque de color del marcador portado de `resources/views/ui/v2/modals/devices/partials/cola.blade.php:130-189`. Tipos de vehículo: `VehicleTypes/edit.blade.php` + `VehicleTypesController::syncStates()` (hoy solo actualiza `name`; copiar `stateFields()` de `Ui/V2/VehicleTypesController.php:161-176`). Decidir dónde se edita `days_history`. |
| 8 | **Medio** | Guardar dispositivo (legacy y v2) | **Las columnas nuevas no se validan.** | `PUT /devices/update` con `id`, `sim_number` y uno de estos valores (probado sobre el dispositivo QA): `cost_per_km=abc`; `cost_per_km=-5`; `route_color_type=xyz` con `route_color=notacolor`; `marker_geofence_margin=99999`; `operator_sim` con un texto largo que no está en la lista; `vehicle_type_id=''` con `vehicle_state_id=3`; `vehicle_type_id=2` con un estado de otro tipo. | 422 con el campo señalado. | Todo responde 200. Queda: costo `-5.0000`; tipo de color inválido (ningún radio marcado al reabrir); color `notacolor`; margen recortado a `32767`; operador truncado a 20 caracteres que el desplegable no muestra y **se vacía en el siguiente guardado**; estado sin tipo; estado de un tipo distinto. Solo `video_channels` valida (0–6). | `Tobuli/Validation/DeviceFormValidator.php`, en `create` y `update`: `'cost_per_km' => 'nullable\|numeric\|min:0'`, `'route_color_type' => 'nullable\|in:trips,single,speed,sensor'`, `'route_color' => 'nullable\|css_color'`, `'route_speed_ranges' => 'nullable\|json'`, `'marker_colors_enabled' => 'boolean'`, `'marker_speed_ranges' => 'nullable\|json'`, `'marker_geofence_margin' => 'nullable\|integer\|between:0,200'`, `'marker_geofence_near_color' => 'nullable\|css_color'`, `'marker_geofence_over_color' => 'nullable\|css_color'`, `'operator_sim' => 'nullable\|in:M2M,CUY,IOT,MOVISTAR,CLARO,ENTEL,BITEL'`. En `DeviceModalHelper::edit()` y `create()`: comprobar que el estado pertenece al tipo (la comprobación ya existe en `changeVehicleState()`, líneas 658-664) y poner el estado a `null` si el tipo va vacío. Sacar la lista de operadores a `config`, hoy repetida en dos vistas. |
| 9 | **Medio** | Guardar geocerca (legacy y v2) | **Los límites por tipo de vehículo aceptan cualquier cosa y un tipo inexistente da error 500 con guardado a medias.** | `PUT /geofences/update` sobre la geocerca QA con: (a) `speed_limit=-5`, `vehicle_speed_limits[2][2]=-20`, `vehicle_speed_limits[2][3]=abc`; (b) `vehicle_speed_limits[99999][0]=33`. | 422 en ambos casos. | (a) 200: guarda `-5`, `-20` y `abc` como `0`. (b) **500** con el mensaje SQL completo (`SQLSTATE[23000] … gpswox_web.geofence_vehicle_speed_limits`); la geocerca ya quedó actualizada y los límites anteriores borrados. El caso (b) ocurre de verdad si se borra un tipo con el formulario de geocerca abierto en otra pestaña. | `Tobuli/Services/GeofenceService.php`: `normalize()` convierte antes de validar, por eso `abc` pasa como `0`: validar `vehicle_speed_limits.*.*` como `nullable\|numeric\|min:0` sobre el dato crudo, o dejar el valor intacto si no es numérico. En `syncVehicleSpeedLimits()` filtrar por los tipos y estados del usuario y envolver `update()` + sync en una transacción. |
| 10 | **Medio** | Geocercas → Exportar (GEXP) | **La exportación no incluye límites de velocidad.** | `POST /geofences/export_create` con `export_type=export_single` y dos geocercas con límite. | El `.gexp` lleva `speed_limit`, los límites por tipo/estado y `additional`. | Claves por geocerca: `id, group_id, name, coordinates, polygon_color, type, radius, center, device_id`. Exportar e importar pierde todos los límites. Ya figuraba como pendiente en `CLAUDE.md`. | `app/Http/Controllers/Frontend/GeofencesExportController.php:24-34`: añadir `speed_limit` y `additional` a `$attributes`, serializar `vehicleSpeedLimits` (por **nombre** de tipo y estado, no por id) y leerlo en `Tobuli/Importers/Geofence`. |
| 11 | **Bajo** | Borrar geocerca | Borrar una geocerca que es punto de control de una ruta la quita de la ruta sin avisar. | Geocerca usada en «Puntos de control» → ⋮ → Eliminar → Confirmar. | Aviso de que la usan N rutas. | Se borra y el punto de control desaparece por `ON DELETE CASCADE`. | Avisar en `Objects/partials/deleteGeofence.blade.php` cuando `RouteCheckpoint::where('geofence_id', …)->exists()`. |
| 12 | **Bajo** | Geocercas → `+` / Editar | **«Velocidad por tipo de vehículo» sale descolocado**: el nombre del tipo aparece a la derecha de los estados («VACIO: CARGADO: PESADOS»), y con dos tipos se mezclan. | Tener un tipo con estados y abrir el formulario. | Nombre del tipo como título y debajo sus estados. | Primera fila: «VACIO:» «CARGADO:» «PESADOS». Segunda fila: «QA-E1 ren:» «QA-Tipo Legacy ed» «QA-E2:». No se distingue qué estado es de qué tipo. | `Tobuli/Views/Frontend/Geofences/partials/vehicle_speed_limits.blade.php:30-45`: el `<label>` del tipo es `inline` y flota junto a los `col-sm-4`. Ponerle `style="display:block"` o envolver los estados en un `<div class="row">`. |
| 13 | **Bajo** | Geocercas → `+` | Un polígono se guarda con `radius = 100`. | Crear un polígono y mirar la respuesta de `POST /geofences`. | `radius` nulo en polígonos. | `"radius":100`: el campo oculto «Distancia al eje» se serializa siempre. | `Geofences/create.blade.php:21-25` y `edit.blade.php:16-20`: `prop('disabled', !esCorredor)` dentro de `toggleCorridorWidth()`. |
| 14 | **Bajo** | Geocercas → `+` | Mensajes poco claros. | Guardar sin cerrar la figura, o elegir Círculo o Corredor. | «Dibuje la geocerca en el mapa». | «El polygon campo es requerido cuando Tipo es polygon.» El aviso superior dice siempre «Por favor dibuje un polígono». Al cambiar a Círculo antes de dibujar, la consola da `TypeError: Cannot read properties of null (reading 'lng')` en `changeType` (`app.js:10955`). | Añadir `polygon` a `validation.attributes`; cambiar el aviso según el tipo; en `changeType` no añadir la capa si aún no hay geometría. |
| 15 | **Bajo** | Dispositivo (legacy) | Textos fijos en castellano que no pasan por `trans()`. | Visto en las plantillas (no cambié el idioma de la cuenta): los textos están escritos tal cual en el Blade. Lo de Configuración sí lo vi en pantalla. | Traducidos. | «Operador SIM:», «-- Sin operador --», «Colores del recorrido — Historial:», «Por viajes», «Único», «Por velocidad», «Por sensor», y las pestañas «Servicios Web» e «Historial». En Configuración → Principal, al revés: «Unit of distance / capacity / altitude» en inglés con la cuenta en castellano. | `Devices/partials/advanced.blade.php:73-88` y `:359-399`, `Devices/edit.blade.php` y `create.blade.php` (pestañas), `Devices/partials/maintenances.blade.php`. |
| 16 | **Bajo** | Agregar dispositivo | En un alta nueva sale «El protocolo de este equipo no lleva vídeo…» bajo «Canales de vídeo a bordo». | Vehículos → `+` → Dispositivos → Información. | Sin aviso hasta conocer el protocolo. | El aviso sale siempre en el alta. | `Devices/partials/advanced.blade.php:163`: `@unless (!$item->exists \|\| $videoService->isSupported($item))`. |
| 17 | **Bajo** | POI → `+` | El primer icono de la lista sale roto. | POI → `+`. | Imagen del icono. | `/images/map_icons/3b78b0ebc9ff00d8f3070f2c7d4815461a079e88.png` no carga (texto «ICON»). | Falta el fichero en la copia o sobra la fila en `map_icons`. |
| 18 | **Bajo** | Lista de vehículos (legacy) | El estado del vehículo no se ve en ningún sitio y, al guardar «Cambiar estado del vehículo», no vi aviso de confirmación. | ⋮ → Cambiar estado del vehículo → Guardar. | Aviso de guardado y el estado (etiqueta y color) junto al vehículo, como en la v2. | Guarda (`POST /devices/change_vehicle_state` 200) y cierra sin más. | Mostrar `label` y `color` del estado en `Objects/item.blade.php` y lanzar el aviso en el callback del modal. |

---

## 2 · Compatibilidad del legacy con cada columna o tabla nueva

| Columna / tabla | ¿Formulario legacy? | ¿Guarda? | ¿Se conserva al guardar desde legacy? | Nota |
| --- | --- | --- | --- | --- |
| `devices.vehicle_type_id`, `vehicle_state_id` | Sí | Sí | Sí | Sin comprobar que el estado sea del tipo (fallo 8); riesgo con tipos de otro usuario (fallo 5) |
| `devices.operator_sim` | Sí | Sí | Sí, si el valor está en la lista | Fallo 8 |
| `devices.video_channels` | Sí | Sí | Sí | Valida 0–6 |
| `devices.route_color_type`, `route_color`, `route_speed_ranges` | Sí | Sí | Sí | Al guardar, un `route_color` nulo pasa a `#2563eb` y los rangos nulos a los de fábrica; inofensivo |
| `devices.route_sensor_id` | No («Por sensor» deshabilitado) | — | Sí | Tampoco es usable en la v2 todavía |
| `devices.cost_per_km` | **No** | — | **Sí** (comprobado) | Fallo 7 |
| `devices.marker_*` (5 columnas) | **No** | — | **Sí** (comprobado) | Fallo 7 |
| `devices.suspended_at` | Automático | **No se guarda** | — | Fallo 3 |
| `devices.days_history` | No (tampoco en v2) | — | Sí | Fallo 7 |
| `devices.sidersales`, `epscenter`, `wisetrack` | Sí (pestaña Servicios Web) | No probado | — | No los activé para no disparar integraciones |
| `device_maintenances` | Sí (pestaña Historial) | Sí | — | Suspensión y reactivación automáticas y borrado correctos; el alta manual no la probé |
| `geofence_vehicle_speed_limits` | Sí | Sí | Sí | Fallos 4, 9, 10 y 12 |
| Geocerca `type = line` (corredor) | Sí | **No se puede crear**; editar sí | Sí | Fallos 1 y 2 |
| `vehicle_types`, `vehicle_states` | Sí (Configuración → Tipos de vehículo) | Sí | Sí | Alta, renombrar, añadir estados y borrar el tipo, correctos |
| `vehicle_states.label`, `color` | **No** | — | **Sí** (comprobado) | Fallo 7 |
| `route_checkpoints` | Sí | Sí | Sí | Editar la ruta no los toca |
| `route_schedules`, `device_route_schedule` | Sí (Herramientas → Programaciones de ruta) | Sí | Sí | Se leen igual en la v2 |
| `routes`, `user_map_icons` (POI) | Sin columnas nuevas | Sí | — | CRUD completo correcto |

---

## 3 · Qué probé y salió bien

- **POI**: alta con clic en el mapa e icono, edición de nombre y descripción, borrado con su
  confirmación (`/pois`, `/pois/update/15`, `/pois/destroy/15`).
- **Ruta**: alta dibujando 3 puntos, edición de nombre y color (los vértices salen editables,
  no los moví), borrado. Al borrarla desaparecen su programación y sus puntos de control.
- **Puntos de control y programación de ruta** creados desde el legacy
  (`PUT /route_checkpoints/13`, `POST /route_schedules`): ida y vuelta, tolerancias, días,
  horas y vehículo. El formulario de edición los devuelve igual y la v2 los lee igual.
- **Geocerca polígono y círculo**: alta dibujando, con límite por defecto y por estado. Edición
  del polígono y del corredor desde el formulario legacy (el círculo no abre, fallo 6). Borrado
  del círculo con su confirmación. La v2 muestra los mismos límites en su modal.
- **Tipos de vehículo**: alta, estados, renombrado; borrar el tipo quita sus límites en las
  geocercas y deja el estado del dispositivo en nulo.
- **Dispositivo**: alta desde el modal legacy con los campos nuevos; lectura correcta en el
  formulario v2; guardar desde legacy no altera ninguno de los campos que expone el formulario v2
  (comparados antes y después, cero diferencias); «Cambiar estado del vehículo»; registros
  automáticos de Historial.

## 4 · Qué no pude probar

- **Borrar un dispositivo.** El legacy pide la clave fija `addon.object_delete_pass` del `.env` y
  la v2 pide la contraseña de acceso del usuario. No tengo la primera y no he usado la segunda.
- **Dispositivo con placa y casillas de Servicios Web** (Sutran, Osinergmin, Mininter, Consatel,
  Wisetrack, Sidersales, EPS Center): dispararían el webhook a Talentus ERP o las integraciones.
- **Guardar un dispositivo real existente**, por el mismo motivo. El fallo 5 queda sin reproducir.
- **Importar** geocercas, rutas y POIs (hace falta subir un fichero).
- **Geocerca móvil** e **intervalos** de geocerca (`addon.reports_geofleet_in` apagado).
- **Sensores, servicios, cámaras, gastos, balizas** dentro del modal de dispositivo.
- **Usuario normal** (`jhamnerx1x@gmail.com`): todo se probó como administrador.
- **Envío de comandos**: fuera de alcance por las reglas de seguridad.

## 5 · Limpieza

Borrados: POI 15, ruta 13 con su programación 9 y sus puntos de control, geocerca 1379,
tipo de vehículo 5 con sus dos estados, registros de Historial 116 y 117.

**Quedan tres registros de prueba:**

| Registro | Id | Por qué sigue ahí |
| --- | --- | --- |
| Dispositivo `QA-Legacy Device` (IMEI 990000862471854, sin placa) | 1695 | Borrarlo exige la clave de borrado. Tiene valores absurdos a propósito (costo −5, margen 32767) |
| Geocerca `QA-Legacy Poligono ed` | 1377 | La herramienta de navegador quedó bloqueada antes de borrarla |
| Geocerca `QA-Legacy Corredor ed` (quedó como polígono por el fallo 2) | 1378 | Igual |

La cuenta admin puede haber quedado en la interfaz **legacy**: entré por «Versión anterior» y
antes `/objects` abría la v2.
