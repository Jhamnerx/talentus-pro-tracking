<?php

namespace App\Console\Commands;

use App\Services\Ai\AiFeatures;
use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Illuminate\Console\Command;
use Tobuli\Entities\User;

/**
 * Resumen diario de alertas por correo, escrito por el asistente. Solo para usuarios
 * con el permiso ai_assistant y con config ai.features.alerts_digest activo
 * (viene apagado porque escribe a los clientes).
 */
class AiAlertsDigestCommand extends Command
{
    protected $signature = 'ai:alerts-digest {--user= : Solo este usuario (prueba)} {--dry : Muestra el texto sin enviar}';

    protected $description = 'Talentus IA CHAT: envia el resumen diario de alertas de ayer';

    public function handle()
    {
        if (!config('ai.features.alerts_digest') && !$this->option('dry')) {
            $this->info('Desactivado (AI_ALERTS_DIGEST=false).');
            return 0;
        }

        $query = User::query()->whereNotNull('email')->where('active', 1);

        if ($this->option('user')) {
            $query->where('id', (int) $this->option('user'));
        }

        $sent = 0;

        $query->chunk(100, function ($users) use (&$sent) {
            foreach ($users as $user) {
                if (!$this->option('dry') && !AiFeatures::enabled($user, 'alerts_digest')) {
                    continue;
                }
                if ($this->option('dry') && !$user->perm('ai_assistant', 'view') && !$this->option('user')) {
                    continue;
                }

                try {
                    setActingUser($user);
                    $html = AiFeatures::alertsDigest($user);
                } catch (\Throwable $e) {
                    Bugsnag::notifyException($e);
                    continue;
                }

                if (!$html) {
                    continue;
                }

                if ($this->option('dry')) {
                    $this->line("== {$user->email}\n" . strip_tags(str_replace('<br />', "\n", $html)));
                    continue;
                }

                try {
                    \CustomFacades\MailHelper::send($user->email, 'Resumen diario de alertas - ' . \App\Services\Ai\AiSettings::name(),
                        '<p>' . $html . '</p><p style="color:#6b7280;font-size:12px">Generado por ' . e(\App\Services\Ai\AiSettings::name()) . ' con los eventos de ayer.</p>');
                    $sent++;
                } catch (\Throwable $e) {
                    Bugsnag::notifyException($e);
                }
            }
        });

        $this->info("Resumenes enviados: $sent");

        return 0;
    }
}
