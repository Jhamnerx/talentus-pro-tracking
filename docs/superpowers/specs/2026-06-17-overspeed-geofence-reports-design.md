# Diseño: Reusabilidad y prioridad de geocercas en reportes de exceso de velocidad (904/905/906)

## Contexto

Los reportes custom 902-906 (`ReportManager::$types`) incluyen tres variantes de "exceso de velocidad en geocerca":

- `904` — `OverspeedsInGeofenceCustomReport` (recorre posiciones GPS crudas, evalúa contra todas las geocercas activas del usuario).
- `905` — `OverspeedsInGeofenceCustomReportV2` (usa el motor de Historial compartido, `Group`/`Actions`, requiere selección manual de geocercas).
- `906` — `OverspeedsInGeofenceCustomReportBuenaventura` (clon exacto de 905, solo cambia el título).

Dos problemas identificados:

1. **904 no es reutilizable**: siempre incluye todas las posiciones dentro de una geocerca con `speed_limit`, exceda o no el límite. No hay forma de filtrar solo violaciones.
2. **Prioridad arbitraria entre geocercas superpuestas**: tanto el loop manual de 904 como el motor compartido (`AppendSpeedLimitGeofence`, usado por 905/906 y por `RagGeofencesReport`) eligen la primera geocerca que contiene el punto, en el orden en que la consulta a la base de datos las devuelve — que por defecto es por `id` ascendente (orden de creación), no por especificidad geométrica. Esto es accidental, no una regla de diseño: si una geocerca más específica (una curva) tiene un ID mayor que la zona grande que la contiene, la zona grande "gana" incorrectamente.

## Alcance

- Cambios en **904, 905 y 906 únicamente**.
- El motor compartido `AppendSpeedLimitGeofence` (usado también por `RagGeofencesReport`, de otros clientes) **no se modifica**. Para 905/906 se introduce una acción paralela (`AppendSpeedLimitGeofenceByPriority`) que reemplaza a `AppendSpeedLimitGeofence` solo en esos dos reportes.
- No se modifican alertas en tiempo real (`OverspeedAlertCheck`, `GeofenceAlertCheck`) — usan un código completamente separado y no fueron parte de este pedido.

## Comportamiento objetivo

### 904 — nuevo parámetro `only_violations`

Checkbox en `getInputParameters()`, default `true` (checked):

- `only_violations = true` (default): el reporte solo incluye posiciones que exceden el límite de al menos una geocerca.
- `only_violations = false`: además de las violaciones, incluye posiciones dentro de una geocerca sin exceder el límite.

### 904 — evento por geocerca excedida

Cuando una posición cae dentro de **varias geocercas con `speed_limit`** y excede el límite de más de una simultáneamente (ej.: 60 km/h dentro de una curva de 40 y una zona de 55 al mismo tiempo), el reporte genera **una fila por cada geocerca cuyo límite fue excedido**, no solo la más específica. Cada fila identifica su propia `geofence_name`, `speed_limit`, `speed_difference` y `violation_level`.

Cuando una posición no excede ningún límite (solo relevante si `only_violations = false`) y cae dentro de varias geocercas superpuestas, se genera **una sola fila**, usando la geocerca más específica (área más pequeña) como referencia — para no listar N filas idénticas de "sin exceso" por cada geocerca superpuesta que cumple.

### 904 — rendimiento

`Geofence::where('user_id', ...)->where('active', 1)->get()` se ejecuta hoy dentro de `processPosition()`, es decir, una vez por cada posición GPS procesada. Se mueve a `beforeGenerate()` (se ejecuta una sola vez por reporte) y se cachea en una propiedad de instancia.

### 905/906 — prioridad por área

Cuando una posición cae dentro de varias geocercas seleccionadas con `speed_limit` definido (sin exceso de por medio — este es el caso que determina qué límite aplica para detectar overspeeding en el motor de Historial), se usa la geocerca de **menor área aproximada** como la geocerca "vigente" en ese punto, en vez de la primera en orden de iteración.

## Componentes

### `Tobuli/Helpers/PolygonHelper.php`

Agregar `getArea(): ?float`. El área (fórmula shoelace) ya se calcula internamente en `getCenter()` pero se descarta; se expone sin alterar el comportamiento de `getCenter()`.

### `Tobuli/Entities/Geofence.php`

Dos métodos nuevos:

