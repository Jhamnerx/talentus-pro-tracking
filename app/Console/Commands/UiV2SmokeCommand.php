<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Tobuli\Entities\User;

/**
 * Prueba de humo de la UI v2: lanza una peticion REAL a cada pagina y a cada
 * endpoint, y dice cual no responde.
 *
 * Existe por un motivo concreto. El 29-sep una correccion metio trans()
 * dentro de una `const`, que es sintaxis valida pero fatal al cargar la
 * clase. `php -l` paso limpio en los 25 controladores y el tablero quedo
 * tirado hasta que alguien lo abrio en el navegador. Lintar no es ejecutar.
 *
 *   php artisan uiv2:smoke
 *   php artisan uiv2:smoke --user=12
 */
class UiV2SmokeCommand extends Command
{
    protected $signature = 'uiv2:smoke
        {--user= : id del usuario con el que probar (por defecto, el primer admin)}';

    protected $description = 'Comprueba que las paginas y endpoints de la UI v2 responden';

    /** Paginas del shell v2. */
    private const PAGINAS = [
        '/ui/v2/live', '/ui/v2/fleet', '/ui/v2/monitor', '/ui/v2/dashboard',
        '/ui/v2/zones', '/ui/v2/alerts', '/ui/v2/events', '/ui/v2/history',
        '/ui/v2/reports', '/ui/v2/maintenance', '/ui/v2/routes/table',
        '/ui/v2/commands', '/ui/v2/sharing', '/ui/v2/drivers', '/ui/v2/tasks',
        '/ui/v2/call-actions', '/ui/v2/chat', '/ui/v2/expenses',
        '/ui/v2/route-schedules', '/ui/v2/copilot', '/ui/v2/legacy',
    ];

    /** Endpoints de lectura que alimentan esas paginas. */
    private const ENDPOINTS = [
        '/ui/v2/api/devices', '/ui/v2/api/fleet', '/ui/v2/api/dashboard',
        '/ui/v2/api/zones', '/ui/v2/api/alerts', '/ui/v2/api/events',
        '/ui/v2/api/pois', '/ui/v2/api/routes', '/ui/v2/api/expenses',
        '/ui/v2/api/forwards', '/ui/v2/api/reports/meta', '/ui/v2/api/reports/saved',
        '/ui/v2/api/notifications', '/ui/v2/api/search?q=a',
        '/ui/v2/api/history/devices', '/ui/v2/api/route-schedules',
    ];

    public function handle(): int
    {
        $user = $this->resolverUsuario();

        if (! $user) {
            $this->error('No hay usuario con el que probar.');

            return self::FAILURE;
        }

        auth()->setUser($user);
        $this->line(sprintf('  como id=%s %s', $user->id, $user->email));
        $this->newLine();

        $malos = array_merge(
            $this->tanda('paginas', self::PAGINAS, $user),
            $this->tanda('endpoints', self::ENDPOINTS, $user)
        );

        $total = count(self::PAGINAS) + count(self::ENDPOINTS);
        $this->newLine();

        if (! $malos) {
            $this->info(sprintf('  %d/%d responden.', $total, $total));

            return self::SUCCESS;
        }

        $this->error(sprintf('  %d con problema de %d:', count($malos), $total));
        foreach ($malos as [$uri, $codigo, $mensaje]) {
            $this->line(sprintf('     %-34s %-10s %s', $uri, $codigo,
                preg_replace('/\s+/', ' ', $mensaje)));
        }

        return self::FAILURE;
    }

    private function resolverUsuario(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find((int) $id);
        }

        return User::where('group_id', 1)->orderBy('id')->first();
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function tanda(string $titulo, array $uris, User $user): array
    {
        $this->line('  == ' . $titulo . ' ==');
        $malos = [];

        foreach ($uris as $uri) {
            $request = Request::create($uri, 'GET');
            $request->setUserResolver(fn () => $user);

            try {
                $respuesta = app()->handle($request);
                $codigo = $respuesta->getStatusCode();
                // 302 es correcto: /ui/v2/zones y /ui/v2/legacy redirigen.
                $ok = $codigo < 400 || $codigo === 302;

                if (! $ok) {
                    $malos[] = [$uri, (string) $codigo,
                        mb_substr(strip_tags($respuesta->getContent()), 0, 160)];
                }

                $this->line(sprintf('     %-34s %s%s', $uri, $codigo,
                    $ok ? '' : '   <-- REVISAR'));
            } catch (\Throwable $e) {
                $malos[] = [$uri, 'EXCEPCION', mb_substr($e->getMessage(), 0, 160)];
                $this->line(sprintf('     %-34s EXCEPCION  %s', $uri,
                    mb_substr($e->getMessage(), 0, 70)));
            }
        }

        return $malos;
    }
}
