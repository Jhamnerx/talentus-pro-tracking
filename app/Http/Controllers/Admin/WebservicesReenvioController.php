<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tobuli\Entities\Device;
use Tobuli\Entities\Mininter;

class WebservicesReenvioController extends BaseController
{
    // ─── Helpers ────────────────────────────────────────────────────

    private function log(array &$logs, string $level, string $msg): void
    {
        $logs[] = ['level' => $level, 'msg' => '[' . now()->format('H:i:s') . '] ' . $msg];
    }

    /**
     * Obtiene el token del servicio del usuario autenticado (si tiene el servicio activo).
     */
    private function getServiceToken(Device $device, string $service): ?string
    {
        $authUser = \Auth::user();
        if (!$authUser) {
            return null;
        }
        $services = $authUser->services;
        if (
            isset($services[$service]['active']) && $services[$service]['active'] == 1
            && !empty($services[$service]['token'])
        ) {
            return $services[$service]['token'];
        }
        return null;
    }

    /**
     * Envía un lote de tramas al endpoint batch de Osinergmin.
     */
    private function sendOsinergminBatch(array $tramas): array
    {
        $client   = new Client(['verify' => false, 'timeout' => 60]);
        $response = $client->request('POST', 'https://prod.osinergmin-agent-2021.com/api/v1/trama-batch', [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $tramas,
        ]);
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    // ─── Vista principal ─────────────────────────────────────────────

    public function index()
    {
        $devicesSutran     = Device::where('mtc', 1)
            ->select('id', 'name', 'plate_number', 'imei')
            ->orderBy('name')
            ->get();

        $devicesOsinergmin = Device::where('osinergmin', 1)
            ->select('id', 'name', 'plate_number', 'imei')
            ->orderBy('name')
            ->get();

        $devicesMininter   = Device::where('mininter', 1)
            ->select('id', 'name', 'plate_number', 'imei')
            ->orderBy('name')
            ->get();

        $tz       = \Auth::user()->userTimezone;
        $nowUtc   = new \DateTime('now', new \DateTimeZone('UTC'));
        $nowUtc->modify($tz->zone);
        $nowUserTz = $nowUtc->format('Y-m-d\TH:i');

        return View::make('Admin.WebservicesReenvio.index', compact(
            'devicesSutran',
            'devicesOsinergmin',
            'devicesMininter',
            'nowUserTz'
        ));
    }

    // ─── Última posición de un dispositivo ──────────────────────────

    public function lastPosition($id): JsonResponse
    {
        $device = Device::with('traccar')->find((int) $id);

        if (!$device || !$device->traccar) {
            return response()->json(['error' => 'Unidad no encontrada'], 404);
        }

        $traccar = $device->traccar;
        $lat     = (float) $traccar->lastValidLatitude;
        $lng     = (float) $traccar->lastValidLongitude;

        if (!$lat && !$lng) {
            return response()->json(['error' => 'Sin posición disponible para esta unidad'], 404);
        }

        $timeStr = $traccar->device_time ?: $traccar->server_time;

        if (!$timeStr) {
            return response()->json(['error' => 'Sin tiempo GPS disponible'], 404);
        }

        $tz  = \Auth::user()->userTimezone;
        $utc = new \DateTime($timeStr, new \DateTimeZone('UTC'));
        $utc->modify($tz->zone);

        return response()->json([
            'lat'      => round($lat, 6),
            'lng'      => round($lng, 6),
            'datetime' => $utc->format('Y-m-d\TH:i'),
        ]);
    }

    // ─── SUTRAN ──────────────────────────────────────────────────────

    public function sutranEnviar(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'latitud'   => 'required|numeric|between:-90,90',
            'longitud'  => 'required|numeric|between:-180,180',
            'datetime'  => 'required|date',
        ]);

        $logs   = [];
        $device = Device::with('users')->findOrFail($request->device_id);
        $token  = $this->getServiceToken($device, 'sutran');

