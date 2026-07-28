<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tobuli\Entities\Forward;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Forwards\ForwardsManager;
use Tobuli\Services\ForwardService;

/**
 * TalentusPro · ForwardsController (ui/v2)
 * -----------------------------------------------------------------
 * Reenvíos: reenvía las posiciones a sistemas externos. Reutiliza
 * ForwardService + la entidad Forward (payload = config por tipo).
 * Los TIPOS disponibles se filtran por sus addons: forward_custom /
 * forward_pegasus / forward_macropoint. Si ninguno está activo, la
 * lista de tipos llega vacía (por eso el <select> Tipo salía vacío).
 * -----------------------------------------------------------------
 */
class ForwardsController extends BaseController
{
    /** Campos de configuración por tipo (paridad con los Form de cada conexión). */
    private const TYPE_FIELDS = [
        'custom' => [
            ['name' => 'ip',   'label' => 'IP',     'type' => 'text',   'required' => true],
            ['name' => 'port', 'label' => 'Puerto', 'type' => 'number', 'required' => true],
        ],
        'pegasus' => [
            ['name' => 'domain', 'label' => 'Dominio', 'type' => 'text', 'required' => true, 'help' => 'https://{dominio}.peginstances.com/receivers/json'],
            ['name' => 'token',  'label' => 'Token',   'type' => 'text', 'required' => true],
        ],
        'macropoint' => [
            ['name' => 'username', 'label' => 'Usuario',    'type' => 'text',     'required' => true],
            ['name' => 'password', 'label' => 'Contraseña', 'type' => 'password', 'required' => true],
            ['name' => 'mpid',     'label' => 'MPID',       'type' => 'text',     'required' => true],
        ],
    ];

    private ForwardService $service;

    protected function afterAuth($user)
    {
        $this->service = new ForwardService();
    }

    private function gate(string $action): void
    {
        if (! config('addon.forwards') || ! $this->user->perm('forwards', $action)) {
            abort(403);
        }
    }

    /** Lista de reenvíos del usuario + los tipos habilitados (para el form). */
    public function data(Request $request): JsonResponse
    {
        $this->gate('view');

        $items = Forward::userAccessible($this->user)
            ->search($request->get('s'))
            ->orderBy('title')
            ->get()
            ->map(function (Forward $f) {
                return [
                    'id'      => (int) $f->id,
                    'active'  => (bool) $f->active,
                    'type'    => (string) $f->type,
                    'title'   => (string) $f->title,
                    'payload' => (array) ($f->payload ?? []),
                ];
            })
            ->values()
            ->all();

        // Tipos habilitados (por addon), con sus campos de configuración.
        $enabled = [];
        foreach ((new ForwardsManager())->getEnabledList() as $conn) {
            $type = $conn::getType();
            if (! isset(self::TYPE_FIELDS[$type])) {
                continue;
            }
            $enabled[] = [
                'type'   => $type,
                'title'  => $conn::getTitle(),
                'fields' => self::TYPE_FIELDS[$type],
            ];
        }

        return response()->json(['ok' => true, 'items' => $items, 'types' => $enabled]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate('edit');

        try {
            $this->service->store($request->all(), $this->user);

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->gate('edit');

        $item = Forward::userAccessible($this->user)->find((int) $id);
        if (! $item) {
            return response()->json(['ok' => false, 'message' => 'Reenvío no encontrado.'], 404);
        }

        try {
            $this->service->update($item, $request->all());

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $this->gate('remove');

        Forward::userAccessible($this->user)->where('id', (int) $id)->delete();

        return response()->json(['ok' => true]);
    }
}
