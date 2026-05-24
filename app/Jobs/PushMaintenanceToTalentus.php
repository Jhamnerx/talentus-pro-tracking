<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushMaintenanceToTalentus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de reintentos si falla la conexión con Talentus.
     */
    public int $tries = 3;

    /**
     * Backoff entre reintentos (segundos).
     */
    public array $backoff = [10, 60, 300];

    private $action;
    private $items;

    public function __construct(string $action, array $item)
    {
        $this->action = $action;
        $this->items  = $item;
    }

    public function handle(): void
    {
        $url      = rtrim(config('app.talentus_url', ''), '/');
        $empresaId = config('app.talentus_empresa_id');

        if (! $url || ! $empresaId) {
            return; // no configurado — silenciar
        }

        $response = Http::timeout(15)
            ->post("{$url}/api/tracking/device-maintenances/sync", [
                'action'     => $this->action,
                'empresa_id' => (int) $empresaId,
                'item'       => $this->items,
            ]);

        if (! $response->successful()) {
            Log::warning('PushMaintenanceToTalentus: respuesta no exitosa', [
                'action' => $this->action,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            // Forzar reintento si el servidor devolvió 5xx
            if ($response->serverError()) {
                $this->release(30);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PushMaintenanceToTalentus: falló definitivamente', [
            'action' => $this->action,
            'item'   => $this->items,
            'error'  => $e->getMessage(),
        ]);
    }
}
