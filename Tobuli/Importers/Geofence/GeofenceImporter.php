<?php

namespace Tobuli\Importers\Geofence;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\GeofenceGroup;
use Tobuli\Entities\VehicleState;
use Tobuli\Entities\VehicleType;
use Tobuli\Importers\Importer;
use Tobuli\Services\GeofenceService;

class GeofenceImporter extends Importer
{
    private $geofenceService;

    public function __construct()
    {
        $this->geofenceService = new GeofenceService();
    }

    protected $defaults = [
        'active'        => 1,
        'type'          => 'polygon',
        'polygon_color' => '#ffffff',
    ];

    protected function getDefaults()
    {
        return $this->defaults;
    }

    protected function importItem($data, $attributes = [])
    {
        $data = $this->mergeDefaults($data);
        $data = $this->setUser($data, $attributes);
        $data = $this->normalize($data);

        if ( ! $this->validate($data)) {
            return;
        }

        if ($this->getGeofence($data)) {
            return;
        }

        $this->create($data);
    }

    private function normalize(array $data): array
    {
        if (!empty($data['polygon']) && is_array($data['polygon'])) {
            $last_point = last($data['polygon']);
            $first_point = head($data['polygon']);

            if ($last_point != $first_point) {
                $data['polygon'][] = $first_point;
            }
        }

        if (!empty($data['type'])) {
            $data['type'] = strtolower($data['type']);
        }

        // El importador llama a store() directo y se salta normalize() del service.
        if (array_key_exists('category', $data) && ($data['category'] === '' || $data['category'] === null)) {
            $data['category'] = null;
        }

        unset($data['group_id']);

        if (!empty($data['center'])) {
            $data['center'] = $this->parseCenter($data['center']);
        }

        if (array_key_exists('speed_limit', $data) && ($data['speed_limit'] === '' || $data['speed_limit'] === null)) {
            $data['speed_limit'] = null;
        }

        if (array_key_exists('additional', $data) && is_string($data['additional'])) {
            $data['additional'] = json_decode($data['additional'], true);
        }

        return $data;
    }

    /**
     * Limites por tipo/estado de vehiculo del .gexp: vienen por NOMBRE
     * (el exportador no manda ids, que no valen en otra cuenta). Se
     * resuelven contra los tipos del usuario que importa y, si no existen,
     * se crean; el resultado es el formato [tipo_id][estado_id|0] = limite
     * que entiende GeofenceService::syncVehicleSpeedLimits().
     */
    private function resolveVehicleSpeedLimits(array $data): array
    {
        if (empty($data['vehicle_speed_limits']) || !is_array($data['vehicle_speed_limits']) || empty($data['user_id'])) {
            unset($data['vehicle_speed_limits']);
            return $data;
        }

        $byId = [];

        foreach ($data['vehicle_speed_limits'] as $row) {
            if (!is_array($row) || empty($row['vehicle_type']) || !isset($row['speed_limit']) || !is_numeric($row['speed_limit'])) {
                continue;
            }

            $typeKey = md5("vt.{$data['user_id']}." . mb_strtolower(trim($row['vehicle_type'])));
            $type = Cache::store('array')->rememberForever($typeKey, function () use ($data, $row) {
                return VehicleType::firstOrCreate(
                    ['user_id' => $data['user_id'], 'name' => trim($row['vehicle_type'])]
                );
            });

            $stateId = 0;
            if (!empty($row['vehicle_state'])) {
                $stateKey = md5("vs.{$type->id}." . mb_strtolower(trim($row['vehicle_state'])));
                $state = Cache::store('array')->rememberForever($stateKey, function () use ($type, $row) {
                    return VehicleState::firstOrCreate(
                        ['vehicle_type_id' => $type->id, 'name' => trim($row['vehicle_state'])]
                    );
                });
                $stateId = $state->id;
            }

            $byId[$type->id][$stateId] = $row['speed_limit'];
        }

        $data['vehicle_speed_limits'] = $byId;

        return $data;
    }

    private function parseCenter($data): array
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data)) {
            $center = [];
        } elseif (isset($data['lat']) && isset($data['lng'])) {
            $center = Arr::only($data, ['lat', 'lng']);
        } else {
            $center = ['lat' => reset($data), 'lng' => next($data)];
        }

        return $center;
    }

    private function getGeofence($data)
    {
        return Geofence::where(Arr::only($data, ['user_id', 'name', 'type']))->first();
    }

    private function create($data)
    {
        beginTransaction();
        try {
            if ( ! empty($data['group'])) {
                $this->createGroup($data);
            } else {
                $data['group_id'] = null;
            }

            $data = $this->resolveVehicleSpeedLimits($data);

            $this->geofenceService->store($data);
        } catch (\Exception $e) {
            rollbackTransaction();
            throw $e;
        }
        commitTransaction();
    }

    private function createGroup(& $data)
    {
        $key = md5("{$data['user_id']}.{$data['group']}");

        $data['group_id'] = Cache::store('array')->rememberForever($key, function() use ($data) {
            $group =  GeofenceGroup::firstOrCreate([
                'title'   => $data['group'],
                'user_id' => $data['user_id']
            ]);

            return $group->id;
        });

        unset($data['group']);
    }

    public function getValidationBaseRules(): array
    {
        return [
            'name'          => 'required',
            // 'line' es el corredor: linea + radio, como en GeofenceFormValidator.
            // Sin el, un GEXP con corredores abortaba el import entero.
            'type'          => 'required|in:polygon,circle,line',
            'category'      => 'nullable|in:carga,descarga,inicio_ruta,fin_ruta,grifo,taller,descanso',
            'polygon'       => 'required_if:type,polygon,line|nullable|array',
            'polygon.*.lat' => 'required_if:type,polygon,line|lat',
            'polygon.*.lng' => 'required_if:type,polygon,line|lng',
            'radius'        => 'required_if:type,circle,line|nullable|numeric',
            'center'        => 'required_if:type,circle|nullable|array',
            'center.lat'    => 'required_if:type,circle|lat',
            'center.lng'    => 'required_if:type,circle|lng',
            'speed_limit'   => 'nullable|numeric|min:1',
            'additional'    => 'nullable|array',
            'vehicle_speed_limits'               => 'nullable|array',
            'vehicle_speed_limits.*.vehicle_type'  => 'required|string',
            'vehicle_speed_limits.*.vehicle_state' => 'nullable|string',
            'vehicle_speed_limits.*.speed_limit'   => 'required|numeric|min:1',
        ];
    }
}
