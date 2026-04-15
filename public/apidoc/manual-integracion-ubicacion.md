# DOCUMENTACIÓN TÉCNICA

## Integración API REST — Consulta de Ubicación Vehicular en Tiempo Real

**Versión:** 1.0.0
**Fecha:** Abril 2026
**Proveedor:** Talentus Technology GPS
**Dirigido a:** Equipo técnico del cliente (CCP / MYPER)

---

## 1. Introducción

Este documento describe la implementación del endpoint REST que permite consultar en tiempo real la posición geográfica y velocidad de vehículos registrados en la plataforma GPS, identificados por su número de placa.

La integración cumple con todos los requisitos técnicos especificados en el documento de requerimiento entregado por el cliente.

---

## 2. Características de la Implementación

| Atributo             | Valor                                |
| -------------------- | ------------------------------------ |
| Protocolo            | API REST                             |
| Formato              | JSON (application/json)              |
| Método HTTP          | POST                                 |
| Autenticación        | Bearer Token                         |
| Transporte           | HTTPS obligatorio                    |
| Antigüedad máxima    | 60 segundos                          |
| Límite de placas     | 200 por request                      |
| Rate limit           | 60 requests / minuto por token       |
| Timeout de respuesta | < 3 segundos en condiciones normales |

---

## 3. Autenticación

La autenticación se realiza mediante **Bearer Token** en el header `Authorization`.

El token de acceso (`api_hash`) es entregado directamente por el proveedor GPS al cliente. **No existe un endpoint de login** — el token es de larga duración y se gestiona de forma administrativa.

### Header requerido

```
Authorization: Bearer <token>
Content-Type: application/json
```

### Ejemplo

```
Authorization: Bearer a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4
Content-Type: application/json
```

> **Importante:** El token debe mantenerse confidencial. En caso de compromiso, comunicarse con soporte técnico para su renovación.

---

## 4. Endpoint de Producción

### 4.1 URL

```
POST https://plataforma.talentustechnology.com/api/v1/myper/ubicacion
```

### 4.2 Request Body

```json
{
  "items": [{ "Placa": "ABC-123" }, { "Placa": "CDE-456" }]
}
```

| Campo         | Tipo   | Obligatorio | Descripción                    |
| ------------- | ------ | ----------- | ------------------------------ |
| items         | Array  | Sí          | Lista de vehículos a consultar |
| items[].Placa | String | Sí          | Número de placa del vehículo   |

---

## 5. Respuestas del Servicio

Todas las respuestas comparten la misma estructura base:

| Campo        | Tipo    | Descripción                                         |
| ------------ | ------- | --------------------------------------------------- |
| responseCode | Integer | Código de resultado (200, 400, 401)                 |
| message      | String  | Mensaje descriptivo del resultado                   |
| items        | Array   | Lista de vehículos encontrados con posición vigente |
| errors       | Array   | Mensajes de error o placas no encontradas           |

### 5.1 Respuesta exitosa — `responseCode: 200`

```json
{
  "responseCode": 200,
  "message": "",
  "items": [
    {
      "Placa": "ABC-123",
      "Latitud": 4.710989,
      "Longitud": -74.07209,
      "Velocidad": 45.0
    },
    {
      "Placa": "CDE-456",
      "Latitud": 4.653427,
      "Longitud": -74.0812,
      "Velocidad": 0.0
    }
  ],
  "errors": []
}
```

| Campo     | Tipo   | Unidad           | Descripción                        |
| --------- | ------ | ---------------- | ---------------------------------- |
| Placa     | String | —                | Número de placa del vehículo       |
| Latitud   | Float  | Grados decimales | Coordenada de latitud (WGS84)      |
| Longitud  | Float  | Grados decimales | Coordenada de longitud (WGS84)     |
| Velocidad | Float  | km/h             | Velocidad instantánea del vehículo |

### 5.2 Respuesta con errores parciales — `responseCode: 200`

Cuando algunas placas se encuentran pero otras no, el sistema responde con HTTP 200. Las placas encontradas aparecen en `items` y las no encontradas se reportan en `errors`.

```json
{
  "responseCode": 200,
  "message": "",
  "items": [
    {
      "Placa": "ABC-123",
      "Latitud": 4.710989,
      "Longitud": -74.07209,
      "Velocidad": 45.0
    }
  ],
  "errors": [
    "Placa no encontrada: CDE-456",
    "Placa sin datos recientes (> 60 s): XYZ-999"
  ]
}
```

### 5.3 Error en los datos enviados — `responseCode: 400`

Se retorna cuando el body del request no cumple el formato esperado.

```json
{
  "responseCode": 400,
  "message": "Error en la obtención de datos",
  "items": [],
  "errors": ["El campo items es requerido y debe ser un arreglo no vacío"]
}
```

### 5.4 Error de autenticación — `responseCode: 401`

Se retorna cuando el token es inválido, está expirado o el usuario se encuentra desactivado.

```json
{
  "responseCode": 401,
  "message": "Error en la autenticación",
  "items": [],
  "errors": ["Token de autenticación inválido o expirado"]
}
```

### 5.5 Tabla resumen de códigos

| responseCode | Descripción                                             |
| :----------: | ------------------------------------------------------- |
|     200      | Consulta procesada (puede haber errores parciales)      |
|     400      | Error en los datos enviados o formato incorrecto        |
|     401      | Token de autenticación inválido, expirado o desactivado |

