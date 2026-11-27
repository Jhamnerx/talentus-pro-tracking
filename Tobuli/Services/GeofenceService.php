<?php

namespace Tobuli\Services;

use Tobuli\Entities\Geofence;
use CustomFacades\Validators\GeofenceFormValidator;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\Operations\OperationService;

class GeofenceService extends ModelService
{
    public function __construct()
    {
        $this->setDefaults([
            'active'        => true,
            'type'          => 'polygon',
            'polygon_color' => '#d000df',
        ]);

        $this->setValidationRulesStore(
            GeofenceFormValidator::getFacadeRoot()->rules['create']
        );

        $this->setValidationRulesUpdate(
            GeofenceFormValidator::getFacadeRoot()->rules['update']
        );
    }

    public function store(array $data)
    {
        $geofence = Geofence::create($data);

        $this->syncVehicleSpeedLimits($geofence, $data);

        return $geofence;
    }

    public function update($geofence, array $data)
    {
        $geofence->update($data);

        $this->syncVehicleSpeedLimits($geofence, $data);

        // Operaciones: si cambio la forma, versiona los puntos que usan esta
        // geocerca (comparacion numerica dentro; un guardado sin mover no
        // crea version). Va aqui y no en edit() para cubrir v2, legacy, API
        // y DiemRateService.
        if ($geofence->wasChanged(['type', 'coordinates', 'center', 'radius', 'name'])) {
            OperationService::versionPointsForGeofence($geofence);
        }

        return $geofence;
    }

    protected function syncVehicleSpeedLimits(Geofence $geofence, array $data): void
    {
        if (!array_key_exists('vehicle_speed_limits', $data))
            return;

        $limits = is_array($data['vehicle_speed_limits']) ? $data['vehicle_speed_limits'] : [];

        // Se comprueba TODO antes de tocar la base: un tipo o estado que no
        // sea del dueño de la geocerca (o no exista) daba un 500 por la FK
        // con los limites ya borrados (fallo 9 de la QA legacy).
        $rows = [];
        $typeIds = array_filter(array_map('intval', array_keys($limits)));
        $types = $typeIds
            ? \Tobuli\Entities\VehicleType::where('user_id', $geofence->user_id)
                ->whereIn('id', $typeIds)->with('states:id,vehicle_type_id')->get()->keyBy('id')
            : collect();

        foreach ($limits as $vehicleTypeId => $byState) {
            if (!is_array($byState))
                continue;

            $type = $types->get((int) $vehicleTypeId);
            if (!$type) {
                throw new ValidationException(
                    ['vehicle_speed_limits' => [trans('validation.exists', ['attribute' => trans('validation.attributes.vehicle_type_id')])]]
                );
            }

            foreach ($byState as $vehicleStateId => $speedLimit) {
                if ($speedLimit === null || $speedLimit === '')
                    continue;

                $stateId = empty($vehicleStateId) ? null : (int) $vehicleStateId;
                if ($stateId !== null && !$type->states->contains('id', $stateId)) {
                    throw new ValidationException(
                        ['vehicle_speed_limits' => [trans('validation.exists', ['attribute' => trans('validation.attributes.vehicle_state_id')])]]
                    );
                }

                $rows[] = [
                    'vehicle_type_id'  => $type->id,
                    'vehicle_state_id' => $stateId,
                    'speed_limit'      => $speedLimit,
                ];
            }
        }

        $geofence->vehicleSpeedLimits()->delete();

        foreach ($rows as $row) {
            $geofence->vehicleSpeedLimits()->create($row);
        }
    }

    public function delete($geofence)
    {
        $this->checkNotOperationPoint($geofence);

        return $geofence->delete();
    }

    /**
     * Una geocerca que es punto vigente de una operacion no se puede borrar:
     * primero hay que quitarla de la operacion. Cubre v2, legacy (tambien el
     * borrado masivo) y la API, porque todos pasan por delete().
     */
    public function checkNotOperationPoint(Geofence $geofence): void
    {
        $operations = OperationService::operationsUsingGeofence($geofence);

        if ($operations->isEmpty())
            return;

        throw new ValidationException([
            'id' => trans('front.geofence_in_operation', [
                'name'       => $geofence->name,
                'operations' => $operations->pluck('name')->implode(', '),
            ]),
        ]);
    }

