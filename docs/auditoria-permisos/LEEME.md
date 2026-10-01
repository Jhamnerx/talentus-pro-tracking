# Auditoría de permisos · UI v2 contra el legacy

Revisión del 1-oct-2026. Se comprueba que la v2 evalúe los mismos permisos
que el sistema clásico, en las dos direcciones: que no deje pasar lo que el
legacy bloquea, y que no esconda lo que el legacy permite.

## Resultado

**No se encontraron huecos de permisos.** Las 147 rutas de escritura de
`/ui/v2` se comportan como su equivalente legacy.

Se encontró **una** divergencia, ya corregida antes de esta auditoría: la
pestaña *Sensores* del modal de vehículo pedía `isAdmin()` cuando el legacy
no la gatea (commit `1f59fb1d`).

## Por qué no sirve leer el código

Los gates de la v2 viven en cuatro capas y un análisis estático da falsos
positivos sin parar:

```
controlador  ->  ayudante privado   ->  servicio        ->  Manager
                 $this->gate('edit')    TaskUserService      ->check('tasks', …)
                 $this->helper($d)      PoiUserService       ->check('poi', …)
                                        SensorModalHelper
```

La primera pasada estática marcó **43** métodos "sin comprobación". Tras
resolver los ayudantes privados bajaron a 18, y al ejecutarlos de verdad
quedaron en **cero**. Ejemplos de lo que la lectura no ve:

- `TasksController@store` no tiene gate propio: lo pone `TaskUserService`,
  **el mismo servicio que usa el controlador legacy**.
- `MapLayersController@storePoi` delega en `PoiUserService::create()`, que
  llama a `manager->check('poi', 'create')`.
- `CommandsController@storeSchedule` hereda el gate de más arriba en el
  controlador.

## Cómo se repite la comprobación

```bash
# 1. estática: métodos públicos de escritura sin gate aparente
python docs/auditoria-permisos/estatica.py

# 2. barrido: las 147 rutas de escritura con un usuario de pocos permisos
php artisan tinker --execute="require 'docs/auditoria-permisos/barrido.php';"

# 3. a fondo: payloads válidos para que la petición llegue al gate
php artisan tinker --execute="require 'docs/auditoria-permisos/a-fondo.php';"
```

Los dos scripts PHP envuelven todo en una transacción y la revierten, y
comparan los recuentos de tabla antes y después. El barrido con payload
vacío **no distingue** "no hay gate" de "no llegó al gate": para eso está el
tercero, que manda datos válidos y espera un 403.

### Leer los códigos

| código | significado |
| ------ | ----------- |
| 403 / 401 | el gate saltó — correcto |
| 2xx | pasó del todo — revisar |
| 422 | murió en validación — no concluyente, repetir con payload válido |
| 404 | el recurso no existe — no concluyente |

## Lo que sí conviene saber

Dos diferencias de comportamiento que **no** son de permisos, pero que se
notan al usar el sistema:

1. **Contraseña para borrar un vehículo.** Con `addon.object_delete_pass`
   activo, el legacy pide la contraseña *maestra* de `config/addon.php`; la
   v2 pide la contraseña *de login del propio administrador*
   (`DevicesController:1838`). Mismo gate (`isAdmin()`), secreto distinto.

2. **Grupos de dispositivo.** Un usuario normal puede crearlos en los dos
   sistemas: `devices_groups` resuelve por `DeviceGroupPolicy`, no por
   `perm()`, y la política solo bloquea cuentas demo. El legacy además exige
   elegir vehículos (falla con 422); la v2 no. Es diferencia de validación,
   no de permisos.

## Alcance

- Usuario de prueba: grupo 2, sin permiso en `poi`, `routes`, `tasks`,
  `drivers`, `device_expenses`, `devices_groups`, `services`, `checklist`,
  `custom_events`, `device_camera`, `users`, `forwards`, ni `edit` en
  `devices`, `send_command` ni `history`.
- Segundo usuario para comandos: sin `send_command` de ningún tipo.
- Los gates de menú de la v2 cubren los 18 del header legacy.
  `custom_device_add` no está en los parciales de menú pero sí en
  `DevicesController` (líneas 1417 y 1531).
- Los cuatro `isAdmin()` de `AlertsController` gatean **plantillas de
  alerta**, funcionalidad nueva de la v2 (migración `2026_08_23_000001`) sin
  equivalente legacy.
