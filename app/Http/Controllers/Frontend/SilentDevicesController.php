<?php namespace App\Http\Controllers\Frontend;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Http\Controllers\Controller;
use Tobuli\Lookups\Styler\ExcelCellStringifier;
use Tobuli\Services\SilentDevicesInZoneService;

/**
 * Herramienta de mapa "Unidades sin transmitir": el usuario dibuja una zona
 * —polígono o círculo— y se listan los vehículos cuya última posición cae
 * dentro y que llevan sin transmitir más de lo que indique.
 *
 * Se gatea con `devices.view`, que es el permiso con el que ya se ven esos
 * mismos vehículos en el mapa: la herramienta no enseña nada que el usuario
 * no pudiera mirar uno a uno.
 */
class SilentDevicesController extends Controller
{
    /** @var SilentDevicesInZoneService */
    private $servicio;

    public function __construct(SilentDevicesInZoneService $servicio)
    {
        parent::__construct();

        $this->servicio = $servicio;
    }

    public function search(): JsonResponse
    {
        $this->checkException('devices', 'view');

        $datos = $this->buscar();

        return Response::json([
            'status'  => 1,
            'items'   => $datos['items'],
            'total'   => $datos['total'],
            'acotado' => $datos['acotado'],
            'corte'   => $datos['corte'],
        ]);
    }

    /**
     * Mismo resultado en CSV. Se transmite en streaming para no armar en
     * memoria un fichero que puede llevar cientos de filas.
     */
    public function export(): StreamedResponse
    {
        $this->checkException('devices', 'view');

        $items = $this->buscar()['items'];
        $cabecera = array_values($this->columnas());

        return Response::stream(function () use ($cabecera, $items) {
            $salida = fopen('php://output', 'w');

            // BOM: sin el, Excel abre los acentos rotos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, $cabecera);

            foreach ($items as $i) {
                fputcsv($salida, array_values($this->fila($i)));
            }

            fclose($salida);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->nombreFichero() . '.csv"',
        ]);
    }

    /**
     * El mismo listado en XLSX. Reutiliza ExcelCellStringifier, que es el
     * exportador que ya usan los listados del sistema: fuerza todo a texto,
     * y sin eso Excel se come el cero inicial de un IMEI y convierte las
     * coordenadas en fechas según la configuración regional.
     */
    public function exportXlsx()
    {
        $this->checkException('devices', 'view');

        $filas = [];

        foreach ($this->buscar()['items'] as $i) {
            $filas[] = $this->fila($i);
        }

        // Sin filas no hay cabecera que deducir: se manda una vacía para que
        // el fichero salga con sus títulos igualmente.
        if (! $filas) {
            $filas[] = array_fill_keys(array_values($this->columnas()), '');
        }

        return Excel::download(
            new ExcelCellStringifier($filas),
            $this->nombreFichero() . '.xlsx',
            \Maatwebsite\Excel\Excel::XLSX
        )->deleteFileAfterSend(true);
    }

    /** Títulos de columna, en el orden en que salen. */
    private function columnas(): array
    {
        return [
            'name'    => trans('validation.attributes.name'),
            'plate'   => trans('validation.attributes.plate_number'),
            'imei'    => trans('validation.attributes.imei'),
            'lat'     => trans('front.latitude'),
            'lng'     => trans('front.longitude'),
            'users'   => trans('front.users'),
            'last_at' => trans('front.last_update'),
        ];
    }

    /** Una fila del listado, con los títulos como claves (los pide el XLSX). */
    private function fila(array $item): array
    {
        $fila = [];

        foreach ($this->columnas() as $clave => $titulo) {
            $fila[$titulo] = $item[$clave] ?? '';
        }

        return $fila;
    }

    private function nombreFichero(): string
    {
        return 'unidades_sin_transmitir_' . date('Ymd_His');
    }

    private function buscar(): array
    {
        $zona = Request::input('zone', []);

        if (is_string($zona)) {
            $zona = json_decode($zona, true) ?: [];
        }

        $minutos = Request::input('minutes', SilentDevicesInZoneService::MINUTOS_POR_DEFECTO);

        return $this->servicio->buscar($this->user, (array) $zona, (int) $minutos);
    }
}
