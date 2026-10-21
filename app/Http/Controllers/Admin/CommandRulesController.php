<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\CommandRule;
use Tobuli\Entities\CommandRuleCondition;
use Tobuli\Entities\CommandRuleLog;
use Tobuli\Services\CommandRules\CommandRuleRunner;
use Tobuli\Services\CommandRules\CommandRuleService;
use Tobuli\Services\EntityLoader\DevicesGroupLoader;

/**
 * Admin > Normas de comando (solo administradores, ruta bajo auth.admin).
 */
class CommandRulesController extends Controller
{
    /** @var DevicesGroupLoader */
    protected $devicesLoader;

    /** @var CommandRuleService */
    protected $service;

    protected function afterAuth($user)
    {
        $this->devicesLoader = new DevicesGroupLoader($user);
        $this->devicesLoader->setRequestKey('devices');
        $this->service = new CommandRuleService();
    }

    public function index(Request $request)
    {
        $input = $request->all();
        $sort  = $input['sorting'] ?? ['sort_by' => 'name', 'sort' => 'asc'];

        $items = CommandRule::search($input['search_phrase'] ?? null)
            ->withCount('devices', 'conditions')
            ->with('user')
            ->toPaginator(20, $sort['sort_by'], $sort['sort']);

        return $this->api
            ? $items
            : View::make('admin::CommandRules.' . ($request->ajax() ? 'table' : 'index'))
                ->with(compact('items', 'input'));
    }

    public function create()
    {
        $item = new CommandRule([
            'active'           => 1,
            'interval_minutes' => 5,
            'only_connected'   => 1,
            'cooldown_minutes' => 0,
        ]);

        return View::make('admin::CommandRules.create')->with(compact('item') + $this->getFormData());
    }

    public function edit(int $id = null)
    {
        $item = $this->getItem($id);

        return View::make('admin::CommandRules.edit')->with(compact('item') + $this->getFormData());
    }

    public function store()
    {
        $this->service->save($this->data, $this->user, $this->devicesLoader);

        return new JsonResponse(['status' => 1]);
    }

    public function update(Request $request, int $id = null)
    {
        $item = $this->getItem($id ?: $request->get('id'));

        $this->service->save($this->data, $this->user, $this->devicesLoader, $item);

        return new JsonResponse(['status' => 1]);
    }

    public function destroy(Request $request, int $id = null)
    {
        $ids = (array) ($id ?: $request->get('id'));

        foreach (CommandRule::whereIn('id', $ids)->get() as $rule) {
            $this->service->delete($rule);
        }

        return new JsonResponse(['status' => 1]);
    }

    /** Selector de vehiculos (bootstrap-select con data-ajax), igual que plantillas de comandos. */
    public function devices(Request $request, $id = null)
    {
        if ($id && $rule = CommandRule::find($id)) {
            $this->devicesLoader->setQueryStored($rule->devices());
        }

        return response()->json($this->devicesLoader->get());
    }

    /** Ejecuta la consulta de la norma ahora mismo (sin esperar el intervalo). */
    public function run(Request $request, int $id = null)
    {
        $rule  = $this->getItem($id ?: $request->get('id'));
        $stats = (new CommandRuleRunner())->run($rule);

        return new JsonResponse([
            'status'  => 1,
            'message' => trans('admin.command_rules_run_result', $stats),
        ]);
    }

    /** Bitacora de una norma (modal). */
    public function logs(Request $request, int $id)
    {
        $item  = $this->getItem($id);
        $phase = $request->get('phase');

        $logs = CommandRuleLog::where('command_rule_id', $item->id)
            ->when($phase, fn ($q) => $q->where('phase', $phase))
            ->with(['device:id,name,imei', 'condition'])
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $phases = [];
        foreach (CommandRuleLog::PHASES as $p) {
            $phases[$p] = trans('admin.command_rules_phase_' . $p);
        }

        return View::make('admin::CommandRules.logs')->with(compact('item', 'logs', 'phases', 'phase'));
    }

    private function getFormData(): array
    {
        $matchTypes = [
            CommandRuleCondition::MATCH_CONTAINS => trans('admin.command_rules_match_contains'),
            CommandRuleCondition::MATCH_REGEX    => trans('admin.command_rules_match_regex'),
            CommandRuleCondition::MATCH_COMPARE  => trans('admin.command_rules_match_compare'),
        ];

        $operators = array_combine(CommandRuleCondition::OPERATORS, CommandRuleCondition::OPERATORS);

        $yesNo = [1 => trans('global.yes'), 0 => trans('global.no')];

        return compact('matchTypes', 'operators', 'yesNo');
    }

    private function getItem($id): CommandRule
    {
        return CommandRule::with('conditions')->findOrFail($id);
    }
}
