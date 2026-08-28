<?php

namespace Tobuli\Services;

use Formatter;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Helpers\PolygonHelper;

/**
 * Unidades que dejaron de transmitir dentro de una zona dibujada en el mapa.
 *
 * La pregunta que responde es "¿qué tengo callado ahí dentro?": se toma la
 * ÚLTIMA posición conocida de cada vehículo y se mira si cae dentro del
 * polígono y si su última transmisión es más vieja que el umbral. No se
 * recorre el historial: un equipo que dejó de transmitir se queda clavado en
 * su último punto, que es justo el que interesa localizar.
 *
 * La zona puede ser un POLÍGONO o un CÍRCULO. El círculo no se convierte a
 * polígono: se compara la distancia al centro con el radio, igual que hace
 * Geofence::pointInCircle con las geocercas circulares. Aproximarlo por
 * vértices metería un error en los bordes que aquí no hace falta pagar.
 *
 * El filtro se hace en PHP y no en SQL a propósito: la zona la dibuja el
 * usuario al vuelo, no está guardada, y meterla en la consulta obligaría a
 * construir un ST_Contains con un WKT armado a mano por cada petición. Con
 * ~1.400 vehículos el coste del recorrido es despreciable frente a la
 * consulta, y antes de la prueba fina hay un descarte por caja envolvente.
 */
class SilentDevicesInZoneService
{
    /** Umbral por defecto si no se pide otro: sin transmitir hace 2 horas. */
    public const MINUTOS_POR_DEFECTO = 120;

    /** Tope de filas devueltas, para que un polígono enorme no tumbe la vista. */
    public const TOPE = 1000;

    /**
     * @param array $zona    ['polygon' => [['lat','lng'], …]]
     *                       o ['circle' => ['lat','lng','radius' => metros]]
     * @param int   $minutos Sin transmitir durante al menos estos minutos
     *
     * @return array{items:array,total:int,acotado:bool,corte:string}
     */
    public function buscar(User $user, array $zona, int $minutos): array
    {
        $vacio = ['items' => [], 'total' => 0, 'acotado' => false, 'corte' => ''];

        $dentro = $this->comprobadorDeZona($zona);

        if (! $dentro) {
            return $vacio;
        }

        $minutos = max(0, $minutos);
        $corte = date('Y-m-d H:i:s', strtotime('-' . $minutos . ' minutes'));

        $devices = $user->devices()
            ->clearOrdersBy()
            // Solo vehiculos habilitados. Uno dado de baja no transmite por
            // definicion, y listarlo aqui seria ruido que tapa a los que si
            // deberian estar reportando.
            //
            // Es `devices.active` (el vehiculo esta de alta), NO
            // `user_device_pivot.active`, que es la casilla del mapa de cada
            // usuario: filtrar por esa esconderia vehiculos que solo estan
            // destildados de la vista.
            ->where('devices.active', 1)
            ->with(['traccar', 'users:id,email'])
            ->get();

        $items = [];

        foreach ($devices as $device) {
            $traccar = $device->traccar;

            if (! $traccar) {
                continue;
            }

            $lat = $traccar->lastValidLatitude;
            $lng = $traccar->lastValidLongitude;

            // Sin última posición no se puede situar en la zona.
            if ($lat === null || $lng === null || (! $lat && ! $lng)) {
                continue;
            }

            if (! $dentro($lat, $lng)) {
                continue;
            }

            // Nunca transmitió: cuenta como callado, y se dice.
            $ultima = $traccar->time;

            if ($ultima && $ultima > $corte) {
                continue;
            }

            $items[] = [
                'id'       => (int) $device->id,
                'name'     => (string) $device->name,
                'plate'    => (string) ($device->plate_number ?? ''),
                'imei'     => (string) $device->imei,
                'lat'      => round((float) $lat, 6),
                'lng'      => round((float) $lng, 6),
                'users'    => $device->users->pluck('email')->implode(', '),
                'last_at'  => $ultima ? Formatter::time()->human($ultima) : null,
                'silent_for' => $ultima ? $this->desde($ultima) : null,
            ];
        }

        // El que lleva más tiempo callado primero: es por el que se pregunta.
        usort($items, function ($a, $b) {
            return strcmp((string) $a['last_at'], (string) $b['last_at']);
        });

        $total = count($items);

        return [
            'items'   => array_slice($items, 0, self::TOPE),
            'total'   => $total,
            'acotado' => $total > self::TOPE,
            'corte'   => Formatter::time()->human($corte),
        ];
    }

