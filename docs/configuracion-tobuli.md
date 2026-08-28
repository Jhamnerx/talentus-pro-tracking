# Configuración del sistema — `config/tobuli.php` y la tabla `configs`

Documento de referencia sobre **dónde vive cada ajuste**, **quién gana cuando hay conflicto** y
**qué hay que reiniciar** para que un cambio surta efecto.

---

## 1. Respuesta corta

`config/tobuli.php` **no se vuelca a la base de datos**. Ni `php artisan migrate` ni `php artisan db:seed`
copian su contenido. Es un archivo de configuración normal de Laravel que actúa como **capa de
fallback** por debajo de la tabla `configs`.

Sí es editable, pero **editar el archivo no siempre cambia el comportamiento**: depende de si esa
clave existe también en la tabla `configs`. Ver §3.

Y sí, tras instalar quedan filas en `configs` que luego puedes editar — pero llegan ahí por otro
camino, no copiando el archivo. El detalle está en §4.

---

## 2. Las tres capas

El sistema resuelve un ajuste bajando por una cadena de tres niveles. Cada nivel tiene un padre al
que delega cuando no conoce la clave.

```
SettingsModel   (por usuario)    users.settings          columna JSON
      ↓ padre
SettingsDB      (global)         tabla configs           value = PHP serialize()
      ↓ padre
SettingsConfig  (archivo)        config/tobuli.php       + .env
```

| Clase | Origen | Se lee con | Escritura |
| ----- | ------ | ---------- | --------- |
| `SettingsConfig` | `config/tobuli.php` | `config('tobuli.X')` | `_set()` es **no-op**: nadie escribe el archivo desde la app |
| `SettingsDB` | tabla `configs` | `settings('X.Y')` | `settings('X.Y', $valor)` |
| `SettingsModel` | `users.settings` (trait `Settingable`) | `$user->getSettings('X')` | `$user->setSettings('X', $v)` |

El cableado está en [`app/Providers/SettingsServiceProvider.php`](../app/Providers/SettingsServiceProvider.php):

```php
$settings = new SettingsDB();
$settings->setParent(new SettingsConfig());
```

Y el helper `settings()` ([`Tobuli/Helpers/Settings/helper.php`](../Tobuli/Helpers/Settings/helper.php))
siempre resuelve contra `SettingsDB`, o sea contra la cadena completa.

### La regla que importa

- Una clave leída con **`config('tobuli.X')`** ignora la BD por completo. Solo la cambias editando el
  archivo (o el `.env` si el valor viene de `env()`).
- Una clave leída con **`settings('X.Y')`** usa BD primero y archivo como relleno.

Si editas el archivo para una clave que ya existe en `configs`, **no pasa nada**: la BD gana.

---

## 3. Cómo se mezclan archivo y BD

En `Settings::withParent()`:

```php
if (!$this->_has($key))                       // la BD no conoce el grupo
    return $this->parent->get($key);          // → todo sale del archivo

$value        = $this->_get($key);            // valor de BD
$parent_value = $this->parent->get($key);     // valor del archivo

return array_merge_recursive_distinct($parent_value, $value);   // BD pisa al archivo
```