---

## 6. Comportamiento ante Casos Especiales

| Situación                                | Comportamiento                                                               |
| ---------------------------------------- | ---------------------------------------------------------------------------- |
| Placa no registrada en el sistema        | Se excluye de `items` y se reporta en `errors`                               |
| Placa registrada sin datos GPS recientes | Se excluye de `items` si el último dato supera los 60 segundos de antigüedad |
| Placa duplicada en el request            | Se procesa una sola vez                                                      |
| Más de 200 placas en un request          | Se retorna error 400                                                         |
| Token válido pero usuario desactivado    | Se retorna error 401                                                         |

---

## 7. Ambiente de Pruebas (Sandbox)

Se dispone de un endpoint sandbox con datos simulados para validar la integración **sin necesidad de credenciales** ni vehículos reales.

### 7.1 URL Sandbox

```
POST https://plataforma.talentustechnology.com/api/v1/myper/sandbox/ubicacion
```

No requiere header `Authorization`.

### 7.2 Placas de prueba disponibles

| Placa   | Latitud    | Longitud   | Velocidad |
| ------- | ---------- | ---------- | --------- |
| ABC-123 | -12.046374 | -77.042793 | 45 km/h   |
| CDE-456 | -12.083261 | -77.054961 | 0 km/h    |
| EFG-789 | -12.121500 | -76.990100 | 72 km/h   |
| HIJ-000 | -11.990000 | -77.110000 | 30 km/h   |
| KLM-321 | -12.005000 | -77.020000 | 0 km/h    |

Cualquier placa diferente a las anteriores será reportada en `errors` como no encontrada.

### 7.3 Ejemplo de request sandbox

```bash
curl -X POST https://plataforma.talentustechnology.com/api/v1/myper/sandbox/ubicacion \
  -H "Content-Type: application/json" \
  -d '{"items": [{"Placa": "ABC-123"}, {"Placa": "INVALIDA-999"}]}'
```

### 7.4 Respuesta sandbox

```json
{
  "responseCode": 200,
  "message": "[SANDBOX] Datos simulados. No usar en producción.",
  "items": [
    {
      "Placa": "ABC-123",
      "Latitud": -12.046374,
      "Longitud": -77.042793,
      "Velocidad": 45.0
    }
  ],
  "errors": ["Placa no encontrada en sandbox: INVALIDA-999"]
}
```

---

## 8. Rate Limiting

| Ambiente   | Límite                       | Respuesta al exceder       |
| ---------- | ---------------------------- | -------------------------- |
| Producción | 60 requests / minuto / token | HTTP 429 Too Many Requests |
| Sandbox    | 60 requests / minuto / IP    | HTTP 429 Too Many Requests |

---

## 9. Ejemplo de Integración Completo

### Request

```http
POST /api/v1/myper/ubicacion HTTP/1.1
Host: plataforma.talentustechnology.com
Authorization: Bearer a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4
Content-Type: application/json

{
  "items": [
    { "Placa": "SJA-123" },
    { "Placa": "ABC-456" },
    { "Placa": "XYZ-999" }
  ]
}
```

### Response

```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "responseCode": 200,
  "message": "",
  "items": [
    {
      "Placa": "SJA-123",
      "Latitud": 4.710989,
      "Longitud": -74.072090,
      "Velocidad": 62.0
    },
    {
      "Placa": "ABC-456",
      "Latitud": 4.653427,
      "Longitud": -74.081200,
      "Velocidad": 0.0
    }
  ],
  "errors": [
    "Placa no encontrada: XYZ-999"
  ]
}
```

---

## 10. Recursos Adicionales

| Recurso                | Descripción                                                                       |
| ---------------------- | --------------------------------------------------------------------------------- |
| Colección Postman      | `myper-ubicacion.postman_collection.json` — importar directamente en Postman v2.1 |
| Especificación OpenAPI | `myper-ubicacion.openapi.yaml` — compatible con Swagger UI, Postman, Insomnia     |
| Sandbox URL            | `https://plataforma.talentustechnology.com/api/v1/myper/sandbox/ubicacion`        |

---

## 11. Entregables del Proveedor GPS

| Entregable                 | Estado       | Detalle                                                    |
| -------------------------- | ------------ | ---------------------------------------------------------- |
| URL endpoint producción    | ✅ Entregado | `POST /api/v1/myper/ubicacion`                             |
| URL endpoint sandbox       | ✅ Entregado | `POST /api/v1/myper/sandbox/ubicacion` (sin autenticación) |
| Mecanismo de autenticación | ✅ Entregado | Bearer Token entregado directamente por el proveedor       |
| Credenciales de prueba     | ✅ Entregado | Token de prueba adjunto a este documento                   |
| Documentación técnica      | ✅ Entregado | Colección Postman + especificación OpenAPI 3.0.3           |
| Contacto técnico           | ✅ Entregado | Ver sección 12                                             |

---

## 12. Contacto Técnico

| Dato    | Información                                            |
| ------- | ------------------------------------------------------ |
| Empresa | Talentus Technology GPS                                |
| Soporte | soporte@talentustechnology.com                         |
| Horario | Lunes a viernes, 8:00 a.m. – 6:00 p.m. (hora Colombia) |

---

_Fin del documento — Versión 1.0.0 — Talentus Technology GPS_