    /**
     * @param $data
     */
    protected function normalize(array $data)
    {
        if (array_key_exists('group_id', $data)) {
            if (empty($data['group_id'])) {
                $data['group_id'] = null;
            }
        }

        if (array_key_exists('category', $data)) {
            // El select manda "" para "sin categoria".
            if ($data['category'] === '' || $data['category'] === null) {
                $data['category'] = null;
            }
        }

        if (array_key_exists('polygon', $data)) {
            if (is_string($data['polygon'])) {
                $data['polygon'] = json_decode($data['polygon'], TRUE);
            }

            if (empty($data['polygon'])) {
                $data['polygon'] = [];
            }

            $data['polygon'] = array_map(function($point) {
                return [
                    'lat' => floatval($point['lat']),
                    'lng' => floatval($point['lng'])
                ];
            }, $data['polygon']);
        }

        if (array_key_exists('center', $data)) {
            if (is_string($data['center'])) {
                $data['center'] = json_decode($data['center'], TRUE);
            }
        }

        if (array_key_exists('speed_limit', $data)) {
            // Vacio significa "sin limite", no "limite de 0 km/h".
            // Formatter::speed()->reverse('') devuelve 0, y un 0 en los
            // limites POR TIPO si cuenta como limite real: todo vehiculo de
            // ese tipo quedaria en exceso permanente dentro de la geocerca.
            $data['speed_limit'] = ($data['speed_limit'] === null || $data['speed_limit'] === '')
                ? null
                : \Formatter::speed()->reverse($data['speed_limit']);
        }

        if (array_key_exists('vehicle_speed_limits', $data) && is_array($data['vehicle_speed_limits'])) {
            foreach ($data['vehicle_speed_limits'] as $vehicleTypeId => $byState) {
                foreach ($byState as $vehicleStateId => $speedLimit) {
                    $data['vehicle_speed_limits'][$vehicleTypeId][$vehicleStateId] = ($speedLimit === null || $speedLimit === '')
                        ? null
                        : \Formatter::speed()->reverse($speedLimit);
                }
            }
        }

        return $data;
    }



    /**
     * @param Geofence $geofence
     * @param $point
     * @return Geofence
     */
    public function moveTo(Geofence $geofence, $point)
    {
        if ($geofence->type == Geofence::TYPE_CIRCLE)
            return $this->moveCircleTo($geofence, $point);
        else
            return $this->movePolygonTo($geofence, $point);
    }

    /**
     * @param Geofence $geofence
     * @param $point
     * @return Geofence
     */
    public function moveCircleTo(Geofence $geofence, $point)
    {
        $geofence->center = [
            'lat' => $point['lat'],
            'lng' => $point['lng']
        ];

        return $geofence;
    }

    /**
     * @param Geofence $geofence
     * @param $point
     * @return Geofence
     */
    public function movePolygonTo(Geofence $geofence, $point)
    {
        $newCoordinates = [];

        $center = $geofence->getCenter();

        if (is_null($center))
            return $geofence;

        $coordinates = json_decode($geofence->coordinates, true);

        foreach ($coordinates as $coordinate) {
            $newCoordinates[] = [
                'lat' => $coordinate['lat'] - $center['lat'],
                'lng' => $coordinate['lng'] - $center['lng'],
            ];
        }

        array_walk($newCoordinates, function(&$coordinate, $key) use ($point) {
            $coordinate = [
                'lat' => $coordinate['lat'] + $point['lat'],
                'lng' => $coordinate['lng'] + $point['lng'],
            ];
        });

        $geofence->coordinates = json_encode($newCoordinates);

        return $geofence;
    }

    public function calculateSwNeBounds(Geofence $geofence): bool
    {
        if ($geofence->type === Geofence::TYPE_CIRCLE) {
            return false;
        }

        $swLat = 90;
        $neLat = -90;
        $swLng = 180;
        $neLng = -180;

        foreach (json_decode($geofence->coordinates, true) as $coordinate) {
            $lat = $coordinate['lat'];
            $lng = $coordinate['lng'];

            if ($lat < $swLat) {
                $swLat = $lat;
            }

            if ($lat > $neLat) {
                $neLat = $lat;
            }

            if ($lng < $swLng) {
                $swLng = $lng;
            }

            if ($lng > $neLng) {
                $neLng = $lng;
            }
        }

        $geofence->sw_lat = $swLat;
        $geofence->ne_lat = $neLat;
        $geofence->sw_lng = $swLng;
        $geofence->ne_lng = $neLng;

        return true;
    }
}