`array_merge_recursive_distinct()` ([`Tobuli/Helpers/Helper.php:811`](../Tobuli/Helpers/Helper.php#L811))
recorre en profundidad **solo arrays asociativos**.

**Gotcha crítico:** si el valor es una **lista** (índices numéricos), la BD la **reemplaza entera**,
no la fusiona. Un `widgets.list` o un `password.includes` guardado en BD borra los del archivo.
Con arrays asociativos, en cambio, el archivo rellena las claves ausentes.

### Ejemplo real de esta instalación

La tabla `configs` tiene una fila `protocols` con **un solo protocolo**:

```json
{"h02":{"mergeable":true,"bypass_invalid":"1"}}
```

Pero `settings('protocols')` devuelve los doce del archivo, con `h02` ya sobreescrito:

```
gt02, gt06, gt062, gps103, h02{mergeable,bypass_invalid}, eelink, xirgo,
tk103, gl200, wialon, aquila, dualcam
```

Eso es exactamente el merge recursivo trabajando: el `h02` de la BD gana, el resto lo pone el archivo.

---

## 4. Qué crea realmente `migrate` / `seed`

Tras una instalación nueva **sí** quedan filas en `configs`, y son editables desde el panel. Pero
**ninguna sale de copiar `config/tobuli.php`**: llegan por tres caminos distintos.

### 4.1 La migración de la tabla — 3 filas hardcodeadas

[`2014_09_12_140638_create_configs_table.php`](../database/migrations/2014_09_12_140638_create_configs_table.php)
crea la tabla e inserta tres filas con valores escritos **en la propia migración**, sin leer
`config/tobuli.php`:

- `alerts_last_check` = `0`
- `email` = `a:0:{}`
- `main_settings` = 12 claves (`server_name`, `default_language`, `default_timezone`, formatos,
  unidades, `default_map`, `default_object_online_timeout`, `allow_users_registration`,
  `frontpage_logo_padding_top`) **+** `user_permissions`, este sí construido desde
  `config('permissions.list')`.

Esos 12 valores **difieren** de los del archivo. La migración pone `default_language = 'en'` mientras
el archivo tiene `'es'`. Como la BD gana, tras un `migrate` limpio el idioma efectivo es `en` hasta
que alguien lo cambie en el panel. (En esta instalación ya está en `es`.)

### 4.2 Las migraciones de datos — materializan grupos al escribir

Este es el camino que explica la mayoría de las filas. Hay **16 migraciones** que leen o escriben
ajustes, y `SettingsDB::_set()` **crea la fila si el grupo no existe**:

```php
$item = DB::table('configs')->where('title', '=', $group)->first();

if (empty($item))
    DB::table('configs')->insert(['title' => $group, 'value' => '']);
```

O sea: cualquier migración que escriba un grupo lo materializa en la tabla, aunque solo toque una
subclave.

| Migración | Grupo que escribe |
| --------- | ----------------- |
| `2018_11_23_..._alter_backups_type` | `backups` |
| `2018_11_28_..._alter_backups_empty_to_auto` | `backups` |
| `2019_04_24_..._payments_gateway_config` | `payments` |
| `2019_05_17_..._stripe_public_key` | `payments`, `main_settings` |
| `2019_05_27_..._google_maps_key` | `main_settings` |
| `2019_05_29_..._google_streetview_key` | `main_settings` |
| `2022_05_23_..._transform_geocoder_settings` | `main_settings.geocoders` |
| `2023_03_09_..._send_command_edit_permission` | `main_settings.user_permissions` |
| `2023_04_07_..._remove_daylight_saving_mexico` | `main_settings` |
| `2023_05_18_..._user_users_permission` | `main_settings.user_permissions` |
| `2024_02_27_..._migrate_report_plugins_settings` | `reports`, `plugins` |
| `2024_07_02_..._set_sms_gateway_edit_permissions` | `main_settings.user_permissions` |

**Ejemplo que siempre dispara:** `alter_backups_type` termina en `settings('backups.type', 'custom')`
salvo que el FTP configurado sea una IP concreta. En una instalación nueva no lo es, así que la fila
`backups` **aparece siempre**.

**Contraejemplo que casi nunca dispara:** `migrate_report_plugins_settings` instancia
`new SettingsDB()` **sin padre**, o sea leyendo solo la BD, ignorando el archivo. Si no hay fila
`plugins`, todas sus lecturas dan `null` y hace `continue`: la fila `reports` **no se crea**. Solo
aparece si `plugins` ya existía antes.

### 4.3 El panel admin y el instalador externo

`db:seed` no participa: [`DatabaseSeeder`](../database/seeders/DatabaseSeeder.php) solo llama a
`DeviceIconsTableSeeder` y `DeviceExpensesTypesSeeder`, y **no toca `configs`**.

Grupos como `plugins`, `sms_gateway`, `db_clear`, `languages` o `protocols` los crea la aplicación la
primera vez que guardas esa pantalla del panel (§5.1 dice cuál es cada una).

Y tres filas **no las escribe ningún código de este repositorio** — un grep completo no devuelve
nada:

- `jar_version`
- `last_ports_modification`
- `last_config_modification`

Vienen del instalador/actualizador de GPS Server, fuera de Laravel. La pista de que el flujo real de
instalación parte de un **dump SQL** y no de `migrate` desde cero está en la propia migración de
`configs`, que se declara a sí misma opcional:

```php
if (Schema::hasTable('configs')) {
    return;
}
```

### Estado de esta instalación

Filas presentes hoy en `configs`:

```
alerts_last_check   email          main_settings   backups     languages
plugins             jar_version    db_clear        sms_gateway reports
protocols           last_ports_modification        last_config_modification
```

Todo grupo de `config/tobuli.php` que **no** esté en esa lista se sirve íntegro desde el archivo.

---

## 5. Mapa de grupos

### 5.1 Grupos con capa de BD (`settings()`)

Editar el archivo solo afecta a las subclaves que no estén ya en la fila de BD.

| Grupo | Qué controla | Se edita en |
| ----- | ------------ | ----------- |
| `main_settings` | Nombre del servidor, idioma/zona/formatos por defecto, unidades, mapa y centro por defecto, límites de dispositivos, registro de usuarios, geocoder, LBS, notificaciones de expiración, StreetView, `template_color`, campos de registro personalizado, `user_permissions` | `/admin/main_server_settings/index` |
| `plugins` | ~35 plugins con `status` 0/1 y sus `options` (business/private, colores de ruta, beacons, bloqueo de SIM, geocercas móviles, `reset_driver_on_unknown_rfid`, `geofences_speed_limit`…) | `/admin/plugins/index` |
| `protocols` | Por protocolo: `mergeable`, `expects`, `overwrite`, `bypass_invalid`. Lo consume `PositionsWriter::getProtocolConfig()` | `/admin/ports` (edición de puerto) |
| `payments` | `gateways` on/off y credenciales de PayPal/Stripe/Braintree/Paydunya/2Checkout/Paysera/Asaas | `/admin/billing/gateways` |
| `languages` | Catálogo de idiomas (583 líneas en el archivo): `key`, `iso`, `iso3`, nombre, RTL, estado | `/admin/languages` |
| `sms_gateway` | Gateway SMS del sistema: URL, método, auth, remitente | `/admin/sms_gateway/index` |
| `users_default_sms_gateway` | Gateway SMS por defecto que heredan los usuarios | `/admin/billing/users_default_sms_gateway` |
| `email` | Credenciales y remitente de correo saliente | `/admin/email_settings/index` |
| `backups` | Tipo, periodicidad, hora y destino FTP de los respaldos | `/admin/backups/index` |
| `db_clear` | Purga de posiciones: `status`, `days`, `from` (`server_time`) | `/admin/db_clear/panel` |
| `external_url` | Enlace externo extra en el menú | `/admin/external_url/index` |
| `user_login_methods` | Métodos de login habilitados (email / teléfono / Azure) y config de Azure AD | `AuthManager::setLoginMethods()` |
| `reports` | `status` on/off por `TYPE_ID` de reporte | `/admin/reports/types` |
| `device` | `expiration_offset`, colores por estado, tail (largo/color), `min_moving_speed`, mínimos de llenado/robo de combustible, `icon_id` | solo archivo (no hay fila en BD) |
| `map_controls` | Toggles por defecto del mapa: cluster, mostrar objetos/rutas/POI/tail/nombres, capas de historial | solo archivo |
| `widgets` | Widgets del bottombar: `default`, `status`, `list` | solo archivo (**`list` es lista → cuidado con el reemplazo**) |
| `password` | Política de contraseña: `min_length`, `length`, `includes`, `change_required_current` | solo archivo (**`includes` es lista**) |
| `currency` | Símbolo de moneda | solo archivo |
| `login_periods` | Restricción horaria de login (`enabled`) | archivo / `LOGIN_PERIODS` |
| `dualcam` | Habilita DualCam (`enabled`) | archivo / `DUALCAM` |
| `position_notifications` | A quién se notifica el resultado de un comando (`send_to`) | archivo / `POSITION_RESULT_NOTIFICATION` |
| `extra_required_fields` | Campos extra obligatorios por entidad | solo archivo |
| `alerts_last_check` | Marca de tiempo interna del chequeo de alertas | lo escribe el sistema |

### 5.2 Grupos solo de archivo (`config('tobuli.X')`)

La BD no interviene. Se cambian editando el archivo (o el `.env`) y **reiniciando**.

| Clave | Qué hace |
| ----- | -------- |
| `version` | Versión mostrada del sistema (`3.7.11`) |
| `type` | Tipo de despliegue (`APP_TYPE`, por defecto `ss3`) |
| `logs_path`, `media_path` | Rutas de logs de Traccar y de fotos solicitadas |
| `limits` | Topes por entidad: teléfonos/emails/webhooks por alerta, grupos de geocerca, emails de reporte, dispositivos por comando SMS/GPRS, IPs de reenvío |
| `process` | Timeouts y límites de concurrencia de los demonios: `insert_*`, `reportdaily_*`, `send_event_*` |
| `listview_fields`, `listview` | Columnas disponibles y por defecto de la vista de lista de objetos |
| `numeric_sensors` | Tipos de sensor tratados como numéricos (batería, temperatura, odómetro, `speed_ecm`…) |
| `date_formats`, `time_formats`, `duration_formats` | Catálogos de formato ofrecidos al usuario |
| `template_colors` | Los 25 temas seleccionables (13 colores × claro/oscuro) |
| `dashboard` | Bloques del dashboard: `enabled` y `options` por bloque, incluidos los colores de `device_overview` |
| `model_change_log` | Qué entidades audita `ChangeLogs` y qué atributos ignora |
| `additional_protocols` | Protocolos virtuales (`gpsdata`, `ios`, `android`) |
| `device_configuration` | `delay` entre envíos de configuración al equipo |
| `exports` | Formatos de exportación ofrecidos (CSV, XLS) |
| `weekdays` | Mapa de días a claves de traducción |
| `validation` | `regex_phone` para validar teléfonos |
| `min_database_clear_days` | Mínimo de días que la purga permite conservar |
| `history_max_period_days`, `history_show_invalid` | Ventana máxima de historial y si se muestran posiciones inválidas |
| `device_offline_minutes`, `check_frequency`, `check_chat_frequency`, `check_chat_unread_frequency` | Umbral de offline y frecuencias de polling del frontend |
| `frontend_login`, `frontend_subscriptions`, `frontend_change_password`, `frontend_curl*` | Integración con un frontend externo |
| `sensors`, `units_of_*`, `object_online_timeouts`, `plans` | Arrays vacíos, poblados en runtime |

---

## 6. Ajustes que solo salen del `.env`

Estos usan `env()` dentro del archivo, así que **no hay forma de cambiarlos desde el panel**. Los que
más impactan al pipeline de posiciones van marcados con ⚠️.

| Variable | Clave | Default | Efecto |
| -------- | ----- | ------- | ------ |
| `APPLY_NETWORK_DATA` | `apply_network_data` | `false` | ⚠️ Guarda la celda GSM (`mcc`, `mnc`, `lac`, `cellid`, `operator`) en los parámetros de cada posición. Apagado, el bloque `network` del decoder se descarta |
| `MAX_SPEED_LIMIT` | `max_speed` | `300` | ⚠️ Velocidad por encima de la cual la posición se considera basura |
| `MIN_TIME_GAP` | `min_time_gap` | `600` | ⚠️ Segundos de hueco a partir de los cuales una posición se guarda aunque no haya cambiado nada |
| `OVERWRITE_INVALID` | `overwrite_invalid` | `true` | ⚠️ Permite que una posición inválida herede las coordenadas de la anterior |
| `PREV_POSITION_DEVICE_OBJECT` | `prev_position_device_object` | `false` | ⚠️ Toma la posición previa del objeto en memoria en vez de consultarla |
| `HISTORY_SHOW_INVALID` | `history_show_invalid` | `true` | Muestra posiciones inválidas en el historial |
| `MAX_HISTORY_PERIOD_DAYS` | `history_max_period_days` | `31` | Rango máximo consultable de historial |
| `POSITIONS_IP_LOG` | `positions_ip_log` | `false` | Registra la IP de origen de cada trama |
| `ALERTS_THROW_USER_DEVICE` | `alerts_throw_user_device` | `true` | Propaga la alerta al usuario dueño del dispositivo |
| `DEVICE_SIDEBAR_QUERY` | `device_sidebar_query` | `true` | Consulta optimizada para el sidebar de objetos |
| `ENTITYLOADER_STORE_QUERY` | `entityloader_store_query` | `false` | Cachea queries del EntityLoader |
| `PROC_INSERT_TIMEOUT` / `PROC_INSERT_LIMIT` | `process.insert_*` | `60` / `30` | Timeout y procesos concurrentes de `insert:run` |
| `PROC_REPORT_TIMEOUT` / `PROC_REPORT_LIMIT` | `process.reportdaily_*` | `180` / `2` | Ídem para reportes programados |
| `PROC_SEND_EVENT_TIMEOUT` / `_LIMIT` / `_TAKE` | `process.send_event_*` | `120` / `2` / `50` | Ídem para el envío de eventos |
| `LIMIT_*` | `limits.*` | ver §5.2 | Topes por entidad |
| `GEOCODER_CACHE_DRIVER` | `geocoder_cache_driver` | `sqlite` | Driver de caché del geocoder |
| `DEVICE_CONFIGURATION_DELAY` | `device_configuration.delay` | `5` | Segundos entre envíos de configuración |
| `REGEX_PHONE` | `validation.regex_phone` | `/^\+?\d[0-9]{7,15}$/` | Validación de teléfono |
| `DUALCAM`, `LOGIN_PERIODS`, `EMAIL_DOMAIN`, `FRONTEND_PASSWORD` | varios | — | Ver §5 |
| `LOG_SEND_MAIL_TEMPLATE`, `FALLBACK_SEND_MAIL_TEMPLATE`, `PAYMENTS_ERROR_LOG`, `FUEL_CHECK_LOCK_LOG` | logs | `false`/`true` | Activan trazas de diagnóstico |

---

## 7. Cachés: por qué "cambié algo y no pasó nada"

Hay tres capas de caché encima de esto y las tres muerden.

**1. Caché de proceso, sin expiración.** `Settings::get()` envuelve cada lectura en
`Cache::store('array')->rememberForever()`. El store `array` vive en memoria del proceso. En una
petición web da igual (muere al terminar), pero en un **demonio de larga vida** (`insert:run`,
colas, `schedule:work`) significa que el valor **no se refresca nunca** hasta reiniciar.

**2. Caché de grupo, 15 minutos.** `SettingsDB::_get()` cachea la fila entera de `configs` durante
`15 * 60` segundos. `settings('X', $v)` invalida ese grupo al escribir, pero solo **en el proceso que
escribe** — el panel admin. Los demonios no se enteran.

**3. `config:cache` de Laravel.** Si alguna vez se ejecuta, `config/tobuli.php` y el `.env` quedan
congelados en `bootstrap/cache/config.php`. Hoy ese archivo **no existe** en esta instalación, así
que la config se lee fresca en cada arranque.

### Regla práctica

Después de tocar `config/tobuli.php` o el `.env`:

```bash
php artisan config:clear     # solo si existe bootstrap/cache/config.php
# reiniciar los demonios (supervisor / pm2 / systemd), en particular insert:run
```

Después de guardar algo en el panel admin: el frontend lo ve al instante; los **demonios necesitan
reinicio** (o esperar a los 15 min, y solo para lecturas que no hayan pasado por `rememberForever`).

`PositionsWriter` agrava esto: `getProtocolConfig()` añade su propio `rememberForever("protocol.$protocol")`,
así que un cambio en `/admin/ports` **no llega al writer hasta reiniciarlo**.

---

## 8. Recetas

**Ver el valor efectivo de un ajuste** (con el merge ya aplicado):

```bash
php artisan tinker --execute="echo json_encode(settings('protocols'));"
php artisan tinker --execute="echo json_encode(config('tobuli.limits'));"
```

**Ver qué hay realmente en la BD** (sin el fallback del archivo):

```bash
php artisan tinker --execute="\$r=DB::table('configs')->where('title','protocols')->first(); echo json_encode(unserialize(\$r->value));"
```

**Listar qué grupos tienen fila en BD:**

```bash
php artisan tinker --execute="foreach(DB::table('configs')->get(['title']) as \$r) echo \$r->title.PHP_EOL;"
```

**Cambiar un valor global desde código:**

```php
settings('main_settings.default_language', 'es');   // escribe en configs
```

**Volver una clave al valor del archivo:** borrarla de la BD, no reescribirla.

```php
settings()->forget('main_settings.default_language');
```

**Añadir un ajuste nuevo:** decláralo en `config/tobuli.php` con su valor por defecto. Si además
debe ser editable desde el panel, léelo con `settings('grupo.clave')` y guárdalo con
`settings('grupo.clave', $valor)`. Si es puramente operativo (afecta al pipeline, no al negocio),
déjalo como `env()` y documéntalo en §6.

---

## 9. Trampas conocidas

- **La BD gana siempre.** Editar el archivo para una clave que ya está en `configs` no hace nada.
  Comprobar primero si el consumidor usa `settings()` o `config()`.
- **Las listas se reemplazan, no se fusionan.** `widgets.list`, `password.includes`,
  `gl200.expects` y similares: si acaban en BD, la versión del archivo desaparece entera.
- **El `migrate` inicial contradice al archivo.** En una instalación nueva `default_language` queda
  en `en` aunque el archivo diga `es`, porque la migración inserta su propio bloque hardcodeado.
- **`main_settings.available_maps` tiene una clave duplicada** en el archivo: `"56" => 56` seguido de
  `"56" => 57`. PHP se queda con la última, así que el array queda con 9 entradas en vez de 10 —
  el mapa 56 desaparece y el 57 queda bajo la clave `56`.
- **Los demonios cachean para siempre.** Cualquier cambio de configuración exige reiniciar
  `insert:run` y demás procesos de larga vida.
- **`configs.value` es `serialize()` de PHP, no JSON.** No editarla a mano por SQL salvo que
  reserialices correctamente; usar `settings()`.
