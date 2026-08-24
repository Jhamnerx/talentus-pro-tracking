<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Services\IntegrationStatusService;

/**
 * Recalcula el resumen de reenvío por vehículo e integración.
 *
 * Va en un comando periódico y NO en la petición web a proposito: son
 * agregaciones sobre colas que llegan a cientos de miles de filas, y el panel
 * no debe competir por ellas con los jobs de envio.
 */
class IntegrationStatusCommand extends Command
{
    protected $signature = 'integrations:status';

    protected $description = 'Recalcula el estado de reenvio por vehiculo e integracion';

    public function handle(IntegrationStatusService $servicio): int
    {
        $inicio = microtime(true);

        $resumen = $servicio->refresh();

        $ms = round((microtime(true) - $inicio) * 1000);

        foreach ($resumen as $integracion => $vehiculos) {
            $this->line(str_pad($integracion, 14) . $vehiculos . ' vehiculos');
        }

        $this->info('Resumen actualizado en ' . $ms . ' ms');

        return self::SUCCESS;
    }
}
