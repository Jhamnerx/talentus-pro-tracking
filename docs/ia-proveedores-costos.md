# AirBot: costos de proveedores de IA

Fecha: 18-nov-2026. Precios tomados de las páginas oficiales de cada proveedor ese día (Groq de
terceros, ver nota). USD por **millón de tokens**. "Caché" es el precio del input repetido
(instrucciones y herramientas), que en AirBot es la mayor parte del input.

## 1. Cómo gasta AirBot hoy (base del cálculo)

Medido con la API real en la sesión de AirBot:

- Prefijo fijo (instrucciones + 27 herramientas): **~10.700 tokens** (permiso Ver) / **~12.600** (Editar).
  Con el caché de 1 h compartido se paga casi siempre como lectura de caché.
- Una pregunta típica hace **2–4 llamadas** (ciclo de herramientas), así que el prefijo se lee varias veces.

**Pregunta típica usada en las tablas:** 38.000 tokens leídos de caché + 12.000 de input nuevo
(pregunta, historial y resultados de herramientas) + 1.800 de salida (con razonamiento).
Es una estimación; el costo real por pregunta lo da `ai_usage_logs` en Admin > AirBot.

## 2. Precios y costo estimado por pregunta

### De pago, con buen uso de herramientas (candidatos para el chat)

| Proveedor / modelo | Input | Caché | Output | **USD / pregunta** | 5.000 preg/mes |
|---|---|---|---|---|---|
| Anthropic Claude Opus 5.5 | 4.00 | 0.20 | 20.00 | ~0.092 | ~460 |
| OpenAI gpt-5.6-terra | 2.00 | 0.20 | 12.00 | ~0.053 | ~265 |
| **Anthropic Claude Sonnet 5.5 (actual)** | 2.00 | 0.10 | 10.00 | **~0.046** | **~230** |
| xAI grok-4.3 | 1.25 | 0.20 | 2.50 | ~0.027 | ~135 |
| DeepSeek v4-pro (hora pico / valle) | 1.32 / 0.66 | 0.044 / 0.022 | 3.96 / 1.98 | ~0.025 / ~0.012 | ~123 / ~62 |
| Google Gemini 3.8 Flash ⚠️ sube 2× el 1-ene-2027 | 0.75 | 0.075 | 3.75 | ~0.019 (→ ~0.037) | ~93 (→ ~186) |
| Mistral Large 4 | 0.50 | ~0.05 | 1.50 | ~0.011 | ~53 |

### Baratos (resúmenes, dashboard, correos; chat simple)

| Proveedor / modelo | Input | Caché | Output | **USD / pregunta** | 5.000 preg/mes |
|---|---|---|---|---|---|
| Google Gemini 3.5 Flash-Lite | 0.30 | 0.03 | 2.50 | ~0.009 | ~46 |
| Groq gpt-oss-120b (sin caché) | 0.15 | — | 0.60 | ~0.009 | ~43 |
| OpenAI gpt-5-mini | 0.25 | 0.025 | 2.00 | ~0.008 | ~38 |
| DeepSeek flash (pico / valle) | 0.30 / 0.15 | 0.006 / 0.003 | 1.20 / 0.60 | ~0.006 / ~0.003 | ~30 / ~15 |
| OpenAI gpt-5.6-luna | 0.20 | 0.02 | 1.20 | ~0.005 | ~26 |
| **Anthropic Claude Haiku 5.5 (actual, resúmenes)** | 0.10 | 0.01 | 0.50 | **~0.0025** | **~13** |
| Google Gemini 2.5 Flash-Lite | 0.10 | 0.01 | 0.40 | ~0.002 | ~12 |
| OpenAI gpt-5-nano | 0.05 | 0.005 | 0.40 | ~0.0015 | ~8 |

Descuento por lotes (Batch): **50 %** en Anthropic, OpenAI, Google y Mistral. Sirve para los
resúmenes de reportes programados y el digest de alertas, que no necesitan respuesta inmediata.

## 3. Alternativas gratuitas

| Opción | Qué da gratis | Problema para AirBot |
|---|---|---|
| Google Gemini (AI Studio), nivel gratis | Flash, Flash-Lite y Gemini 4 Hydrogen sin costo, con límites de peticiones | **Google usa el contenido para mejorar sus productos.** Mandaría ubicaciones, rutas y datos de clientes. Solo sirve para desarrollo y pruebas. |
| OpenRouter, modelos `:free` | 20 peticiones/min; 50/día (1.000/día si compraste USD 10 de crédito) | Hoy casi no hay modelos de texto gratis en el catálogo, cambian sin aviso y el límite diario no alcanza ni para un cliente. |
| Groq, plan Free | Sin tarjeta, solo límites (~30 peticiones/min, tope diario de tokens) | El tope diario de tokens se acaba con pocas preguntas de AirBot (~50.000 tokens cada una). |
| Self-hosted (Ollama / vLLM con Llama, Qwen, gpt-oss, Mistral Small) | Sin costo por token, los datos no salen del servidor | Necesita servidor con GPU (alquilada: ~USD 300–1.000+/mes); el servidor de producción actual (AlmaLinux) no tiene GPU. Los modelos que caben en una GPU fallan más con 27 herramientas y acciones con confirmación. Solo conviene con mucho volumen. |

