<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\User;
use Tobuli\Services\Security\SecurityDailyCalculator;

class SecurityAggregateCommand extends Command
{
    protected $signature = 'security:aggregate {--from=} {--to=} {--user=} {--device=*}';
    protected $description = 'Agregados diarios de Seguridad (km, motor, movimiento, excesos, cinturon) por unidad y turno';

    public function handle(): int
    {
        if (! config('tobuli.security_enabled')) {
            return 0;
        }

        // Sin rango: ayer y hoy (hoy queda parcial y se rehace manana).
        $from = $this->option('from') ?: now()->subDay()->toDateString();
        $to = $this->option('to') ?: now()->toDateString();

        $users = SecuritySetting::query()
            ->when($this->option('user'), fn($q, $u) => $q->where('user_id', $u))
            ->pluck('user_id');

        foreach ($users as $userId) {
            $user = User::find($userId);
            if (! $user || ! $user->perm('security', 'view')) continue;

            $n = (new SecurityDailyCalculator($user))->calculateRange($from, $to, $this->option('device') ?: null);
            $this->info("usuario {$userId}: {$n} filas");
        }

        return 0;
    }
}
