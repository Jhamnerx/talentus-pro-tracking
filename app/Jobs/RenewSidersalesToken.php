<?php

namespace App\Jobs;

use App\Services\Api\SidersalesTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use CustomFacades\Repositories\UserRepo;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Job para renovar proactivamente el token OAuth 2.0 (Azure AD — Client Credentials)
 * del servicio Sidersales por cada usuario activo.
 * Programado cada 50 minutos; delega la lógica a SidersalesTokenService.
 */
class RenewSidersalesToken implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle()
    {
        $tokenService = app(SidersalesTokenService::class);
        $users        = UserRepo::getActiveServiceManagers(1, 'sidersales');

        foreach ($users as $user) {
            $service = $user->services['sidersales'] ?? [];

            try {
                $tokenService->refreshToken($user->id, $service);
            } catch (\RuntimeException $e) {
                Log::channel('sidersales')->error('Error en RenewSidersalesToken', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
