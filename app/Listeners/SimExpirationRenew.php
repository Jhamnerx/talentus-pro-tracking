<?php

namespace App\Listeners;

use Exception;
use App\Events\Device\DeviceEventInterface;
use Tobuli\Services\SimBlockingService;

class SimExpirationRenew
{
    public function handle(DeviceEventInterface $event)
    {
        // La clave es 'renew_sim_expiration'. Con el guion bajo de delante no
        // coincidia con nada, asi que el listener salia siempre y el plugin
        // "Renovar la fecha de vencimiento de la SIM despues de renovar la
        // suscripcion" no hacia nada aunque se marcara en el admin.
        if (! settings('plugins.renew_sim_expiration.status')) {
            return;
        }

        $event->device->update([
            'sim_expiration_date' => $event->device->expiration_date,
        ]);
    }
}
