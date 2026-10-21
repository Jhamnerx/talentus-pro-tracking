<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Services\Unigis\UnigisEventMapper;
use Tobuli\Services\Unigis\UnigisSoapClient;
use Tobuli\Services\Unigis\UnigisTransportException;

/**
 * Envio de prueba a UNIGIS (un solo LoginYInsertarEvento).
 *
 * Credenciales: por defecto se toman de services['unigis'] del usuario gestor
 * (--user=<id>); se pueden sobreescribir con --username/--password/--url.
 * Posicion: por defecto la ultima conocida del dispositivo con esa placa;
 * se puede forzar con --lat/--lng.
 *
 *   php artisan unigis:test BEP887 --user=12
 *   php artisan unigis:test BEP887 --username=X --password=Y --url=https://.../service.asmx?wsdl
 */
class UnigisTestCommand extends Command
{
    protected $signature = 'unigis:test
        {plate : Placa a enviar (se normaliza sin guiones ni espacios)}
        {--user= : Id del usuario gestor cuyas credenciales services[unigis] se usan}
        {--username= : Usuario UNIGIS (sobreescribe el del gestor)}
        {--password= : Contrasena UNIGIS (sobreescribe la del gestor)}
        {--url= : URL del WSDL (sobreescribe la del gestor)}
        {--code=POS : Codigo de evento UNIGIS}
        {--lat= : Latitud (por defecto la ultima del dispositivo)}
        {--lng= : Longitud (por defecto la ultima del dispositivo)}
        {--speed= : Velocidad km/h (por defecto la ultima del dispositivo)}
        {--now : Usar la hora actual UTC como FechaHoraEvento/Recepcion aunque se tome la posicion del dispositivo}
        {--dry-run : Solo muestra lo que se enviaria, sin llamar al servicio}';

    protected $description = 'Envia una posicion de prueba a UNIGIS con LoginYInsertarEvento y muestra el retorno';

    public function handle(): int
    {
        $plate  = UnigisEventMapper::normalizePlate($this->argument('plate'));
        $code   = strtoupper((string) $this->option('code'));

        if (!array_key_exists($code, UnigisEventMapper::catalog())) {
            $this->warn("El codigo {$code} no esta en el catalogo acordado con UNIGIS: " . implode(', ', array_keys(UnigisEventMapper::catalog())));
        }

        // 1. Credenciales
        $service = $this->resolveService();
        if (!$service) {
            return 1;
        }

        // 2. Posicion
        $row = $this->resolvePosition($plate, $code);
        if (!$row) {
            return 1;
        }

        $this->table(['Campo', 'Valor'], [
            ['WSDL', $service['url']],
            ['SystemUser', $service['user']],
            ['Password', str_repeat('*', strlen($service['password']))],
            ['Dominio', $row['dominio']],
            ['NroSerie', UnigisSoapClient::NRO_SERIE],
            ['Codigo', $row['codigo']],
            ['Latitud', $row['latitud']],
            ['Longitud', $row['longitud']],
            ['Altitud', $row['altitud']],
            ['Velocidad', $row['velocidad']],
            ['FechaHoraEvento (UTC)', $row['fecha_hora_evento']],
            ['FechaHoraRecepcion (UTC)', $row['fecha_hora_recepcion']],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: no se envio nada.');
            return 0;
        }

        $client = new UnigisSoapClient($service['url'], $service['user'], $service['password']);

        try {
            $result = $client->loginYInsertarEvento($row);
        } catch (UnigisTransportException $e) {
            $this->error('Fallo de transporte: ' . $e->getMessage());
            $this->printXml('Request', $client->lastRequest());
            $this->printXml('Response', $client->lastResponse());
            return 2;
        }

        $this->newLine();
        $this->line('Retorno UNIGIS: <options=bold>' . $result . '</> (' . UnigisSoapClient::describeResult($result) . ')');
        $this->line('Enviado a las ' . gmdate('Y-m-d H:i:s') . ' UTC');

        if ($this->getOutput()->isVerbose() || $result <= 0) {
            $this->printXml('Request', $client->lastRequest());
            $this->printXml('Response', $client->lastResponse());
        }

        return $result > 0 ? 0 : 3;
    }

    private function resolveService(): ?array
    {
        $service = ['url' => null, 'user' => null, 'password' => null];

        if ($userId = $this->option('user')) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("No existe el usuario {$userId}.");
                return null;
            }
            $cfg = $user->services['unigis'] ?? [];
            $service = [
                'url'      => $cfg['url'] ?? null,
                'user'     => $cfg['user'] ?? null,
                'password' => $cfg['password'] ?? null,
            ];
        }

        foreach (['url' => 'url', 'username' => 'user', 'password' => 'password'] as $opt => $key) {
            if ($this->option($opt) !== null && $this->option($opt) !== '') {
                $service[$key] = $this->option($opt);
            }
        }

        if (!$service['url']) {
            $service['url'] = config('services.unigis.test_wsdl', 'https://cloud-test.unigis.com/hub_TEST/mapi/soap/gps/service.asmx?wsdl');
        }

        if (!$service['user'] || !$service['password']) {
            $this->error('Faltan credenciales: indica --user=<id gestor con UNIGIS configurado> o --username y --password.');
            return null;
        }

        return $service;
    }

    private function resolvePosition(string $plate, string $code): ?array
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $row = [
            'dominio'              => $plate,
            'codigo'               => $code,
            'latitud'              => $this->option('lat'),
            'longitud'             => $this->option('lng'),
            'altitud'              => 0,
            'velocidad'            => $this->option('speed'),
            'fecha_hora_evento'    => $nowUtc,
            'fecha_hora_recepcion' => $nowUtc,
        ];

        $needsDevice = $row['latitud'] === null || $row['longitud'] === null || $row['velocidad'] === null;

        if ($needsDevice) {
            $device = Device::with('traccar')
                ->whereRaw("UPPER(REPLACE(REPLACE(plate_number, '-', ''), ' ', '')) = ?", [$plate])
                ->first();

            if (!$device || !$device->traccar) {
                $this->error("No se encontro un dispositivo con placa {$plate} (o no tiene posicion). Usa --lat, --lng y --speed.");
                return null;
            }

            $t = $device->traccar;
            $row['latitud']   = $row['latitud'] ?? $t->lastValidLatitude;
            $row['longitud']  = $row['longitud'] ?? $t->lastValidLongitude;
            $row['velocidad'] = $row['velocidad'] ?? (int) round((float) $t->speed);
            $row['altitud']   = (int) round((float) ($t->altitude ?? 0));

            if ($t->device_time && !$this->option('now')) {
                $row['fecha_hora_evento'] = date('Y-m-d H:i:s', strtotime($t->device_time));
            }
            if ($t->server_time && !$this->option('now')) {
                $row['fecha_hora_recepcion'] = date('Y-m-d H:i:s', strtotime($t->server_time));
            }

            $this->line("Dispositivo #{$device->id} ({$device->name}), ultima posicion del " . $row['fecha_hora_evento'] . ' UTC');
        }

        $row['latitud']   = (float) $row['latitud'];
        $row['longitud']  = (float) $row['longitud'];
        $row['velocidad'] = (int) $row['velocidad'];

        return $row;
    }

    private function printXml(string $title, ?string $xml): void
    {
        if (!$xml) {
            return;
        }
        $this->newLine();
        $this->comment($title . ':');
        $this->line($xml);
    }
}
