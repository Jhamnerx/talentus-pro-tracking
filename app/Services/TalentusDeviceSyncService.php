<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\Device;

/**
 * Envía un webhook a Talentus ERP cuando se guarda o actualiza un dispositivo.
 *
 * Endpoint destino: POST {TALENTUS_URL}/api/tracking/device-sync
 * Autenticación   : X-API-KEY header (TALENTUS_API_KEY)
 *
 * Talentus buscará el vehículo por plate_number y actualizará:
 *   gpswox_id, gpswox_active, numero (SIM), dispositivo principal (IMEI).
 */
class TalentusDeviceSyncService
{
    public function pushDevice(Device $device): bool
    {
        $url    = rtrim(config('app.talentus_url', ''), '/');
        $apiKey = config('app.talentus_api_key', '');

        // Si no hay URL o placa, no hay nada que sincronizar
        if (! $url || blank($device->plate_number)) {
            return false;
        }

        $payload = [
            'plate_number' => $device->plate_number,
            'id'           => $device->id,
            'imei'         => $device->imei         ?? null,
            'sim_number'   => $device->sim_number   ?? null,
            'active'       => (bool) $device->active,
            'name'         => $device->name         ?? null,
        ];

        try {
            $request = Http::timeout(10);

            if (filled($apiKey)) {
                $request = $request->withHeaders(['X-API-KEY' => $apiKey]);
            }

            $response = $request->post("{$url}/api/tracking/device-sync", $payload);

            if ($response->successful()) {
                Log::channel('daily')->info('[TalentusDeviceSync] Sincronizado', [
                    'plate'    => $device->plate_number,
                    'gpswox_id' => $device->id,
                    'response' => $response->json(),
                ]);
                return true;
            }

            Log::channel('daily')->warning('[TalentusDeviceSync] Respuesta no exitosa', [
                'plate'  => $device->plate_number,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[TalentusDeviceSync] Error al enviar webhook', [
                'plate' => $device->plate_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
