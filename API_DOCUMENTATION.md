# Documentación API - Nuevos Endpoints

## Autenticación

Todos los endpoints requieren autenticación mediante `user_api_hash` que se obtiene del endpoint de login.

**Parámetro requerido en todas las peticiones:**

```
user_api_hash={tu_hash_de_autenticacion}
```

Este parámetro debe incluirse en la URL como parámetro GET en todas las solicitudes.

---

## 1. Búsqueda de Dispositivo por Placa

Busca un dispositivo específico por número de placa exacto.

### Endpoint

```
GET /api/get_devices_by_plate
```

### Parámetros Query

| Parámetro       | Tipo   | Requerido | Descripción                            |
| --------------- | ------ | --------- | -------------------------------------- |
| `user_api_hash` | string | **Sí**    | Hash de autenticación del usuario      |
| `plate_number`  | string | **Sí**    | Número de placa exacto del dispositivo |

### Ejemplo de Solicitud

```bash
curl -X GET "https://tu-dominio.com/api/get_devices_by_plate?user_api_hash=tu_hash_aqui&plate_number=ADF-730"
```

### Respuesta Exitosa (200)

```json
{
  "status": 1,
  "device": {
    "id": 502,
    "imei": "865413055961162",
    "sim_number": "982086064",
    "name": "ADF-730 SHADAY",
    "plate_number": "ADF-730"
  }
}
```

### Respuestas de Error

**400 - Parámetro faltante**

```json
{
  "status": 0,
  "message": "plate_number parameter is required"
}
```

**403 - Sin permisos**

```json
{
  "status": 0,
  "message": "No tiene permiso para realizar esta acción"
}
```

**404 - Dispositivo no encontrado**

```json
{
  "status": 0,
  "message": "Device not found with plate number: ADF-730"
}
```

### Notas

- La búsqueda es exacta (debe coincidir completamente)
- Solo busca en dispositivos del usuario autenticado
- Los números de placa son únicos por dispositivo

---

## 2. Estado de Transmisión de Dispositivos

Obtiene el estado de transmisión de todos los dispositivos activos, categorizados por tiempo sin conexión.

### Endpoint

```
GET /api/get_devices_transmission_status
```

### Parámetros Query

| Parámetro       | Tipo    | Requerido | Descripción                         | Default |
| --------------- | ------- | --------- | ----------------------------------- | ------- |
| `user_api_hash` | string  | **Sí**    | Hash de autenticación del usuario   | -       |
| `show_empty`    | boolean | No        | Mostrar categorías sin dispositivos | false   |

### Categorías de Estado

| Categoría         | Tiempo Offline | Prioridad | Descripción                 |
| ----------------- | -------------- | --------- | --------------------------- |
| `ok`              | < 1 hora       | 0         | Transmitiendo normalmente   |
| `warning`         | 1-24 horas     | 1         | Sin transmisión reciente    |
| `critical`        | 1-7 días       | 2         | Requiere atención inmediata |
| `emergency`       | > 7 días       | 3         | Requiere revisión urgente   |
| `never_connected` | Sin conexión   | 4         | Nunca se ha conectado       |

### Ejemplo de Solicitud

```bash
curl -X GET "https://tu-dominio.com/api/get_devices_transmission_status?user_api_hash=tu_hash_aqui&show_empty=false"
```

### Respuesta Exitosa (200)

```json
{
  "status": 1,
  "total_active_devices": 8,
  "summary": {
    "ok": 7,
    "warning": 1,
    "critical": 0,
    "emergency": 0,
    "never_connected": 0
  },
  "categories": [
    {
      "key": "ok",
      "title": "OK (< 1 hora)",
      "description": "Transmitiendo normalmente",
      "priority": 0,
      "count": 7,
      "items": [
        {
          "id": 502,
          "name": "ADF-730 SHADAY",
          "imei": "865413055961162",
          "plate_number": "ADF-730",
          "sim_number": "982086064",
          "last_connection": "2026-02-11 13:29:23",
          "last_connection_utc": "2026-02-11 18:29:23",
          "days_offline": 0,
          "hours_offline": 0.2,
          "status": "online"
        }
      ]
    },
    {
      "key": "warning",
      "title": "Advertencia (1-24 horas)",
      "description": "Sin transmisión reciente",
      "priority": 1,
      "count": 1,
      "items": [
        {
          "id": 91,
          "name": "D9L-954 MELCHOR",
          "imei": "865413054701304",
          "plate_number": "D9L-954",
          "sim_number": "961841025",
          "last_connection": "2026-02-11 11:40:35",
          "last_connection_utc": "2026-02-11 16:40:35",
          "days_offline": 0.1,
          "hours_offline": 1.8,
          "status": "offline"
        }
      ]
    }
  ]
}
```