        if (!$token) {
            $this->log($logs, 'error', 'No se encontró token Sutran activo para este dispositivo.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $this->log($logs, 'info', "Dispositivo: {$device->name} | Placa: {$device->plate_number} | IMEI: {$device->imei}");
        $this->log($logs, 'info', 'Token: ' . substr($token, 0, 6) . '...');

        $plate    = trim(str_replace('-', '', $device->plate_number));
        $tz       = new DateTimeZone('America/Lima');
        $dt       = new DateTime($request->datetime, $tz);
        $ts       = $dt->getTimestamp();
        $gps_date = $dt->format('Y-m-d H:i:s');

        $course = (int) ($device->traccar->course ?? 0);

        $trama = [
            'id'          => $ts,
            'plate'       => $plate,
            'geo'         => [floatval($request->latitud), floatval($request->longitud)],
            'direction'   => $course,
            'event'       => 'PA',
            'speed'       => 0,
            'time_device' => $gps_date,
            'imei'        => intval($device->imei),
        ];

        $this->log($logs, 'info', 'Trama: ' . json_encode($trama));

        try {
            $client   = new Client(['verify' => false, 'timeout' => 30]);
            $response = $client->request('POST', 'https://ws03.sutran.gob.pe/api/v1.0/transmisiones', [
                'headers' => [
                    'access-token' => $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [$trama],
            ]);
            $body = $response->getBody()->getContents();
            $this->log($logs, 'success', 'Respuesta Sutran (' . $response->getStatusCode() . '): ' . $body);
            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $this->log($logs, 'error', "Error Sutran: $body");
            return response()->json(['success' => false, 'logs' => $logs]);
        } catch (\Exception $e) {
            $this->log($logs, 'error', 'Error inesperado: ' . $e->getMessage());
            return response()->json(['success' => false, 'logs' => $logs]);
        }
    }

    // ─── OSINERGMIN: Mantener transmisión ────────────────────────────

    public function osinergminMantener(Request $request): JsonResponse
    {
        $request->validate([
            'device_id'      => 'required|integer|exists:devices,id',
            'latitud'        => 'required|numeric|between:-90,90',
            'longitud'       => 'required|numeric|between:-180,180',
            'cantidad'       => 'required|integer|min:1|max:120',
            'desde_datetime' => 'required|date',
        ]);

        $logs   = [];
        $device = Device::with('users')->findOrFail($request->device_id);
        $token  = $this->getServiceToken($device, 'osinergmin');

        if (!$token) {
            $this->log($logs, 'error', 'No se encontró token Osinergmin activo para este dispositivo.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $n   = (int) $request->cantidad;
        $lat = floatval($request->latitud);
        $lng = floatval($request->longitud);

        // Parsear como hora Lima y convertir a UTC (igual que MantenertransmisionCommand)
        $tz      = new DateTimeZone('America/Lima');
        $desde   = new DateTime($request->desde_datetime, $tz);
        $desde->setTimezone(new DateTimeZone('UTC'));
        $tsDesde = $desde->getTimestamp();

        $this->log($logs, 'info', "Generando $n trama(s) para {$device->plate_number} desde " . gmdate('Y-m-d H:i', $tsDesde) . " UTC (c/1 min)...");

        $tramas = [];
        for ($i = 0; $i < $n; $i++) {
            $ts       = $tsDesde + ($i * 60);
            $tramas[] = [
                'event'      => 'none',
                'gpsDate'    => gmdate('Y-m-d\TH:i:s.v\Z', $ts),
                'plate'      => $device->plate_number,
                'speed'      => 0.0,
                'position'   => ['latitude' => $lat, 'longitude' => $lng, 'altitude' => 0.0],
                'tokenTrama' => $token,
                'odometer'   => 0,
                'uuid'       => (string) Str::uuid(),
                'imei'       => intval($device->imei),
            ];
        }

        $this->log($logs, 'info', count($tramas) . ' trama(s) preparadas. Enviando...');

        try {
            $result = $this->sendOsinergminBatch($tramas);
            $ok = $err = 0;
            foreach ($result['data'] ?? [] as $d) {
                if (($d['status'] ?? '') === 'CREATED') $ok++;
                elseif (($d['status'] ?? '') === 'ERROR') $err++;
            }
            $this->log($logs, 'success', "Resultado: CREATED={$ok}, ERROR={$err}");
            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $this->log($logs, 'error', "Error Osinergmin: $body");
            return response()->json(['success' => false, 'logs' => $logs]);
        } catch (\Exception $e) {
            $this->log($logs, 'error', 'Error inesperado: ' . $e->getMessage());
            return response()->json(['success' => false, 'logs' => $logs]);
        }
    }

    // ─── OSINERGMIN: Trama única con hora actual (para loop JS) ────────────

    public function osinergminMantenerTrama(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'latitud'   => 'required|numeric|between:-90,90',
            'longitud'  => 'required|numeric|between:-180,180',
            'datetime'  => 'nullable|date',
        ]);

        $device = Device::with('users')->findOrFail($request->device_id);
        $token  = $this->getServiceToken($device, 'osinergmin');

        if (!$token) {
            return response()->json(['success' => false, 'error' => 'No se encontró token Osinergmin activo.'], 422);
        }

        // Si se indica datetime (hora Lima) se convierte a UTC; si no, se usa la hora actual
        if ($request->filled('datetime')) {
            $tz = new DateTimeZone('America/Lima');
            $dt = new DateTime($request->datetime, $tz);
            $dt->setTimezone(new DateTimeZone('UTC'));
            $ts = $dt->getTimestamp();
        } else {
            $ts = time();
        }

        $trama = [
            'event'      => 'none',
            'gpsDate'    => gmdate('Y-m-d\TH:i:s.v\Z', $ts),
            'plate'      => $device->plate_number,
            'speed'      => 0.0,
            'position'   => [
                'latitude'  => floatval($request->latitud),
                'longitude' => floatval($request->longitud),
                'altitude'  => 0.0,
            ],
            'tokenTrama' => $token,
            'odometer'   => 0,
            'uuid'       => (string) Str::uuid(),
            'imei'       => intval($device->imei),
        ];

        try {
            $result = $this->sendOsinergminBatch([$trama]);
            $status = ($result['data'][0]['status'] ?? '') === 'CREATED' ? 'CREATED' : 'ERROR';
            return response()->json([
                'success' => $status === 'CREATED',
                'gpsDate' => $trama['gpsDate'],
                'status'  => $status,
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            return response()->json(['success' => false, 'error' => $body], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ─── OSINERGMIN: Posición fija en rango ──────────────────────────

    public function osinergminRango(Request $request): JsonResponse
    {
        $request->validate([
            'device_id'     => 'required|integer|exists:devices,id',
            'latitud'       => 'required|numeric|between:-90,90',
            'longitud'      => 'required|numeric|between:-180,180',
            'from_datetime' => 'required|date',
            'to_datetime'   => 'required|date|after:from_datetime',
            'interval'      => 'required|integer|min:1|max:60',
        ]);

        $logs   = [];
        $device = Device::with('users')->findOrFail($request->device_id);
        $token  = $this->getServiceToken($device, 'osinergmin');

        if (!$token) {
            $this->log($logs, 'error', 'No se encontró token Osinergmin activo para este dispositivo.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $from     = Carbon::parse($request->from_datetime);
        $to       = Carbon::parse($request->to_datetime);
        $interval = max(1, (int) $request->interval);
        $lat      = floatval($request->latitud);
        $lng      = floatval($request->longitud);

        if ($to->diffInMinutes($from) > 120) {
            $to = $from->copy()->addHours(2);
            $this->log($logs, 'warn', 'Rango limitado a 2 horas. Fin ajustado a: ' . $to->format('Y-m-d H:i:s'));
        }

        $tramas  = [];
        $current = $from->copy();
        while ($current->lte($to)) {
            $tramas[] = [
                'event'      => 'none',
                'gpsDate'    => $current->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'plate'      => $device->plate_number,
                'speed'      => 0.0,
                'position'   => ['latitude' => $lat, 'longitude' => $lng, 'altitude' => 0.0],
                'tokenTrama' => $token,
                'odometer'   => 0,
                'uuid'       => (string) Str::uuid(),
                'imei'       => intval($device->imei),
            ];
            $current->addMinutes($interval);
        }

        $this->log($logs, 'info', count($tramas) . " trama(s) en rango {$from->format('H:i')}–{$to->format('H:i')} (c/{$interval} min). Enviando...");

        try {
            $ok = $err = 0;
            foreach (array_chunk($tramas, 250) as $chunk) {
                $result = $this->sendOsinergminBatch($chunk);
                foreach ($result['data'] ?? [] as $d) {
                    if (($d['status'] ?? '') === 'CREATED') $ok++;
                    elseif (($d['status'] ?? '') === 'ERROR') $err++;
                }
            }
            $this->log($logs, 'success', "Resultado: CREATED={$ok}, ERROR={$err}");
            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $this->log($logs, 'error', "Error Osinergmin: $body");
            return response()->json(['success' => false, 'logs' => $logs]);
        } catch (\Exception $e) {
            $this->log($logs, 'error', 'Error inesperado: ' . $e->getMessage());
            return response()->json(['success' => false, 'logs' => $logs]);
        }
    }

    // ─── OSINERGMIN: Reenviar historial ──────────────────────────────

    public function osinergminHistorial(Request $request): JsonResponse
    {
        $request->validate([
            'device_id'     => 'required|integer|exists:devices,id',
            'from_datetime' => 'required|date',
            'to_datetime'   => 'required|date|after:from_datetime',
        ]);

        $logs   = [];
        $device = Device::with('users', 'traccar')->findOrFail($request->device_id);
        $token  = $this->getServiceToken($device, 'osinergmin');

        if (!$token) {
            $this->log($logs, 'error', 'No se encontró token Osinergmin activo para este dispositivo.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        if (!$device->traccar) {
            $this->log($logs, 'error', 'El dispositivo no tiene configuración Traccar.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $from = Carbon::parse($request->from_datetime);
        $to   = Carbon::parse($request->to_datetime);

        if ($to->diffInMinutes($from) > 120) {
            $to = $from->copy()->addHours(2);
            $this->log($logs, 'warn', 'Rango limitado a 2 horas. Fin ajustado a: ' . $to->format('Y-m-d H:i:s'));
        }

        $positions = $device->positions()
            ->whereBetween('time', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->orderBy('time')
            ->get();

        $this->log($logs, 'info', count($positions) . ' posición(es) encontrada(s) para ' . $device->plate_number . '.');

        if ($positions->isEmpty()) {
            $this->log($logs, 'warn', 'No se encontraron posiciones en el rango indicado.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $tramas = [];
        foreach ($positions as $pos) {
            $tramas[] = [
                'event'      => 'none',
                'gpsDate'    => gmdate('Y-m-d\TH:i:s.v\Z', strtotime($pos->time)),
                'plate'      => $device->plate_number,
                'speed'      => doubleval($pos->speed),
                'position'   => [
                    'latitude'  => doubleval($pos->latitude),
                    'longitude' => doubleval($pos->longitude),
                    'altitude'  => doubleval($pos->altitude ?? 0),
                ],
                'tokenTrama' => $token,
                'odometer'   => 0,
                'uuid'       => (string) Str::uuid(),
                'imei'       => intval($device->imei),
            ];
        }

        $this->log($logs, 'info', count($tramas) . ' trama(s) preparadas. Enviando...');

        try {
            $ok = $err = 0;
            foreach (array_chunk($tramas, 250) as $chunk) {
                $result = $this->sendOsinergminBatch($chunk);
                foreach ($result['data'] ?? [] as $d) {
                    if (($d['status'] ?? '') === 'CREATED') $ok++;
                    elseif (($d['status'] ?? '') === 'ERROR') $err++;
                }
            }
            $this->log($logs, 'success', "Resultado: CREATED={$ok}, ERROR={$err}");
            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            $this->log($logs, 'error', "Error Osinergmin: $body");
            return response()->json(['success' => false, 'logs' => $logs]);
        } catch (\Exception $e) {
            $this->log($logs, 'error', 'Error inesperado: ' . $e->getMessage());
            return response()->json(['success' => false, 'logs' => $logs]);
        }
    }

    // ─── MININTER ────────────────────────────────────────────────────

    public function mininterReenviar(Request $request): JsonResponse
    {
        $request->validate([
            'device_id'     => 'required|integer|exists:devices,id',
            'from_datetime' => 'required|date',
            'to_datetime'   => 'required|date|after:from_datetime',
        ]);

        $logs   = [];
        $device = Device::with('users', 'traccar')->findOrFail($request->device_id);

        if (!$device->traccar) {
            $this->log($logs, 'error', 'El dispositivo no tiene configuración Traccar.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        // Obtener idMunicipalidad y ubigeo del usuario municipalidad del dispositivo
        $idMunicipalidad = '';
        $ubigeo          = '';
        $muni = $device->users->where('is_municipalidad', true)->first();
        if ($muni) {
            $idMunicipalidad = $muni->token_muni  ?? '';
            $ubigeo          = $muni->ubigeo_muni ?? '';
        }

        $from = Carbon::parse($request->from_datetime);
        $to   = Carbon::parse($request->to_datetime);

        if ($to->diffInMinutes($from) > 120) {
            $to = $from->copy()->addHours(2);
            $this->log($logs, 'warn', 'Rango limitado a 2 horas. Fin ajustado a: ' . $to->format('Y-m-d H:i:s'));
        }

        // Leer posiciones del historial (igual que osinergminHistorial)
        $positions = $device->positions()
            ->whereBetween('time', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->orderBy('time')
            ->get();

        $this->log($logs, 'info', count($positions) . ' posición(es) encontrada(s) para ' . $device->plate_number . '.');

        if ($positions->isEmpty()) {
            $this->log($logs, 'warn', 'No se encontraron posiciones en el rango indicado.');
            return response()->json(['success' => false, 'logs' => $logs]);
        }

        $inserted  = 0;
        $skipped   = 0;

        foreach ($positions as $pos) {
            // Evitar duplicados: si ya existe un registro con la misma fechaHora para este device
            $exists = Mininter::where('device_id', $device->id)
                ->where('fechaHora', $pos->time)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            // Parsear el campo XML 'other' (igual que parseString en PositionsWriter)
            $others = [];
            if ($pos->other) {
                $xml = @simplexml_load_string($pos->other);
                if ($xml !== false) {
                    $others = json_decode(json_encode($xml), true) ?: [];
                }
            }

            // Insertar en tabla mininter con status = null para que el job SendDataMininter lo envíe
            Mininter::create([
                'device_id'       => $device->id,
                'alarma'          => 0,
                'altitud'         => $pos->altitude,
                'angulo'          => $pos->course,
                'distancia'       => isset($others['distance']) ? doubleval($others['distance']) : 0,
                'fechaHora'       => $pos->time,
                'timestamp'       => strtotime($pos->device_time),
                'horasMotor'      => $pos->getParameter('enginehours') ?? 0,
                'idMunicipalidad' => $idMunicipalidad,
                'ignition'        => $others['ignition'] ?? 0,
                'imei'            => $device->imei,
                'latitud'         => $pos->latitude,
                'longitud'        => $pos->longitude,
                'motion'          => $others['motion'] ?? 0,
                'placa'           => $device->plate_number,
                'totalDistancia'  => $others['totaldistance'] ?? 0,
                'totalHorasMotor' => $others['enginehours'] ?? 0,
                'ubigeo'          => $ubigeo,
                'valid'           => $pos->valid,
                'velocidad'       => $pos->speed,
                'other'           => $others ?: null,
                // status = null → SendDataMininter job lo procesará desde la cola
            ]);

            $inserted++;
        }

        $msg = "{$inserted} trama(s) insertadas en cola Mininter para {$device->plate_number}.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} omitida(s) por duplicado.)";
        }
        $msg .= ' El job las enviará automáticamente.';

        $this->log($logs, 'success', $msg);
        return response()->json(['success' => true, 'logs' => $logs]);
    }
}
