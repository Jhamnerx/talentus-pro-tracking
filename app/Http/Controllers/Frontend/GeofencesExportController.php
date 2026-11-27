<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tobuli\Entities\Geofence;
use Tobuli\Exporters\EntityManager\Geofence\ExportManager;
use Tobuli\Exporters\Util\ExportTypesUtil;
use Tobuli\Services\GeofenceUserService;

class GeofencesExportController extends Controller
{
    public function index()
    {
        $exportTypes = ExportTypesUtil::getTranslations();
        $geofences = Geofence::userOwned($this->user)->pluck('name', 'id')->all();

        return view('front::Geofences.export')->with(compact('geofences', 'exportTypes'));
    }

    public function store(): BinaryFileResponse
    {
        $attributes = [
            'id',
            'group_id',
            'name',
            'coordinates',
            'polygon_color',
            'type',
            'category',
            'radius',
            'center',
            'device_id',
            // Limites de velocidad: el por defecto, los intervalos
            // (reports_geofleet_in) y, via decorate(), los limites por
            // tipo/estado de vehiculo con sus NOMBRES. Antes el .gexp los
            // perdia todos (fallo 10 de la QA legacy).
            'speed_limit',
            'additional',
        ];

        $geofences = Geofence::userOwned($this->user)
            ->with('group:id,title')
            ->with('vehicleSpeedLimits.vehicleType:id,name', 'vehicleSpeedLimits.vehicleState:id,name');

        return (new ExportManager($geofences))
            ->applyFilter($this->data['export_type'], $this->data)
            ->download($attributes, 'gexp');
    }

    public function getType()
    {
        $type = $this->data['type'];

        $data = (new GeofenceUserService($this->user))->getExportType($type);

        $this->data = $type === ExportTypesUtil::EXPORT_TYPE_GROUPS ? 'groups' : 'geofences';

        $input = $this->data;

        return view('front::Geofences.exportType')->with(array_merge($data, compact('input')));
    }
}
