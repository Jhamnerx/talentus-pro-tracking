<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use Tobuli\Entities\User;

/**
 * Puntuación de conducción: eventos de riesgo por cada 100 km.
 * ---------------------------------------------------------------------
 * Fuente ÚNICA del cálculo. Lo usan el reporte de Conducción (tipo 907) y
 * el ranking del panel; si cada uno clasificara o ponderara a su manera
 * darían números distintos para el mismo vehículo y el mismo periodo, que
 * es justo lo que hace desconfiar del dato.
 *
 * Reconoce los eventos por DOS caminos, porque conviven:
 *  - los tipos nuevos (harsh_braking, accident…), que escribe el tipo de
 *    alerta "Alarma del equipo" leyendo el `alarm` normalizado de Traccar;
 *  - y el TEXTO de los eventos personalizados de siempre ("Giro Brusco",
 *    "Frenado Brusco"…), de los que hay decenas de miles acumulados.
 */
class DrivingScoreService
{
    /**
     * Conductas vigiladas: textos equivalentes de los eventos
     * personalizados y cuánto pesa cada una en la puntuación.
     *
     * El peso es la gravedad relativa: un choque no es un frenado brusco.
     */
    public const CONDUCTAS = [
        'harsh_acceleration' => [
            'titulo' => 'Aceleración brusca',
            'textos' => ['aceleracion brusca', 'harsh acceleration'],
            'peso'   => 1,
        ],
        'harsh_braking' => [
            'titulo' => 'Frenado brusco',
            'textos' => ['frenado brusco', 'harsh braking'],
            'peso'   => 1,
        ],
        'harsh_cornering' => [
            'titulo' => 'Giro brusco',
            'textos' => ['giro brusco', 'harsh cornering'],
            'peso'   => 1,
        ],
        'high_rpm' => [
            'titulo' => 'RPM elevadas',
            'textos' => ['rpm elevadas', 'high rpm'],
            'peso'   => 1,
        ],
        'overspeed' => [
            'titulo' => 'Exceso de velocidad',
            'textos' => ['exceso de velocidad', 'exceso de velocidad sutran', 'overspeed'],
            'peso'   => 2,
        ],
        // Aparte del exceso general: la geocerca suele tener un límite más
        // estricto y el cliente lo audita por separado. Como conducta propia
        // y no sumada a la anterior, para no contar dos veces el mismo exceso.
        'geofence_overspeed' => [
            'titulo' => 'Exceso en geocerca',
            'textos' => ['exceso de velocidad en geocerca', 'exceso de velocidad geocerca'],
            'peso'   => 2,
        ],
        'accident' => [
            'titulo' => 'Detección de choque',
            'textos' => ['deteccion de choque', 'choque', 'accidente'],
            'peso'   => 10,
        ],
    ];

    /**
     * Un vehículo que apenas se movió no entra en el ranking de los mejores:
     * cero eventos en 400 metros no es buena conducción, es estar parado.
     */
    public const KM_MINIMO = 10;

    /** Tope de vehículos a los que se les calcula kilometraje en el panel. */
    public const TOPE_RANKING = 150;

    /** @var array<string,string>|null */
    private static $cacheTitulos = null;

    /** @var array<int,array{0:string,1:string}>|null */
    private static $cacheTextos = null;

    /**
     * Título de cada conducta con el mismo texto que el resto de la
     * plataforma le da a ese tipo de evento. El de CONDUCTAS es solo el
     * respaldo por si el catálogo no lo tiene.
     *
     * @return array<string,string>
     */
    public static function titulos(): array
    {
        if (self::$cacheTitulos !== null) {
            return self::$cacheTitulos;
        }

        $catalogo = Event::getTypeTitles()->pluck('title', 'type')->all();

        $titulos = [];

        foreach (self::CONDUCTAS as $clave => $conducta) {
            $titulos[$clave] = $catalogo[$clave] ?? $conducta['titulo'];
        }

        return self::$cacheTitulos = $titulos;
    }

    /** Conteo en cero de todas las conductas. */
    public static function conteoVacio(): array
    {
        return array_fill_keys(array_keys(self::CONDUCTAS), 0);
    }

