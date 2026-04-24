## MANUAL TÉCNICO – INTEGRACIÓN SERVICIO DE RASTREO GPS

```
V 1. 1 – Plataforma Intranet – Módulo DANA – Gestión operacional
```

## 1. DESCRIPCIÓN GENERAL

El servicio corresponde a un endpoint **HTTP** tipo REST (no estrictamente RESTful) que permite
registrar información de rastreo de unidades móviles (transporte) mediante el envío de datos
vía parámetros en la URL.

Es un **HTTP POST** con parámetros enviados como query string sin body estructurado (body vacío)

**2. ENDPOINT**

**https://eps.center/api/transporte/rastreo/registro**

**3. TIPO DE SERVICIO**

# Característica Valor

# Tipo HTTP API

# Método POST

# Estilo Híbrido (POST + query params)

# Content-Type No requerido

# Body Vacío

# Response JSON (según header Accept)

## 4. FORMA DE ENVÍO

- **Método: POST**
- **Parámetros: Query String (URL)**
- **Ejemplo: POST**
  **https://eps.center/api/transporte/rastreo/registro?id_empresa=24&id_transporte=10&te**
  **lefono=999999999&latitud=-5.77&longitud=-78.45&fecha=2026- 04 -**
  **22&hora=10:00:00&velocidad=35.5&bateria=4&mensaje=OK**

## 5. PARÁMETROS REQUERIDOS

# Parámetro Tipo Valor

# id_empresa Integer 22

# id_transporte Integer ID de la unidad

# telefono Varchar(12) 999999999

# latitud Double(15,8) Coordenada GPS

# longitud Double(15,8) Coordenada GPS

# fecha Date Formato yyyy-mm-dd

# hora Time Formato hh:mm:ss

# velocidad Decimal(6,2) Velocidad

# bateria Double(3,2) Nivel de batería

# Parámetro Tipo Valor

# mensaje Varchar(200) Ok

## 6. IMPLEMENTACIÓN EN JAVA (OKHTTP)

- Código funcional de ejemplo

## 7. OBSERVACIONES IMPORTANTES

```
✓ No es REST puro
✓ No usa JSON en body
✓ Usa query params en POST
✓ Se requiere un body vacío obligatorio
```

Validación de formatos

```
Campo Regla
fecha yyyy-MM-dd
hora HH:mm:ss
lat/lng decimal válido
batería entero 1–5 (según implementación real)
```

# ✓ Batería , aunque la estructura del dato dice Double(3,2) se espera valores de 1 a 5

# donde 1 → 20% 2 → 40% 3 → 60% 4 → 80% 5 → 100%

## 8. RESPUESTAS DEL SERVICIO

Error de validación:

{

```
"status": false,
```

```
"mensaje": "No se ha proporcionado todos los datos necesarios."
```

}

Registro exitoso:

{

```
"status": true,
```

```
"mensaje": "Registro exitoso."
```

}
