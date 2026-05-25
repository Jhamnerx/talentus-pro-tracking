# API — v2/get_devices_by_plate

## Descripción

Devuelve información básica de los dispositivos del usuario autenticado junto con sus sensores configurados y la última posición conocida. Se puede filtrar por número de placa.

---

## Endpoint

```
GET  /api/v2/get_devices_by_plate
POST /api/v2/get_devices_by_plate
```

Acepta tanto GET como POST. Si es POST, los parámetros pueden ir en el body (form-data, x-www-form-urlencoded o JSON) o en la query string de la URL.

---

## Autenticación

Se utiliza el **api_hash del usuario** enviado como parámetro GET en la URL, incluso si el método es POST.

| Parámetro       | Tipo   | Obligatorio | Descripción                       |
| --------------- | ------ | ----------- | --------------------------------- |
| `user_api_hash` | string | **Sí**      | Hash de autenticación del usuario |

El `user_api_hash` se obtiene del endpoint de login (`POST /api/login`).

---

## Parámetros de filtro

| Parámetro      | Tipo   | Obligatorio | Descripción                        |
| -------------- | ------ | ----------- | ---------------------------------- |
| `plate_number` | string | No          | Filtrar por número de placa exacto |

Si no se envía `plate_number`, se devuelven **todos** los dispositivos del usuario.

---

## Respuesta exitosa — 200 OK

```json
{
  "status": 1,
  "total": 2,
  "items": [
    {
      "name": "Unidad 01",
      "plate_number": "ABC-123",
      "active": true,
      "lat": -12.046374,
      "lng": -77.042793,
      "speed": 0,
      "course": 180,
      "time": "2026-05-25 08:30:00",
      "server_time": "2026-05-25 08:30:05",
      "sensors": [
        {
          "id": 5,
          "name": "Velocidad GPS",
          "type": "speed",
          "tag_name": "speed",
          "value": "0 km/h",
          "unit_of_measurement": "km/h",
          "add_to_history": true
        },
        {
          "id": 9,
          "name": "Voltaje",
          "type": "voltage",
          "tag_name": "io66",
          "value": "12.6 V",
          "unit_of_measurement": "V",
          "add_to_history": false
        }
      ]
    }
  ]
}
```

### Campos del dispositivo

| Campo          | Tipo    | Descripción                                      |
| -------------- | ------- | ------------------------------------------------ |
| `name`         | string  | Nombre del dispositivo                           |
| `plate_number` | string  | Número de placa                                  |
| `active`       | boolean | Si el dispositivo está activo                    |
| `lat`          | float   | Última latitud conocida o null                   |
| `lng`          | float   | Última longitud conocida o null                  |
| `speed`        | float   | Última velocidad conocida (km/h) o null          |
| `course`       | integer | Último rumbo en grados o null                    |
| `time`         | string  | Fecha/hora de la última posición del GPS o null  |
| `server_time`  | string  | Fecha/hora de recepción en el servidor o null    |
| `sensors`      | array   | Lista de sensores configurados en el dispositivo |

### Campos de cada sensor

| Campo                 | Tipo    | Descripción                                           |
| --------------------- | ------- | ----------------------------------------------------- |
| `id`                  | integer | ID del sensor                                         |
| `name`                | string  | Nombre del sensor                                     |
| `type`                | string  | Tipo: `speed`, `voltage`, `engine`, `fuel_tank`, etc. |
| `tag_name`            | string  | Tag del protocolo GPS que alimenta el sensor          |
| `value`               | string  | Último valor calculado con unidad o null              |
| `unit_of_measurement` | string  | Unidad de medida                                      |
| `add_to_history`      | boolean | Si el sensor se guarda en historial                   |

---

## Respuesta — 403 Forbidden

```json
{
  "status": 0,
  "message": "You don't have permission to do this."
}
```

---

## Ejemplos cURL

### Todos los dispositivos con sensores

```bash
curl -X GET "https://tu-dominio.com/api/v2/get_devices_by_plate?user_api_hash=TU_HASH_AQUI"
```

### Filtrar por placa (GET)

```bash
curl -X GET "https://tu-dominio.com/api/v2/get_devices_by_plate?user_api_hash=TU_HASH_AQUI&plate_number=ABC-123"
```

### Filtrar por placa (POST, hash en URL)

```bash
curl -X POST "https://tu-dominio.com/api/v2/get_devices_by_plate?user_api_hash=TU_HASH_AQUI" \
  -d "plate_number=ABC-123"
```