    /**
     * Devuelve una función que dice si un punto cae en la zona, o null si la
     * zona no es utilizable. Se resuelve una vez y no por vehículo: el tipo
     * de zona no cambia a mitad del recorrido.
     *
     * @return callable(float,float):bool|null
     */
    private function comprobadorDeZona(array $zona): ?callable
    {
        $circulo = $zona['circle'] ?? null;

        if (is_array($circulo)) {
            $lat = $circulo['lat'] ?? null;
            $lng = $circulo['lng'] ?? null;
            $radio = $circulo['radius'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lng) || ! is_numeric($radio) || $radio <= 0) {
                return null;
            }

            $lat = (float) $lat;
            $lng = (float) $lng;
            $radio = (float) $radio;

            // Grados de latitud por metro para el descarte previo; en
            // longitud se corrige por el coseno, que estrecha con la latitud.
            $margenLat = $radio / 111320;
            $cos = max(0.01, cos(deg2rad($lat)));
            $margenLng = $radio / (111320 * $cos);

            return function ($pLat, $pLng) use ($lat, $lng, $radio, $margenLat, $margenLng) {
                if (abs($pLat - $lat) > $margenLat || abs($pLng - $lng) > $margenLng) {
                    return false;
                }

                // getDistance devuelve kilómetros (mismo uso que Geofence).
                return getDistance($lat, $lng, $pLat, $pLng) * 1000 <= $radio;
            };
        }

        $poligono = $this->limpiarPoligono($zona['polygon'] ?? []);

        // Un polígono necesita tres vértices; con menos no encierra nada.
        if (count($poligono) < 3) {
            return null;
        }

        $helper = new PolygonHelper($poligono);
        $caja = $this->cajaEnvolvente($poligono);

        return function ($pLat, $pLng) use ($helper, $caja) {
            // Descarte barato antes del test fino.
            if ($pLat < $caja['minLat'] || $pLat > $caja['maxLat']
                || $pLng < $caja['minLng'] || $pLng > $caja['maxLng']) {
                return false;
            }

            return false !== $helper->pointInPolygon(['lat' => $pLat, 'lng' => $pLng]);
        };
    }

    /** Vértices utilizables: numéricos y dentro de rango. */
    private function limpiarPoligono(array $poligono): array
    {
        $salida = [];

        foreach ($poligono as $v) {
            $lat = is_array($v) ? ($v['lat'] ?? null) : null;
            $lng = is_array($v) ? ($v['lng'] ?? null) : null;

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $lat = (float) $lat;
            $lng = (float) $lng;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }

            $salida[] = ['lat' => $lat, 'lng' => $lng];
        }

        return $salida;
    }

    private function cajaEnvolvente(array $poligono): array
    {
        $lats = array_column($poligono, 'lat');
        $lngs = array_column($poligono, 'lng');

        return [
            'minLat' => min($lats), 'maxLat' => max($lats),
            'minLng' => min($lngs), 'maxLng' => max($lngs),
        ];
    }

    /** "3 h 20 min" — cuánto lleva sin transmitir, en texto corto. */
    private function desde(string $ultima): string
    {
        $segundos = max(0, time() - strtotime($ultima));
        $dias = (int) floor($segundos / 86400);
        $horas = (int) floor(($segundos % 86400) / 3600);
        $minutos = (int) floor(($segundos % 3600) / 60);

        if ($dias) {
            return $dias . ' d ' . $horas . ' h';
        }

        return $horas ? $horas . ' h ' . $minutos . ' min' : $minutos . ' min';
    }
}
