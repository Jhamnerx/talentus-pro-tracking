<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Entities\TraccarPosition;
use Tobuli\Entities\User;
use Tobuli\Services\Securitas\SecuritasClient;
use Tobuli\Services\Securitas\SecuritasEventMapper;
use Tobuli\Services\Securitas\SecuritasTransportException;

/**
 * Envio de prueba a Securitas GlobalAVL (un solo evento).
 *
 * Credenciales: services['securitas'] del gestor (--user=<id>); se pueden
 * sobreescribir con --url / --api-key. El evento se arma con SecuritasEventMapper
 * a partir de la ultima posicion del dispositivo (IMEI).
 *
 *   php artisan securitas:test 356307042441013 --user=12 --dry-run
 *   php artisan securitas:test 356307042441013 --user=12 --now
 */
class SecuritasTestCommand extends Command
{
    protected $signature = 'securitas:test
        {imei : IMEI del dispositivo}
        {--user= : Id del usuario gestor cuyas credenciales services[securitas] se usan}
        {--url= : URL del gateway (sobreescribe la del gestor)}
        {--api-key= : API key (sobreescribe la del gestor)}
        {--now : Usar la hora actual UTC como created en vez de la de la ultima posicion}
        {--dry-run : Solo muestra el JSON que se enviaria, sin llamar al servicio}';

    protected $description = 'Envia a Securitas GlobalAVL la ultima posicion de un dispositivo y muestra la respuesta';

    public function handle(): int
    {
        $device = Device::with('traccar')->where('imei', trim($this->argument('imei')))->first();
        if (!$device || !$device->traccar) {
            $this->error('No se encontro el dispositivo o no tiene posicion.');
            return 1;
        }

        $t = $device->traccar;
        $position = new TraccarPosition();
        $position->forceFill([
            'latitude'    => $t->lastValidLatitude,
            'longitude'   => $t->lastValidLongitude,
            'altitude'    => $t->altitude,
            'speed'       => $t->speed,
            'course'      => $t->course,
            'valid'       => 1,
            'device_time' => $this->option('now') ? gmdate('Y-m-d H:i:s') : $t->device_time,
            'other'       => $t->other,
        ]);

        $event   = SecuritasEventMapper::build($device, $position);
        $payload = ['events' => [$event], 'version' => SecuritasEventMapper::VERSION];

        $this->line("Dispositivo #{$device->id} ({$device->name})");
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        if ($this->option('dry-run')) {
            $this->info('dry-run: no se envio nada.');
            return 0;
        }

        $service = $this->resolveService();
        if (!$service) {
            return 1;
        }

        $client = new SecuritasClient($service['url'], $service['api_key']);
        $this->line('URL: ' . $service['url'] . ' — API key: ' . $client->maskedKey());

        try {
            $status = $client->send([$event]);
        } catch (SecuritasTransportException $e) {
            $this->error('Sin respuesta de GlobalAVL: ' . $e->getMessage());
            return 2;
        }

        $this->line("HTTP {$status}: " . $client->lastResponse());

        return $status === 200 ? 0 : 3;
    }

    private function resolveService(): ?array
    {
        $service = ['url' => null, 'api_key' => null];

        if ($userId = $this->option('user')) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("No existe el usuario {$userId}.");
                return null;
            }
            $cfg = $user->services['securitas'] ?? [];
            $service = ['url' => $cfg['url'] ?? null, 'api_key' => $cfg['api_key'] ?? null];
        }

        if ($this->option('url')) {
            $service['url'] = $this->option('url');
        }
        if ($this->option('api-key')) {
            $service['api_key'] = $this->option('api-key');
        }

        if (empty($service['url']) || empty($service['api_key'])) {
            $this->error('Faltan credenciales: indica --user=<id gestor con Securitas configurado> o --url y --api-key.');
            return null;
        }

        return $service;
    }
}
