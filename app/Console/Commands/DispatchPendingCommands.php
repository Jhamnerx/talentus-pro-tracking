<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\PendingCommand;
use Tobuli\Services\Commands\SendCommandService;

/**
 * Auto-send when online (UI v2): despacha los comandos GPRS en cola
 * cuando su dispositivo vuelve a estar conectado. Programado cada
 * minuto en el Kernel. Expira pendientes con más de 7 días.
 */
class DispatchPendingCommands extends Command
{
    protected $signature = 'commands:dispatch-pending';

    protected $description = 'Send queued GPRS commands once their device is online';

    const EXPIRE_DAYS = 7;

    public function handle()
    {
        $pendings = PendingCommand::pending()
            ->with(['device', 'user'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($pendings as $pending) {
            if (! $pending->device || ! $pending->user) {
                $pending->update(['status' => PendingCommand::STATUS_FAILED, 'last_error' => 'missing device/user']);
                continue;
            }

            if ($pending->created_at->lt(now()->subDays(self::EXPIRE_DAYS))) {
                $pending->update(['status' => PendingCommand::STATUS_EXPIRED]);
                continue;
            }

            if (! $pending->device->isConnected()) {
                continue;
            }

            $data = (array) ($pending->parameters ?? []);
            $data['type'] = $pending->command;

            try {
                // El servicio registra el SentCommand (log) igual que un envío manual.
                $result = (new SendCommandService())
                    ->gprs(collect([$pending->device]), $data, $pending->user)
                    ->first();
            } catch (\Throwable $e) {
                $result = ['status' => 0, 'error' => $e->getMessage()];
            }

            if (! empty($result['status'])) {
                $pending->update(['status' => PendingCommand::STATUS_SENT, 'sent_at' => now()]);
            } else {
                $pending->update([
                    'attempts'   => $pending->attempts + 1,
                    'last_error' => mb_substr((string) ($result['error'] ?? 'error'), 0, 500),
                ]);
            }
        }

        return 0;
    }
}