### Respuestas de Error

**403 - Sin permisos**

```json
{
  "status": 0,
  "message": "No tiene permiso para realizar esta acción"
}
```

### Códigos de Estado del Dispositivo

| Status    | Descripción                |
| --------- | -------------------------- |
| `online`  | Conectado y transmitiendo  |
| `offline` | Desconectado temporalmente |
| `ack`     | Última posición conocida   |

### Notas Importantes

- **Zona horaria**: Los timestamps se convierten de UTC a hora de Lima (UTC-5)
- Solo incluye dispositivos con `active = 1`
- `last_connection`: Hora local de Lima
- `last_connection_utc`: Hora UTC original
- Por defecto, solo muestra categorías con dispositivos (usar `show_empty=true` para ver todas)
- Los cálculos de tiempo consideran la zona horaria correcta para precisión

### Casos de Uso

1. **Monitoreo de Flota**: Identificar vehículos con problemas de transmisión
2. **Alertas Preventivas**: Actuar antes de que un dispositivo quede completamente offline
3. **Dashboard de Estado**: Mostrar resumen visual del estado de la flota
4. **Reportes de Mantenimiento**: Identificar dispositivos que requieren revisión técnica

---

## Autenticación Completa

### Login

```bash
curl -X POST "https://tu-dominio.com/api/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "usuario@ejemplo.com",
    "password": "tu_password"
  }'
```

**Respuesta:**

```json
{
  "status": 1,
  "user_api_hash": "hash_generado_para_autenticacion",
  "permissions": {...}
}
```

El `user_api_hash` debe incluirse en todas las peticiones subsecuentes como parámetro GET en la URL.

---

## Límites y Restricciones

- **Rate Limiting**: Aplican los límites configurados en el middleware `throttle`
- **Paginación máxima**: 100 registros por página
- **Memoria**: Los endpoints con muchos dispositivos usan límites de memoria configurados
- **Permisos**: Requiere permiso `devices.view` activo

---

## Ejemplos de Integración

### PHP

```php
<?php

$apiHash = 'tu_api_hash';
$baseUrl = 'https://tu-dominio.com/api';

// Buscar por placa
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/get_devices_by_plate?user_api_hash=' . $apiHash . '&plate_number=ADF-730');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$data = json_decode($response, true);

if ($data['status'] === 1) {
    $device = $data['device'];
    echo "Dispositivo encontrado: " . $device['name'] . "\n";
    echo "IMEI: " . $device['imei'] . "\n";
} else {
    echo "Error: " . $data['message'] . "\n";
}

curl_close($ch);
```

### JavaScript (Node.js)

```javascript
const axios = require("axios");

const apiHash = "tu_api_hash";
const baseUrl = "https://tu-dominio.com/api";

// Estado de transmisión
async function getTransmissionStatus() {
  try {
    const response = await axios.get(
      `${baseUrl}/get_devices_transmission_status?user_api_hash=${apiHash}`,
    );

    console.log("Total dispositivos:", response.data.total_active_devices);
    console.log("Resumen:", response.data.summary);

    // Procesar dispositivos críticos
    const critical = response.data.categories.find((c) => c.key === "critical");
    if (critical && critical.count > 0) {
      console.log("¡ALERTA! Dispositivos críticos:", critical.count);
    }
  } catch (error) {
    console.error("Error:", error.response?.data || error.message);
  }
}

getTransmissionStatus();
```

### Python

```python
import requests

api_hash = 'tu_api_hash'
base_url = 'https://tu-dominio.com/api'

# Buscar dispositivo
response = requests.get(
    f'{base_url}/get_devices_by_plate',
    params={
        'user_api_hash': api_hash,
        'plate_number': 'ADF-730'
    }
)

if response.status_code == 200:
    data = response.json()
    if data['status'] == 1:
        device = data['device']
        print(f"Dispositivo: {device['name']}")
        print(f"Placa: {device['plate_number']}")
        print(f"IMEI: {device['imei']}")
elif response.status_code == 404:
    print(f"Dispositivo no encontrado")
```

---

## Soporte

Para reportar problemas o solicitar funcionalidades adicionales, contactar al equipo de desarrollo.

**Última actualización**: Febrero 2026
