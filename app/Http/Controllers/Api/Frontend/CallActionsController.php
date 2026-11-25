<?php
namespace App\Http\Controllers\Api\Frontend;

use App\Transformers\CallAction\CallActionFullTransformer;
use CustomFacades\Validators\CallActionFormValidator;
use App\Exceptions\ResourseNotFoundException;
use Formatter;
use Tobuli\Entities\CallAction;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use FractalTransformer;

class CallActionsController extends BaseController {

    public function __construct()
    {
        if (! settings('plugins.call_actions.status')) {
            abort(404);
        }

        parent::__construct();
    }

    protected function afterAuth($user)
    {
        $this->checkException('events', 'view');
    }

    public function index()
    {
        $this->checkException('call_actions', 'view');

        // Solo las atenciones que el usuario puede ver (como la web: byUser); antes se
        // listaban las de todos. Filtros opcionales por evento y dispositivo.
        $query = CallAction::byUser($this->user);

        if ($eventId = (int) ($this->data['event_id'] ?? 0)) {
            $query->where('event_id', $eventId);
        }
        if ($deviceId = (int) ($this->data['device_id'] ?? 0)) {
            $query->where('device_id', $deviceId);
        }

        $items = $query->orderByDesc('called_at')->orderByDesc('id')->paginate(30);

        return response()->json(array_merge(
            ['status' => 1],
            FractalTransformer::paginate($items, CallActionFullTransformer::class)
                ->toArray()
        ));
    }

    public function store()
    {
        $this->checkException('call_actions', 'store');

        // Igual que la web: alerta, dispositivo y usuario salen del evento y de la
        // sesión, no del cliente (antes la API guardaba la atención sin user_id y
        // el scope byUser la ocultaba).
        $event = Event::find($this->data['event_id'] ?? null);

        if (! $event) {
            throw new ResourseNotFoundException(trans('validation.attributes.event'));
        }

        $device = Device::find($event->device_id);
        $this->checkException('devices', 'show', $device);

        $this->data['alert_id'] = $event->alert_id;
        $this->data['device_id'] = $event->device_id;
        $this->data['user_id'] = $this->user->id;
        $this->data['called_at'] = empty($this->data['called_at'])
            ? null
            : Formatter::time()->reverse($this->data['called_at']);

        CallActionFormValidator::validate('create', $this->data);
        CallAction::create($this->data);

        return response()->json(['status' => 1]);
    }

    public function show($id)
    {
        $item = CallAction::find($id);
        $this->checkException('call_actions', 'view', $item);

        return response()->json(array_merge(
            ['status' => 1],
            FractalTransformer::item($item, CallActionFullTransformer::class)->toArray()
        ));
    }

    public function update($id)
    {
        $item = CallAction::find($id);
        $this->checkException('call_actions', 'update', $item);

        // Como store: evento, alerta, dispositivo y usuario no cambian ni vienen del
        // cliente; la hora llega en la zona del usuario.
        $this->data['event_id'] = $item->event_id;
        $this->data['alert_id'] = $item->alert_id;
        $this->data['device_id'] = $item->device_id;
        unset($this->data['user_id']);
        $this->data['called_at'] = empty($this->data['called_at'])
            ? null
            : Formatter::time()->reverse($this->data['called_at']);

        CallActionFormValidator::validate('update', $this->data);

        $item->update($this->data);

        return response()->json(['status' => 1]);
    }

    public function destroy($id)
    {
        $item = CallAction::find($id);
        $this->checkException('call_actions', 'remove', $item);
        $item->delete();

        return response()->json(['status' => 1]);
    }

    public function getResponseTypes()
    {
        return response()->json([
            'status' => 1,
            'types' => CallAction::getResponseTypes(),
        ]);
    }

    public function getEventTypes()
    {
        return response()->json([
            'status' => 1,
            'types' => Event::getTypeTitles(),
        ]);
    }
}
