<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ResourseNotFoundException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tobuli\Entities\Geofence;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\GeofenceUserService;
use Tobuli\Services\GroupModelService;

class GeofencesController extends Controller
{
    private GeofenceUserService $geofenceService;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $this->geofenceService = new GeofenceUserService($this->user);

            return $next($request);
        });
    }

    public function index()
    {
        try {
            $this->checkException('geofences', 'view');

            $geofences = Geofence::userOwned($this->user)
                ->filter(request()->all())
                ->search(request('search'))
                ->with('vehicleSpeedLimits')
                ->get();

            // Límites por tipo/estado de vehículo como lista, en la unidad del usuario (lo
            // mismo que aceptan add/edit_geofence en vehicle_speed_limits[tipo][estado|0]).
            $geofences->each(function (Geofence $g) {
                $limits = $g->vehicleSpeedLimits->map(fn ($l) => [
                    'vehicle_type_id'  => (int) $l->vehicle_type_id,
                    'vehicle_state_id' => $l->vehicle_state_id ? (int) $l->vehicle_state_id : null,
                    'speed_limit'      => round(\Formatter::speed()->convert($l->speed_limit)),
                ])->values()->all();

                $g->unsetRelation('vehicleSpeedLimits');
                $g->setAttribute('vehicle_speed_limits', $limits);
            });

            return ['items' => [
                'geofences' => $geofences
            ]];
        } catch (\Exception $e) {
            return ['items' => ['geofences' => []]];
        }
    }

    public function create()
    {
        if (!$this->user->perm('geofences', 'edit'))
            return ['status' => 0, 'perm' => 0];

        return ['status' => 1];
    }

    public function store()
    {
        $geofence = $this->geofenceService->create($this->data);

        return ['status' => 1, 'item' => $geofence];
    }

    public function update()
    {
        $model = Geofence::find($this->data['id']);

        $this->geofenceService->edit($model, $this->data);

        return ['status' => 1];
    }

    public function changeActive()
    {
        $validator = Validator::make($this->data, [
            'id' => 'required_without:group_id',
            'group_id' => 'required_without:id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        (new GroupModelService($this->user->geofences()))->changeActive(
            $this->data['id'] ?? false,
            $this->data['group_id'] ?? false,
            $this->data['active'] ?? 0
        );

        return ['status' => 1];
    }

    public function destroy()
    {
        $id = array_key_exists('geofence_id', $this->data) ? $this->data['geofence_id'] : $this->data['id'];

        $item = Geofence::find($id);

        $this->geofenceService->remove($item);

        return ['status' => 1];
    }

    public function pointIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->fails())
            return response()->json(['status' => 0, 'errors' => $validator->errors()]);

        if (!$this->user->geofences()->count())
            throw new ResourseNotFoundException(trans('front.geofences'));

        return response()->json([
            'status' => 1,
            'zones'  => $this->user->geofences()->containPoint($request->lat, $request->lng)->pluck('name')->all()
        ]);
    }
}
