<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;
use Tobuli\Helpers\Tracker;

/**
 * Copia a tc_devices de Traccar dos datos del equipo, por la API REST para que Traccar refresque su caché:
 * - model: los decoders lo usan para elegir qué IO decodificar (p. ej. FMC130 en Teltonika); se aplica en
 *   la próxima conexión del equipo.
 * - disabled: el inverso de devices.active. Traccar rechaza los mensajes de un equipo deshabilitado, también
 *   en la conexión ya abierta, así que deja de llegar al instante.
 */
class TraccarDeviceModelService
{
    private Tracker $tracker;

    public function __construct()
    {
        $this->tracker = new Tracker();
    }

    /**
     * Sale del texto libre "Modelo del dispositivo" (p. ej. FMC920). El selector de catálogo
     * (model_id) no se usa porque agrupa equipos para sensores y no es el código exacto del modelo.
     */
    public function resolveModel(Device $device): ?string
    {
        $model = trim((string) $device->device_model);

        return $model === '' ? null : $model;
    }

    /**
     * Un equipo inactivo en la plataforma queda deshabilitado en Traccar.
     */
    public function resolveDisabled(Device $device): bool
    {
        return ! $device->active;
    }

    /**
     * @return bool|null true si se actualizó, false si ya estaba igual, null si el equipo todavía no
     *                   existe en Traccar (se crea al conectarse por primera vez).
     * @throws \RuntimeException si falla la comunicación con Traccar.
     */
    public function sync(Device $device): ?bool
    {
        if (empty($device->imei)) {
            return null;
        }

        $devices = $this->request('GET', 'api/devices?uniqueId=' . urlencode($device->imei));

        if ($devices === null) {
            throw new \RuntimeException("Traccar: no se pudo consultar el equipo {$device->imei}");
        }

        $traccarDevice = $devices[0] ?? null;

        if (! $traccarDevice) {
            return null;
        }

        $model    = $this->resolveModel($device);
        $disabled = $this->resolveDisabled($device);

        if (($traccarDevice['model'] ?? null) === $model
            && (bool) ($traccarDevice['disabled'] ?? false) === $disabled) {
            return false;
        }

        // PUT reemplaza el objeto completo, por eso se reenvía el equipo tal como lo devolvió Traccar.
        $traccarDevice['model']    = $model;
        $traccarDevice['disabled'] = $disabled;

        if ($this->request('PUT', 'api/devices/' . $traccarDevice['id'], $traccarDevice) === null) {
            throw new \RuntimeException("Traccar: no se pudo actualizar el equipo {$device->imei}");
        }

        return true;
    }

    private function request(string $method, string $endpoint, array $body = []): ?array
    {
        $config = config('tracker');
        $url    = $this->tracker->getUrl() . ':' . $config['web.port'] . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode('admin:' . config('app.admin_user')),
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];

        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || $raw === false) {
            return null;
        }

        return json_decode($raw, true) ?? [];
    }
}
