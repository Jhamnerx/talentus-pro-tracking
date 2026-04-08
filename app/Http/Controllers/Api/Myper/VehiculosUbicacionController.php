<?php

namespace App\Http\Controllers\Api\Myper;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VehiculosUbicacionController extends Controller
{
    /**
     * POST api/v1/myper/ubicacion
     *
     * Retorna la posición actual de un listado de vehículos por número de placa.
     * Los datos de posición deben tener como máximo 60 segundos de antigüedad.
     */
    public function ubicacion(Request $request): JsonResponse
    {
        $items = $request->input('items');

        if (! is_array($items) || empty($items)) {
            return response()->json([
                'responseCode' => 400,
                'message'      => 'Error en la obtención de datos',
                'items'        => [],
                'errors'       => ['El campo items es requerido y debe ser un arreglo no vacío'],
            ], 400);
        }

        // Extraer y sanitizar las placas del request
        $placas = [];
        foreach ($items as $item) {
            if (isset($item['Placa']) && ! empty(trim((string) $item['Placa']))) {
                $placas[] = trim((string) $item['Placa']);
            }
        }

        $placas = array_unique($placas);

        if (empty($placas)) {
            return response()->json([
                'responseCode' => 400,
                'message'      => 'Error en la obtención de datos',
                'items'        => [],
                'errors'       => ['Ninguna placa válida fue proporcionada en el arreglo items'],
            ], 400);
        }

        /** @var \Tobuli\Entities\User $user */
        $user = Auth::user();

        // Obtener los dispositivos del usuario que coincidan con las placas
        $devices = $user->devices()
            ->with('traccar')
            ->whereIn('plate_number', $placas)
            ->get()
            ->keyBy('plate_number');

        $result = [];
        $errors = [];

        foreach ($placas as $placa) {
            if (! isset($devices[$placa])) {
                $errors[] = "Placa no encontrada: {$placa}";
                continue;
            }

            /** @var \Tobuli\Entities\Device $device */
            $device = $devices[$placa];

            if (! $device->traccar) {
                $errors[] = "Sin datos GPS para la placa: {$placa}";
                continue;
            }

            $lat = $device->traccar->lastValidLatitude ?? null;
            $lng = $device->traccar->lastValidLongitude ?? null;

            if (empty($lat) && empty($lng)) {
                $errors[] = "Sin coordenadas disponibles para la placa: {$placa}";
                continue;
            }

            // Verificar la antigüedad del último dato (máximo 60 segundos)
            $serverTime = $device->traccar->server_time
                ? strtotime($device->traccar->server_time)
                : 0;

            $age = $serverTime > 0 ? (time() - $serverTime) : PHP_INT_MAX;

            if ($age > 60) {
                $errors[] = "Datos desactualizados para la placa: {$placa} (último registro hace {$age} segundos)";
                continue;
            }

            $result[] = [
                'Placa'     => $placa,
                'Latitud'   => (float) $lat,
                'Longitud'  => (float) $lng,
                'Velocidad' => (float) ($device->traccar->speed ?? 0),
            ];
        }

        return response()->json([
            'responseCode' => 200,
            'message'      => '',
            'items'        => $result,
            'errors'       => $errors,
        ], 200);
    }

    /**
     * POST api/v1/myper/sandbox/ubicacion
     *
     * Endpoint de pruebas (sandbox). No requiere autenticación real.
     * Retorna datos simulados para validar la integración.
     * Las placas no reconocidas se reportan en errors.
     */
    public function sandbox(Request $request): JsonResponse
    {
        $items = $request->input('items');

        if (! is_array($items) || empty($items)) {
            return response()->json([
                'responseCode' => 400,
                'message'      => 'Error en la obtención de datos',
                'items'        => [],
                'errors'       => ['El campo items es requerido y debe ser un arreglo no vacío'],
            ], 400);
        }

        // Datos ficticios indexados por placa (Lima, Perú)
        $catalogo = [
            'ABC-123' => ['lat' => -12.046374, 'lng' => -77.042793, 'speed' => 45.0],
            'CDE-456' => ['lat' => -12.083261, 'lng' => -77.054961, 'speed' =>  0.0],
            'EFG-789' => ['lat' => -12.121500, 'lng' => -76.990100, 'speed' => 72.5],
            'HIJ-000' => ['lat' => -11.990000, 'lng' => -77.110000, 'speed' => 30.0],
            'KLM-321' => ['lat' => -12.005000, 'lng' => -77.020000, 'speed' =>  0.0],
        ];

        $result = [];
        $errors = [];

        foreach ($items as $item) {
            $placa = isset($item['Placa']) ? trim((string) $item['Placa']) : '';

            if (empty($placa)) {
                continue;
            }

            if (! isset($catalogo[$placa])) {
                $errors[] = "Placa no encontrada en sandbox: {$placa}";
                continue;
            }

            $data = $catalogo[$placa];

            $result[] = [
                'Placa'     => $placa,
                'Latitud'   => $data['lat'],
                'Longitud'  => $data['lng'],
                'Velocidad' => $data['speed'],
            ];
        }

        return response()->json([
            'responseCode' => 200,
            'message'      => '[SANDBOX] Datos simulados. No usar en producción.',
            'items'        => $result,
            'errors'       => $errors,
        ], 200);
    }
}
