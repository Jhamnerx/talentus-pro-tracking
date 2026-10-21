# Plan — Normas de comando (consulta → respuesta → acción)

Fecha: 21-oct-2026. Rama `normas-comando` (desde `nuevo-tema`); se lleva a `nuevo-tema` y a `master`.

## 1. Qué reemplaza

Hoy el caso DOUT1 (buzzer FTC921) está hardcodeado en dos piezas:
`app/Console/Commands/SendGetioProbe.php` (manda `getio` cada 5 min a una lista fija de IMEIs) y
`app/Listeners/AutoDisableDoutListener.php` (si la respuesta trae `DO1:1`, manda `setdigout 0` y
escribe en `storage/logs/dout_auto_off.log`). Cambiar vehículo, patrón o comando exige desplegar.

## 2. Decisiones (respuestas del 21-oct-2026)

| Tema | Decisión |
| --- | --- |
| Quién gestiona | Solo administradores, desde el panel admin (`auth.admin`). |
| Evaluación de la respuesta | Tres tipos: **contiene**, **regex** y **comparar valor** (`AIN1 > 90`). |
| Ramas | Rama nueva; merge a `nuevo-tema` y cherry-pick a `master` (igual que UNIGIS). |
| Usuario que envía | El administrador dueño de la norma (no "el último que mandó un comando"). |
| Respuestas evaluadas | Por defecto solo las que llegan dentro de la ventana tras la consulta (marcador en caché); opción "evaluar cualquier respuesta" por norma. |
| Enfriamiento | Por norma: tras ejecutar una acción sobre un vehículo no se repite hasta pasados N minutos (0 = sin enfriamiento). |
| Bitácora | Tabla `command_rule_logs` visible desde el admin; se depuran entradas de más de 30 días. |

## 3. Modelo de datos

| Tabla | Columnas |
| --- | --- |
| `command_rules` | id, user_id, name, description, active, query_command, interval_minutes, only_connected, evaluate_any_response, cooldown_minutes, last_run_at, timestamps |
| `command_rule_devices` | command_rule_id, device_id (unique) |
| `command_rule_conditions` | id, command_rule_id, position, match_type (contains/regex/compare), pattern, field, operator (=,!=,>,>=,<,<=), value, action_command, stop_on_match, timestamps |
| `command_rule_logs` | id, command_rule_id, command_rule_condition_id, device_id, phase, message, created_at |

Fases de la bitácora: `probe_sent`, `probe_failed`, `probe_skipped`, `response`, `matched`,
`action_sent`, `action_failed`.

## 4. Motor (`Tobuli/Services/CommandRules/`)

- `ResponseParser::values($texto)` — extrae pares `CLAVE:valor` / `CLAVE=valor` numéricos de la respuesta.
- `ConditionEvaluator::matches($condicion, $texto)` — contiene (insensible a mayúsculas), regex, o comparación numérica sobre el campo parseado.
- `CommandRuleRunner::runDue()` — normas activas vencidas (`last_run_at + interval`), envía la consulta a cada vehículo (opcionalmente solo conectados) con `SendCommandService::gprs`, marca `probe` en caché (TTL = 2×intervalo, mín. 10 min), registra en bitácora.
- `CommandRuleResponder::handle($device, $resultado)` — para cada norma activa del vehículo: si hay marcador (o evaluar-cualquiera), evalúa condiciones en orden; en coincidencia respeta enfriamiento, envía la acción, registra y para si `stop_on_match`.
- `CommandRuleService` — guardar (validación `AdminCommandRuleValidator`, condiciones, vehículos vía `DevicesGroupLoader`), borrar, ejecutar ahora.

Entrada: comando `command-rules:run` cada minuto (Kernel) y listener `CommandRuleResponseListener` sobre `PositionResultRetrieved` (donde hoy está `AutoDisableDoutListener`).

## 5. Admin

`Admin/CommandRulesController` + rutas `admin.command_rules.*` (index, create, store, edit, update,
destroy, devices, logs, run). Vistas `Tobuli/Views/Admin/CommandRules/` (index, table, create, edit,
form, logs). Entrada en `NavigationHelper` (bloque de herramientas admin) y claves en `lang/{es,en}/admin.php`.

## 6. Migración del caso actual

Migración de datos que crea la norma "DOUT1 buzzer FTC921" (vehículo IMEI 860693084599456, `getio`,
5 min, condición contiene `DO1:1` → `setdigout 0`, dueño = último admin que envió comando a ese equipo
o el primer admin) **solo si el dispositivo existe**. Se eliminan `SendGetioProbe`, su línea en el
Kernel y `AutoDisableDoutListener` (y su registro en `EventServiceProvider`).

## 7. Verificación manual

- `php artisan migrate`; crear norma desde el admin con device 219 (único con datos en dev); ver bitácora.
- `php artisan command-rules:run --rule=<id> --force` y revisar `command_rule_logs`.
- Simular respuesta: `event(new PositionResultRetrieved($device, 'DI1:1 AIN1:91 DO1:1'))` y comprobar coincidencia, enfriamiento y acción.
