<?php

namespace App\Services;

use App\Http\Controllers\Frontend\DeviceMaintenancesController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\DeviceMaintenance;

class TalentusMaintenanceService
{
    /**
     * Envía una acción (upsert / delete) a la API de Talentus.
     * Devuelve true si la respuesta fue exitosa.
     */
    public function push(string $action, array $item): bool
    {
        $url       = rtrim(config('app.talentus_url', ''), '/');
        $empresaId = config('app.talentus_empresa_id');

        if (! $url || ! $empresaId) {
            return false; // integración no configurada
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-API-KEY' => config('app.talentus_api_key')])
                ->post("{$url}/api/tracking/device-maintenances/sync", [
                    'action'     => $action,
                    'empresa_id' => (int) $empresaId,
                    'item'       => $item,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('TalentusMaintenanceService: respuesta no exitosa', [
                'action' => $action,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('TalentusMaintenanceService: excepción al enviar', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sincroniza todos los registros con synced_at = null.
     * Pensado para ejecutarse desde el job de reintento.
     */
    public function syncPending(): void
    {
        $pending = DeviceMaintenance::pendingSync()
            ->with('device')
            ->get();

        foreach ($pending as $maintenance) {
            if (! $maintenance->device) {
                continue; // dispositivo eliminado, omitir
            }

            $payload = DeviceMaintenancesController::buildPayload($maintenance, $maintenance->device);

            if ($this->push('upsert', $payload)) {
                $maintenance->markAsSynced();
            }
        }
    }
}