    /**
     * ¿A qué conducta corresponde este evento? Primero por su tipo (los
     * nuevos, que ya vienen normalizados), y si no, por el texto.
     *
     * Devuelve null si el evento no es de conducción.
     */
    public static function clasificar(?string $tipo, ?string $mensaje): ?string
    {
        $tipo = (string) $tipo;

        if (array_key_exists($tipo, self::CONDUCTAS)) {
            return $tipo;
        }

        $mensaje = self::normalizar((string) $mensaje);

        if ($mensaje === '') {
            return null;
        }

        // Se prueba el texto MÁS LARGO primero: "exceso de velocidad en
        // geocerca" tiene que ganarle a "exceso de velocidad", que es su
        // prefijo, sin depender del orden en que estén declaradas.
        foreach (self::textosPorLongitud() as [$texto, $clave]) {
            if ($mensaje === $texto) {
                return $clave;
            }

            // Prefijo, pero solo si termina en frontera de palabra: asi
            // "Frenado Brusco Nivel 2" cuenta y "Frenadobrusco" no.
            $largo = strlen($texto);

            if (strncmp($mensaje, $texto, $largo) === 0
                && ! preg_match('/^[a-z0-9]/', substr($mensaje, $largo, 1))) {
                return $clave;
            }
        }

        return null;
    }

    /** Suma de los eventos por su peso. */
    public static function ponderar(array $conteo): int
    {
        $total = 0;

        foreach ($conteo as $clave => $cantidad) {
            if (isset(self::CONDUCTAS[$clave])) {
                $total += $cantidad * self::CONDUCTAS[$clave]['peso'];
            }
        }

        return $total;
    }

    /**
     * Índice: eventos ponderados por cada 100 km. Menos es mejor.
     *
     * Sin kilometraje devuelve null y NO cero: un vehículo sin recorrido no
     * se puede comparar con los demás, y un cero fingido lo pondría el
     * primero del ranking de los mejores.
     */
    public static function indice(int $ponderado, float $km): ?float
    {
        if ($km <= 0) {
            return null;
        }

        return round($ponderado * 100 / $km, 2);
    }

    /**
     * Ranking de conducción de la flota del usuario en un rango.
     *
     * El coste está en el kilometraje, que se consulta vehículo a vehículo
     * (tabla de posiciones por dispositivo). Por eso el universo se acota:
     * entran todos los que tuvieron eventos de conducción —que son los que
     * pueden salir mal— y se rellena hasta TOPE_RANKING con los demás por
     * actividad reciente, para que los limpios también puedan encabezar.
     *
     * @param string $desde 'Y-m-d H:i:s' en zona de almacenamiento
     * @param string $hasta 'Y-m-d H:i:s' en zona de almacenamiento
     * @return array{peores:array,mejores:array,cubiertos:int,flota:int,acotado:bool,indice:float|null,km:float,ponderado:int}
     */
    public function ranking(
        User $user,
        string $desde,
        string $hasta,
        int $cuantos = 5,
        int $tope = self::TOPE_RANKING
    ): array {
        $flota = (int) $user->devices()->count();

        $vacio = [
            'peores' => [], 'mejores' => [], 'cubiertos' => 0, 'flota' => $flota,
            'acotado' => false, 'indice' => null, 'km' => 0.0, 'ponderado' => 0,
        ];

        $porVehiculo = $this->eventosPorVehiculo($user, $desde, $hasta);

        // Los eventos accesibles pueden venir de alertas compartidas sobre
        // vehículos que NO son de este usuario: el ranking es de SU flota.
        $suyos = $user->devices()->clearOrdersBy()->pluck('devices.id')->all();
        $conEventos = array_values(array_intersect(array_keys($porVehiculo), $suyos));

        // Relleno: vehículos sin eventos de conducción, por actividad
        // reciente, para que los limpios también puedan encabezar.
        $faltan = max(0, $tope - count($conEventos));
        $relleno = [];

        if ($faltan > 0) {
            $relleno = $user->devices()
                ->clearOrdersBy()
                ->when($conEventos, function ($q) use ($conEventos) {
                    $q->whereNotIn('devices.id', $conEventos);
                })
                ->orderBy('devices.updated_at', 'desc')
                ->limit($faltan)
                ->pluck('devices.id')
                ->all();
        }

        $ids = array_slice(array_merge($conEventos, $relleno), 0, $tope);

        if (! $ids) {
            return $vacio;
        }

        $kilometraje = new DeviceMileageService();

        $filas = [];

        Device::whereIn('devices.id', $ids)
            ->get(['devices.id', 'devices.name', 'devices.plate_number', 'devices.traccar_device_id'])
            ->each(function ($device) use (&$filas, $porVehiculo, $kilometraje, $desde, $hasta) {
                $conteo = $porVehiculo[$device->id]['conteo'] ?? self::conteoVacio();
                $ponderado = self::ponderar($conteo);
                $km = $kilometraje->forRange($device, $desde, $hasta);

                $filas[] = [
                    'id'        => (int) $device->id,
                    'name'      => (string) $device->name,
                    'plate'     => (string) $device->plate_number,
                    'km'        => round($km, 2),
                    'events'    => array_sum($conteo),
                    'weighted'  => $ponderado,
                    'index'     => self::indice($ponderado, $km),
                    'breakdown' => $conteo,
                ];
            });

        // Solo compiten los que tienen kilometraje: sin recorrido no hay
        // nada que comparar.
        $conKm = array_values(array_filter($filas, function ($f) {
            return $f['index'] !== null;
        }));

        // El índice de la flota se calcula SOLO sobre los que tienen
        // kilometraje. Sumar los eventos de vehículos cuyo recorrido no se
        // pudo medir sobre unos kilómetros que no son suyos infla el número.
        $kmTotal = 0.0;
        $ponderadoTotal = 0;

        foreach ($conKm as $fila) {
            $kmTotal += $fila['km'];
            $ponderadoTotal += $fila['weighted'];
        }

        // Peores: los que SÍ tuvieron incidencias. Encabezar el ranking de
        // los peores con un vehículo de cero eventos no diría nada.
        $peores = array_values(array_filter($conKm, function ($f) {
            return $f['weighted'] > 0;
        }));
        usort($peores, function ($a, $b) {
            return $b['index'] <=> $a['index'];
        });

        // Mejores: los que recorrieron de verdad. Cero eventos en 400 metros
        // no es buena conducción, es estar parado.
        $mejores = array_values(array_filter($conKm, function ($f) {
            return $f['km'] >= self::KM_MINIMO;
        }));
        usort($mejores, function ($a, $b) {
            return [$a['index'], -$a['km']] <=> [$b['index'], -$b['km']];
        });

        return [
            'peores'    => array_slice($peores, 0, $cuantos),
            'mejores'   => array_slice($mejores, 0, $cuantos),
            'cubiertos' => count($conKm),
            'flota'     => $flota,
            // Se dice cuando el ranking no abarca toda la flota, para que
            // nadie lea "el mejor de la flota" donde dice "de los medidos".
            'acotado'   => $flota > count($filas),
            'indice'    => self::indice($ponderadoTotal, $kmTotal),
            'km'        => round($kmTotal, 2),
            'ponderado' => $ponderadoTotal,
        ];
    }

