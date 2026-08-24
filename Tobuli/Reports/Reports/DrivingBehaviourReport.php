<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Tobuli\Entities\Event;
use Tobuli\Reports\DeviceReport;
use Tobuli\Services\DeviceMileageService;

/**
 * Conducción: eventos bruscos por cada 100 km.
 *
 * Cuenta por vehículo y no por conductor a proposito: en toda la base hay 9
 * conductores registrados y 14 lecturas de RFID, asi que un ranking por
 * conductor no tendria a quien rankear. El motor es el mismo el dia que haya
 * adopcion: solo cambia a quien se le atribuye el evento.
 *
 * Reconoce los eventos por DOS caminos, porque conviven:
 *  - los tipos nuevos (harsh_braking, accident...), que genera el tipo de
 *    alerta "Alarma del equipo" leyendo el `alarm` normalizado de Traccar;
 *  - y el TEXTO de los eventos personalizados de siempre ("Giro Brusco",
 *    "Frenado Brusco"...), que son 78.000 acumulados y no se pueden perder.
 */
class DrivingBehaviourReport extends DeviceReport
{
    const TYPE_ID = 907;

    // Este reporte no pinta posiciones ni direcciones: son opciones que solo
    // confundirian en el formulario.
    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'show_addresses', 'zones_instead'];

    /**
     * Conductas vigiladas: tipo de evento nuevo + textos equivalentes de los
     * eventos personalizados, y cuánto pesa cada una en la puntuación.
     */
    public const CONDUCTAS = [
        'harsh_acceleration' => [
            'titulo' => 'Aceleración brusca',
            'textos' => ['aceleracion brusca', 'aceleración brusca', 'harsh acceleration'],
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
            'textos' => ['exceso de velocidad', 'exceso de velocidad sutran'],
            'peso'   => 2,
        ],
        // Aparte del exceso general: la geocerca suele tener un limite mas
        // estricto y el cliente lo audita por separado. Como columna propia
        // y no sumado al anterior, para no contar dos veces el mismo exceso.
        'geofence_overspeed' => [
            'titulo' => 'Exceso en geocerca',
            'textos' => ['exceso de velocidad en geocerca'],
            'peso'   => 2,
        ],
        'accident' => [
            'titulo' => 'Detección de choque',
            'textos' => ['deteccion de choque', 'detección de choque'],
            'peso'   => 10,
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.driving_behaviour');
    }

    public function getInputParameters(): array
    {
        $parametros = [
            \Field::checkbox(
                'only_with_events',
                trans('front.driving_only_with_events'),
                true
            ),
        ];

        if ($this->user->isManager()) {
            $parametros[] = \Field::select('subusers', trans('front.subusers'), 0)
                ->setOptions([0 => trans('global.no'), 1 => trans('global.yes')])
                ->setValidation('in:0,1');
        }

        return $parametros;
    }

    protected function generateDevice($device)
    {
        // Mismo criterio de acceso que el reporte de Eventos: un evento no
        // es solo del dueno, tambien lo ve quien comparte la alerta.
        $eventos = Event::whereBetween('time', [$this->date_from, $this->date_to])
            ->where('device_id', $device->id)
            ->where(function ($query) {
                $subusuarios = $this->parameters['subusers'] ?? false;

                if ($subusuarios && $this->user->isManager()) {
                    $query->userControllable($this->user);
                } else {
                    $query->userAccessible($this->user);
                }
            })
            ->get(['type', 'message', 'time']);

        $conteo = array_fill_keys(array_keys(self::CONDUCTAS), 0);

        foreach ($eventos as $evento) {
            $clave = $this->clasificar($evento);

            if ($clave !== null) {
                $conteo[$clave]++;
            }
        }

        $total = array_sum($conteo);

        if (! $total && ! empty($this->parameters['only_with_events'])) {
            return null;
        }

        // Kilometraje del MISMO periodo, con la fuente unica del sistema
        $km = 0.0;
        try {
            $km = (float) (new DeviceMileageService())->forRange($device, $this->date_from, $this->date_to);
        } catch (\Throwable $e) {
            $km = 0.0;
        }

        // Puntuacion: eventos ponderados por cada 100 km. Menos es mejor.
        $ponderado = 0;
        foreach ($conteo as $clave => $n) {
            $ponderado += $n * self::CONDUCTAS[$clave]['peso'];
        }

        $indice = $km > 0 ? round($ponderado * 100 / $km, 2) : null;

        $fila = [
            'name'      => $device->name,
            'plate'     => $device->plate_number,
            'distance'  => Formatter::distance()->human($km),
            'total'     => $total,
            'weighted'  => $ponderado,
            // Sin kilometraje no se puede comparar: se dice, no se inventa un 0
            'index'     => $indice === null ? '—' : $indice,
        ];

        $titulos = self::titulos();

        foreach (self::CONDUCTAS as $clave => $conducta) {
            $fila[$clave] = $conteo[$clave];

            if (empty($this->totals[$titulos[$clave]])) {
                $this->totals[$titulos[$clave]] = 0;
            }

            $this->totals[$titulos[$clave]] += $conteo[$clave];
        }

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => [$fila],
            ],
        ];
    }

    /**
     * Título de cada conducta, con el mismo texto que el resto de la
     * plataforma le da a ese tipo de evento. El de CONDUCTAS es solo el
     * respaldo por si el catálogo no lo tiene.
     *
     * @return array<string,string>
     */
    public static function titulos(): array
    {
        $catalogo = Event::getTypeTitles()->pluck('title', 'type')->all();

        $titulos = [];

        foreach (self::CONDUCTAS as $clave => $conducta) {
            $titulos[$clave] = $catalogo[$clave] ?? $conducta['titulo'];
        }

        return $titulos;
    }

    /**
     * ¿A qué conducta corresponde este evento? Primero por su tipo (los
     * nuevos), y si no, por el texto (los personalizados de siempre).
     */
    private function clasificar($evento): ?string
    {
        $tipo = (string) $evento->type;

        if (array_key_exists($tipo, self::CONDUCTAS)) {
            return $tipo;
        }

        $mensaje = $this->normalizar((string) $evento->message);

        if ($mensaje === '') {
            return null;
        }

        foreach (self::CONDUCTAS as $clave => $conducta) {
            foreach ($conducta['textos'] as $texto) {
                if ($mensaje === $this->normalizar($texto)) {
                    return $clave;
                }
            }
        }

        return null;
    }

    /** Sin acentos, en minusculas y sin espacios de mas: los clientes escriben distinto. */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', $texto);
    }

    protected function toCSVData($file)
    {
        $cabecera = [
            trans('validation.attributes.name'),
            trans('validation.attributes.plate_number'),
            trans('global.distance'),
        ];

        foreach (self::titulos() as $titulo) {
            $cabecera[] = $titulo;
        }

        $cabecera[] = trans('front.driving_index');

        fputcsv($file, $cabecera);

        foreach ($this->getItems() as $item) {
            // Los vehiculos sin eventos vienen como item de 'error', sin tabla
            foreach ($item['table']['rows'] ?? [] as $fila) {
                $linea = [$fila['name'], $fila['plate'], $fila['distance']];

                foreach (array_keys(self::CONDUCTAS) as $clave) {
                    $linea[] = $fila[$clave];
                }

                $linea[] = $fila['index'];

                fputcsv($file, $linea);
            }
        }
    }
}
