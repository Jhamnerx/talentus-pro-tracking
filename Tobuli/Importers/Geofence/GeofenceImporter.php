<?php

namespace Tobuli\Importers\Geofence;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\GeofenceGroup;
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
        ];
    }
}