**Conclusión:** no hay opción gratis para producción. Las gratuitas sirven para desarrollo, o
para un chatbot público sin datos de clientes (por ejemplo, preguntas frecuentes de la web).

## 4. Qué pesa además del precio

1. **Calidad con herramientas.** AirBot tiene 27 herramientas de consulta y 5 acciones (enviar
   comando, crear alerta, renovar licencia…). Un modelo barato que elige mal la herramienta o
   inventa un número sale más caro que la diferencia de precio, porque obliga a repreguntar. Hay
   que probar cada candidato con las mismas preguntas reales antes de cambiar.
2. **Privacidad y residencia de los datos.** DeepSeek procesa en China; el nivel gratis de Gemini
   entrena con los datos. Para clientes regulados (OSINERGMIN, MININTER, mineras) conviene
   Anthropic, OpenAI, Google de pago o Mistral (UE).
3. **Costo de cambiar.** `ClaudeClient` habla el formato de Anthropic: bloques de razonamiento,
   `cache_control`, fallbacks y formato de herramientas. Para usar otros proveedores hay que poner
   una capa común (ver §5). La mayoría (OpenAI, DeepSeek, xAI, Mistral, Groq, OpenRouter, Ollama y
   también Gemini) acepta el **formato compatible con OpenAI**, así que con 2 adaptadores
   (Anthropic + "compatible con OpenAI") se cubren todos.
4. **Caché.** El ahorro grande de AirBot viene del caché del prefijo. Anthropic lo controla a mano
   (1 h compartido); OpenAI, DeepSeek y xAI lo hacen solos; Gemini cobra además el almacenamiento
   por hora; Groq no tiene.

## 5. Recomendación

- **Chat AirBot:** mantener **Claude Sonnet 5.5** como modelo por defecto. Es el que ya está
  probado con las herramientas y las acciones. Tener **un segundo proveedor** configurado como
  respaldo y para planes económicos (candidatos: gpt-5.6-terra o Gemini 3.8 Flash; revisar
  Gemini antes del alza de enero).
- **Resúmenes (explicar reporte, correos, dashboard, digest):** Haiku 5.5 ya es de lo más barato
  que hay (~USD 0,0025). Pasarlos a **Batch** donde no haga falta respuesta inmediata (−50 %).
- **Plan económico por cliente:** permitir elegir el modelo **por plan** (por ejemplo, plan
  básico con Haiku 5.5 o gpt-5.6-luna; plan premium con Sonnet 5.5). Es el mayor ahorro sin
  bajar la calidad para quien paga más.
- **Desarrollo:** el nivel gratis de Gemini o Groq para no gastar en pruebas.

## 6. Plan de trabajo propuesto (para validar)

1. **Capa de proveedores:** interfaz `LlmProvider` con dos adaptadores, `AnthropicProvider` (el
   `ClaudeClient` actual) y `OpenAiCompatibleProvider` (OpenAI, DeepSeek, xAI, Mistral, Groq,
   OpenRouter, Ollama, Gemini). Convertir herramientas, resultados de herramientas y streaming al
   formato de cada uno.
2. **`config/ai.php` por proveedor:** clave, URL base, modelos y tabla de precios por proveedor;
   el costo en `ai_usage_logs` se calcula con la tabla del modelo que respondió.
3. **Modelo por función y por plan:** chat, explicar reporte, resúmenes y digest, cada uno con
   su proveedor y modelo, configurable en Admin > AirBot y por plan.
4. **Respaldo:** si el proveedor principal falla (429/5xx/caída), reintentar con el secundario.
5. **Banco de preguntas:** 30–50 preguntas reales para comparar proveedores en calidad, costo y
   latencia antes de cambiar el modelo por defecto.
6. **Chatbot nuevo** (falta definir el alcance: WhatsApp, Telegram, web pública o soporte).
7. **Más funciones** (falta definir cuáles).

## Fuentes

- Anthropic: precios de la guía de la API (caché 3-nov-2026), iguales a `config/ai.php`.
- OpenAI: https://developers.openai.com/api/docs/pricing
- Google Gemini: https://aistudio.google.com/docs/pricing
- DeepSeek: https://api-docs.deepseek.com/quick_start/pricing
- xAI: https://docs.x.ai/docs/models
- Mistral: https://mistral.ai/pricing (solo publica Large; el resto, en la consola)
- Groq: https://console.groq.com/docs/models (gpt-oss); límites del plan gratis según fuentes de terceros, sin confirmar
- OpenRouter: https://openrouter.ai/blog/tutorials/free-llm-apis-compared/
