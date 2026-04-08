# REQUERIMIENTO TÉCNICO

## Integración API REST — Ubicación Vehicular en Tiempo Real

## 1. Objetivo

### Se requiere que el proveedor GPS habilite un endpoint de API REST que permita consultar la

### ubicación y velocidad en tiempo real de un listado de vehículos, identificados por su número de

### placa.

## 2. Tipo de Integración

### Atributo Valor

### Protocolo API REST

### Formato JSON (application/json)

### Método HTTP POST

### Autenticación Bearer Token (JWT)

### Transporte HTTPS obligatorio

### Timeout máximo 10 segundos

## 3. Especificación del Endpoint

### 3.1 URL

### POST https://{dominio-gps}/api/v1/vehiculos/ubicacion

### 3.2 Headers

### Authorization: Bearer <token>

### Content-Type: application/json

### 3.3 Request Body

### Se envía un arreglo de objetos con las placas a consultar:

### {

### "items": [

### { "Placa": "ABC-123" },

### { "Placa": "CDE-456" }

### ]

### }

### Campo Tipo Obligatorio Descripción

### items Array Sí Lista de vehículos a consultar

### items[].Placa String Sí Número de placa del vehículo

## 4. Respuesta del Servicio

### Todos los responses comparten la misma estructura base:

### Campo Tipo Descripción

### responseCode Integer Código de resultado de la operación

### message String Mensaje descriptivo del resultado

### items Array Lista de vehículos con datos de ubicación

### errors Array Lista de mensajes de error detallados

### 4.1 Respuesta exitosa — responseCode: 200

### {

### "responseCode": 200,

### "message": "",

### "items": [

### {

### "Placa": "ABC-123",

### "Latitud": -12.046374,

### "Longitud": -77.042793,

### "Velocidad": 45.

### },

### {

### "Placa": "CDE-456",

### "Latitud": -12.083261,

### "Longitud": -77.054961,

### "Velocidad": 0.

### }

### ],

### "errors": []

### }

### Campo Tipo Unidad Descripción

### Placa String — Número de placa del vehículo

### Latitud Float Grados decimales Coordenada de latitud (WGS84)

### Longitud Float Grados decimales Coordenada de longitud (WGS84)

### Velocidad Float km/h Velocidad instantánea del

### vehículo

### 4.2 Error de autenticación — responseCode: 401

### {

### "responseCode": 401,

### "message": "Error en la autenticación",

### "items": [],

### "errors": ["Error1", "Error2"]

### }

### 4.3 Error en la obtención de datos — responseCode: 400

### {

### "responseCode": 400,

### "message": "Error en la obtención de datos",

### "items": [],

### "errors": ["Error1", "Error2"]

### }

### responseCode Descripción

### 200 Consulta exitosa

### 400 Error en los datos enviados o en la obtención de información

### 401 Token de autenticación inválido o expirado

## 5. Consideraciones Adicionales

### Consideración Detalle

### Placa no encontrada Informar si se omite del resultado o se incluye con valores nulos

### Antigüedad máxima del dato Se requiere que la ubicación tenga máximo 60 segundos de

### antigüedad

### Límite de placas por request Informar el máximo soportado (se estiman hasta 200 vehículos)

### Ambiente de pruebas Se solicita un sandbox con datos simulados previo a producción

### Rate Limiting Informar el límite de peticiones permitidas por minuto

## 6. Entregables Esperados del Proveedor

### Entregable Descripción

### URL del endpoint Ambiente de pruebas y producción

### Mecanismo de obtención de token Endpoint o proceso para obtener el JWT

### Credenciales de prueba Token o usuario/contraseña para el sandbox

### Documentación técnica Colección Postman o especificación Swagger/OpenAPI

### Contacto técnico Nombre y correo del responsable de la integración

### — Fin del documento —