    /**
     * Eventos de conducción agrupados por vehículo. Una sola consulta
     * agrupada por (device_id, type, message): la clasificación se hace
     * en PHP porque los eventos personalizados solo se distinguen por su
     * texto, que no es una columna indexable por conducta.
     */
    private function eventosPorVehiculo(User $user, string $desde, string $hasta): array
    {
        // Mismo criterio de acceso que el reporte de Eventos: un evento no
        // es solo del dueño, también lo ve quien comparte la alerta.
        $filas = Event::whereBetween('time', [$desde, $hasta])
            ->userAccessible($user)
            ->selectRaw('device_id, type, message, COUNT(*) AS n')
            ->groupBy('device_id', 'type', 'message')
            ->get();

        $porVehiculo = [];

        foreach ($filas as $fila) {
            $clave = self::clasificar($fila->type, $fila->message);

            if ($clave === null) {
                continue;
            }

            $id = (int) $fila->device_id;

            if (! isset($porVehiculo[$id])) {
                $porVehiculo[$id] = ['conteo' => self::conteoVacio()];
            }

            $porVehiculo[$id]['conteo'][$clave] += (int) $fila->n;
        }

        return $porVehiculo;
    }

    /**
     * Pares [texto, conducta] ordenados de más largo a más corto, para que
     * el texto más específico gane siempre.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function textosPorLongitud(): array
    {
        if (self::$cacheTextos !== null) {
            return self::$cacheTextos;
        }

        $pares = [];

        foreach (self::CONDUCTAS as $clave => $conducta) {
            foreach ($conducta['textos'] as $texto) {
                $pares[] = [self::normalizar($texto), $clave];
            }
        }

        usort($pares, function ($a, $b) {
            return strlen($b[0]) <=> strlen($a[0]);
        });

        return self::$cacheTextos = $pares;
    }

    /** Sin acentos, en minúsculas y sin espacios de más: cada cliente escribe distinto. */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', $texto);
    }
}
