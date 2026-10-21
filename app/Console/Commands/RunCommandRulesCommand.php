<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\CommandRule;
use Tobuli\Services\CommandRules\CommandRuleRunner;

/**
 * Ejecuta las normas de comando vencidas (programado cada minuto en Kernel).
 *
 *   php artisan command-rules:run                 # las vencidas
 *   php artisan command-rules:run --rule=3 --force # una norma ahora mismo
 */
class RunCommandRulesCommand extends Command
{
    protected $signature = 'command-rules:run
        {--rule= : Id de una norma concreta}
        {--force : Ejecutar aunque el intervalo no haya vencido (requiere --rule)}';

    protected $description = 'Envia el comando de consulta de las normas de comando vencidas';

    public function handle(CommandRuleRunner $runner): int
    {
        if ($ruleId = $this->option('rule')) {
            $rule = CommandRule::find($ruleId);
            if (!$rule) {
                $this->error("No existe la norma {$ruleId}.");
                return 1;
            }
            if (!$rule->active && !$this->option('force')) {
                $this->warn("La norma {$rule->name} esta inactiva; usa --force para ejecutarla igual.");
                return 0;
            }
            if (!$rule->isDue() && !$this->option('force')) {
                $this->line("La norma {$rule->name} aun no vence (ultima ejecucion {$rule->last_run_at}).");
                return 0;
            }

            $stats = $runner->run($rule);
            $this->printStats([$rule->id => $stats]);
            return 0;
        }

        $this->printStats($runner->runDue());

        return 0;
    }

    private function printStats(array $stats): void
    {
        if (empty($stats)) {
            $this->line('Sin normas vencidas.');
            return;
        }

        $rows = [];
        foreach ($stats as $id => $s) {
            $rows[] = [$id, $s['rule'], $s['sent'], $s['failed'], $s['skipped']];
        }
        $this->table(['Id', 'Norma', 'Enviados', 'Fallidos', 'Omitidos'], $rows);
    }
}
