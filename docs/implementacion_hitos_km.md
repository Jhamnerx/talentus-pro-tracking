# Implementación de Hitos Kilométricos — TalentusPro

> ## ✅ IMPLEMENTADO (21-jul-2026) — con 2 ajustes sobre este plan
>
> 1. **SIN SQL espacial**: el plan proponía `POINT SRID 4326` + `SPATIAL INDEX`
>    (sintaxis solo-MySQL 8; el dedicado corre **MariaDB 10.5**). Con 31.7k
>    filas, `lat/lon DECIMAL` + índices BTREE responde el bbox en ms y es
>    portable. La distancia del "km más cercano" se calcula con haversine en
>    PHP tras un pre-filtro bbox ±0.05°.
> 2. **Carga vía artisan** (no `LOAD DATA LOCAL INFILE`, usualmente
>    deshabilitado): `php artisan hitos:import` — idempotente POR VERSIÓN
>    (`--dataset=2026-01` para la actualización semestral, sin downtime).
>
> **Piezas**: migración `hitos_km` + entidad `Tobuli\Entities\HitoKm`
> (scope `current()` = versión más reciente) + `ImportHitosKmCommand` ·
> endpoints `ui.v2.api.hitos` (bbox+zoom, densidad 1/10/100, Cache-Control
> 86400) y `ui.v2.api.hitos.nearest` (≤2 km o nada) en `MapLayersController` ·
> capa compartida **`tp-hitos.js`** (`tpHitos.create(map)` +
> `tpHitos.nearest(lat,lng)`, debounce 300ms en moveend/zoomend, guard de
> respuestas obsoletas, config en `#tp-hitos-config` del layout) · CSS
> `.tp-hito-pin`/`.tp-hito-ref` en components.css · **toggle en las 3
> páginas con mapa**: Live (persistido en `user->map_controls.m_hitos` — clave
> añadida al whitelist legacy de `changeMapSettings` — y expuesto en
> `mapConfig.toggles.hitos`; se siembra en `seedOverlays()` tras el load de
> devices), Historial (`data-display="hitos"`, estado inicial del mapConfig) y
> Eventos (`tp-ev-toggle-hitos`) · **referencia vial en popups** ("PE-1N ·
> km 557") en el popup de vehículo de Live y el popup de punto de Historial,
> solo con el toggle activo (caché por coordenada en el cliente) ·
> i18n `front.show_km_markers` en 59 idiomas.
>
> Importado: **31,723 hitos** (versión 2025-07). El plan original queda abajo
> como referencia del diseño.
>
> **2ª entrega — nombres legibles de vías** (`storage/rutas_nombres.csv`,
> 196 vías): tabla **`rutas_viales`** (ruta PK, nombre_comun, trayecto_corto —
> se llama así y no `rutas` para no chocar con la tabla `routes` de rutas de
> usuario). Se carga en el mismo `hitos:import` (`--names`), con dedupe por
> normalización Unicode NFC (el CSV traía "PE-1NÑ" con Ñ compuesta y
> descompuesta) + `insertOrIgnore`. Los endpoints hacen LEFT JOIN y exponen
> `via = COALESCE(NULLIF(nombre_comun,''), NULLIF(trayecto_corto,''), ruta)`.
> UX de dos niveles (el código oficial sigue siendo el de los hitos físicos,
> SUTRAN y partes policiales): tooltip del pin = **"Panamericana Norte ·
> km 557"** grande + "PE-1N" pequeño debajo; referencia en popups =
> "Panamericana Norte · km 557 (PE-1N)".

## 1. Base de datos (MySQL 8 / MariaDB con índice espacial)

```sql
CREATE TABLE hitos_km (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ruta VARCHAR(10) NOT NULL,
  km SMALLINT UNSIGNED NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  departamento VARCHAR(40),
  estado VARCHAR(20),
  superficie VARCHAR(20),
  version VARCHAR(10) DEFAULT '2025-07',
  geom POINT SRID 4326 NOT NULL,
  UNIQUE KEY uq_ruta_km (ruta, km, version),
  KEY idx_km_mod (km),
  SPATIAL INDEX sp_geom (geom)
) ENGINE=InnoDB;
```

Carga desde el CSV (ajusta la ruta del archivo):

```sql
LOAD DATA LOCAL INFILE 'hitos_km_peru_2025.csv'
INTO TABLE hitos_km
FIELDS TERMINATED BY ',' IGNORE 1 LINES
(ruta, km, lat, lon, departamento, estado, superficie)
SET geom = ST_SRID(POINT(lon, lat), 4326);
```

> Si usas PostgreSQL/PostGIS: `geom GEOMETRY(Point,4326)` + `CREATE INDEX ... USING GIST(geom)` y las mismas consultas con `ST_MakeEnvelope`.

## 2. Endpoint por viewport (Laravel — adáptalo a tu framework)

`GET /api/hitos?bbox=lonMin,latMin,lonMax,latMax&zoom=12`

```php
public function hitos(Request $r)
{
    [$w, $s, $e, $n] = array_map('floatval', explode(',', $r->query('bbox')));
    $zoom = (int) $r->query('zoom', 10);

    // densidad según zoom
    $mod = $zoom >= 12 ? 1 : ($zoom >= 9 ? 10 : 100);

    $rows = DB::select("
        SELECT ruta, km, lat, lon, estado
        FROM hitos_km
        WHERE MBRContains(
            ST_SRID(ST_MakeEnvelope(POINT(?,?), POINT(?,?)), 4326), geom)
          AND (km % ?) = 0
        LIMIT 4000", [$w, $s, $e, $n, $mod]);

    return response()->json($rows)
        ->header('Cache-Control', 'public, max-age=86400'); // datos semestrales: cachea fuerte
}
```

Bonus — km más cercano a un vehículo (para el popup del device: "PE-1N km 557"):

```sql
SELECT ruta, km, ST_Distance_Sphere(geom, ST_SRID(POINT(?,?),4326)) AS d_m
FROM hitos_km
WHERE MBRContains(ST_SRID(ST_MakeEnvelope(POINT(lon-0.05, lat-0.05), POINT(lon+0.05, lat+0.05)),4326), geom)
ORDER BY d_m LIMIT 1;  -- si d_m > 2000, el vehículo no está sobre red nacional
```

## 3. Frontend (Leaflet) — capa con toggle y recarga por movimiento

```js
const hitosLayer = L.layerGroup();
let hitosActivo = false,
  hitosTimer = null;

const hitoIcon = (txt) =>
  L.divIcon({
    className: "hito-km",
    html: `<div class="hito-pin">${txt}</div>`,
    iconSize: [34, 18],
    iconAnchor: [17, 9],
  });

async function cargarHitos() {
  if (!hitosActivo || map.getZoom() < 9) {
    hitosLayer.clearLayers();
    return;
  }
  const b = map.getBounds();
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
    .map((v) => v.toFixed(4))
    .join(",");
  const res = await fetch(`/api/hitos?bbox=${bbox}&zoom=${map.getZoom()}`);
  const datos = await res.json();
  hitosLayer.clearLayers();
  datos.forEach((h) => {
    L.marker([h.lat, h.lon], { icon: hitoIcon(`${h.km}`), interactive: true })
      .bindTooltip(`${h.ruta} km ${h.km}`, { direction: "top" })
      .addTo(hitosLayer);
  });
}

// recarga con debounce al mover/zoomear
map.on("moveend zoomend", () => {
  clearTimeout(hitosTimer);
  hitosTimer = setTimeout(cargarHitos, 300);
});

// conéctalo a tu toggle del panel derecho
function toggleHitos(on) {
  hitosActivo = on;
  on ? (map.addLayer(hitosLayer), cargarHitos()) : map.removeLayer(hitosLayer);
}
```

CSS sugerido (estilo hito vial):

```css
.hito-pin {
  background: #0b7a3b;
  color: #fff;
  border: 1px solid #fff;
  border-radius: 3px;
  font: 600 10px/16px sans-serif;
  text-align: center;
  padding: 0 3px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  white-space: nowrap;
}
```

## 4. Reglas de operación

- **Densidad**: zoom <9 apagado · 9–11 cada 10 km · ≥12 cada 1 km.
- **Cache**: los datos cambian por semestre → `max-age=86400` + versión en tabla; al actualizar (descarga semestral del servicio de Provías), inserta con `version='2026-01'` y cambia un flag, sin downtime.
- **Google Maps**: mismo endpoint; usa `AdvancedMarkerElement` con contenido HTML equivalente.
- **Móvil**: el mismo endpoint sirve; limita a `LIMIT 2000` si detectas cliente móvil.
