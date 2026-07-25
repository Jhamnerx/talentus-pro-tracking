<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\Repositories\EventCustomRepo;
use CustomFacades\Repositories\TrackerPortRepo;
use ModalHelpers\CustomEventModalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\ConditionService;

/**
 * TalentusPro · CustomEventsController (ui/v2)
 * -----------------------------------------------------------------
 * Eventos personalizados (events_custom). CRUD delegado en el
 * CustomEventModalHelper legacy con api=true: las condiciones viajan
 * como JSON [{tag,type,tag_value}] (validateTags las desempaqueta),
 * junto con protocol, message y always. Se usa desde el tab "Eventos"
 * del modal de cuenta. Permisos: módulo 'custom_events'.
 * -----------------------------------------------------------------
 */
class CustomEventsController extends BaseController
{
    public function data(Request $request): JsonResponse
    {
        $this->checkException('custom_events', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 15)));

        $params = ['filter' => ['user_id' => $this->user->id]];
        if ($s = trim((string) $request->get('s'))) {
            $params['search'] = $s;
        }

        $items = EventCustomRepo::searchAndPaginate($params, 'id', 'desc', $perPage);

        $rows = [];
        foreach ($items as $e) {
            $conditions = [];
            foreach ((array) ($e->conditions ?: []) as $c) {
                $conditions[] = [
                    'tag'       => (string) ($c['tag'] ?? ''),
                    'type'      => (string) ($c['type'] ?? ''),
                    'tag_value' => (string) ($c['tag_value'] ?? ''),
                ];
            }
            $rows[] = [
                'id'         => (int) $e->id,
                'protocol'   => (string) $e->protocol,
                'message'    => (string) $e->message,
                'always'     => (bool) $e->always,
                'conditions' => $conditions,
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    public function meta(): JsonResponse
    {
        $this->checkException('custom_events', 'view');

        $protocols = [];
        foreach (TrackerPortRepo::getProtocolList() as $id => $name) {
            $protocols[] = ['id' => (string) $id, 'label' => (string) $name];
        }

        $types = [];
        foreach (ConditionService::getList() as $id => $label) {
            $types[] = ['id' => (string) $id, 'label' => (string) $label];
        }

        return response()->json([
            'ok'        => true,
            'protocols' => $protocols,
            'types'     => $types,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $this->helper($request->all())->create();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $data = $request->all();
            $data['id'] = (int) $id;
            $this->helper($data)->edit();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $this->helper(['id' => (int) $id])->destroy();

        return response()->json(['ok' => true]);
    }

    /* ----------------------------------------------------------------- */

    private function helper(array $data): CustomEventModalHelper
    {
        // conditions llega como array desde el request → re-serializar a JSON
        // porque el ModalHelper (api) hace json_decode de ese campo.
        if (isset($data['conditions']) && is_array($data['conditions'])) {
            $data['conditions'] = json_encode(array_values($data['conditions']));
        }

        $helper = app(CustomEventModalHelper::class);
        $helper->setUser($this->user);
        $helper->setApi(true);
        $helper->setData($data);

        return $helper;
    }
}