- `approxAreaSqMeters(): ?float`
  - `type === 'circle'`: `M_PI * pow($this->radius, 2)` (radio ya almacenado en metros).
  - `type === 'polygon'`: área de `PolygonHelper::getArea()` (grados²) convertida a m² usando `111320` m/grado de latitud y `111320 * cos(deg2rad($lat))` m/grado de longitud, donde `$lat` es la latitud del centro de la geocerca (`getCenter()`).
  - Si no se puede calcular (sin vértices/coordenadas), retorna `null`.
- `static pickBySpeedLimitPriority(iterable $geofences): ?self`
  - Filtra geocercas con `speed_limit` no nulo.
  - Ordena por `approxAreaSqMeters()` ascendente; las de área `null` van al final (nunca ganan el desempate).
  - Retorna la primera (más específica) o `null` si no hay candidatas.

### `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php` (904)

- `getInputParameters()`: agrega `\Field::checkbox('only_violations', 'Mostrar solo excesos de velocidad', true)`.
- `beforeGenerate()` (override): carga `$this->geofences = Geofence::where('user_id', $this->user->id)->where('active', 1)->whereNotNull('speed_limit')->get()`.
- `processPosition($position): array` (cambia de retornar `?array` a `array`):
  1. Filtra `$this->geofences` a las que contienen el punto (`pointIn`).
  2. Separa las que exceden (`position.speed > geofence.speed_limit`) de las que no.
  3. Si hay excedidas: una fila por cada una.
  4. Si no hay excedidas y `only_violations` es `false`: una fila usando `Geofence::pickBySpeedLimitPriority()` sobre las geocercas que contienen el punto.
  5. Si no hay excedidas y `only_violations` es `true`: array vacío.
- `generateDevice()`: cambia `$rows[] = $processed` por `array_push($rows, ...$processed)` (o `array_merge`), ya que `processPosition` ahora retorna un array.

### `Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php` (nuevo)

Calco de `AppendSpeedLimitGeofence` (mismo `required()`: `[AppendGeofences::class]`), pero en `getSpeedLimit()`:

```php
protected function getSpeedLimit($position)
{
    if (empty($position->geofences))
        return null;

    $candidates = collect($this->history->getGeofences())
        ->whereIn('id', $position->geofences)
        ->filter(fn ($g) => !empty($g->speed_limit));

    $geofence = Geofence::pickBySpeedLimitPriority($candidates);

    return $geofence->speed_limit ?? null;
}
```

### `Tobuli/History/Actions/AppendOverspeeding.php`

En `after()`, agregar `AppendSpeedLimitGeofenceByPriority::class` al array existente (junto a `AppendSpeedLimitStatic`, `AppendSpeedLimitRoads`, `AppendSpeedLimitGeofence`, `AppendSpeedLimitGeofenceMulti`). Cambio puramente aditivo: solo afecta el orden de ejecución si un reporte incluye explícitamente la nueva clase en su `getActionsList()`, lo cual hoy solo harán 905 y 906.

### `OverspeedsInGeofenceCustomReportV2.php` (905) y `OverspeedsInGeofenceCustomReportBuenaventura.php` (906)

En `getActionsList()`, reemplazar `AppendSpeedLimitGeofence::class` por `AppendSpeedLimitGeofenceByPriority::class`.

### Sin cambios

`RagGeofencesReport`, `AppendSpeedLimitGeofence` (clase original), alertas en tiempo real, y el resto de los 95 tipos de reporte nativos.

## Manejo de errores

- Geocerca sin coordenadas válidas → `approxAreaSqMeters()` retorna `null` → tratada como "área desconocida" en `pickBySpeedLimitPriority`, nunca gana el desempate frente a una geocerca con área calculable.
- Si ninguna geocerca candidata tiene `speed_limit`, comportamiento igual al actual: no se genera fila/no se setea `speed_limit`.

## Testing

No existen tests previos para reportes en este proyecto. Se agregan tests unitarios (sin BD, instanciando `Geofence` directamente con atributos):

- `Geofence::approxAreaSqMeters()`: círculo, polígono, geocerca sin coordenadas (`null`).
- `Geofence::pickBySpeedLimitPriority()`: desempate por área, geocercas sin `speed_limit` excluidas, geocercas con área `null` al final.
- Lógica de filas de 904 (`processPosition` o un método extraído y testeable que reciba `$point`, `$speed`, `$geofences`, `$onlyViolations`): caso de exceso múltiple → N filas; caso sin exceso con `only_violations=true` → 0 filas; caso sin exceso con `only_violations=false` → 1 fila por prioridad de área.
