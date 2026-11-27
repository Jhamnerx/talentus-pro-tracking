<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tobuli\Entities\Geofence;
use Tobuli\Reports\ReportManager;

/**
 * Genera el reporte oficial de la plataforma (PDF, XLS o HTML) y agrega a la
 * respuesta un boton de descarga. El archivo queda en storage/cache y se
 * entrega solo al mismo usuario durante una hora.
 */
class ExportarReporteTool extends AbstractTool
{
    /** clave => [type_id, nombre, necesita limite, necesita geocercas] */
    const TYPES = [
        'informacion_general'  => [1, 'Informacion general', false, false],
        'recorridos_y_paradas' => [3, 'Recorridos y paradas', false, false],
        'excesos_velocidad'    => [5, 'Exceso de velocidad', true, false],
        'geocercas'            => [7, 'Geocercas entradas/salidas', false, true],
        'eventos'              => [8, 'Eventos', false, false],
        'cargas_combustible'   => [11, 'Cargas de combustible', false, false],
        'robos_combustible'    => [12, 'Robos de combustible', false, false],
        'conduccion_rag'       => [14, 'Conduccion (RAG)', true, false],
        'horas_motor'          => [29, 'Horas de motor', false, false],
        'paradas'              => [40, 'Paradas', false, false],
    ];

    const FORMATS = ['pdf' => 'pdf', 'excel' => 'xls', 'html' => 'html'];

    public function name(): string
    {
        return 'exportar_reporte';
    }

    public function description(): string
    {
        return 'Genera el reporte completo de la plataforma y agrega un boton para descargarlo (PDF, Excel '
            . 'o HTML). Usalo cuando el usuario quiera el detalle completo o un archivo. Tipos: '
            . implode(', ', array_keys(self::TYPES)) . '.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => array_keys(self::TYPES)],
                'formato' => ['type' => 'string', 'enum' => array_keys(self::FORMATS), 'description' => 'Por defecto pdf.'],
                'unidades' => $this->unitsProperty(),
            ] + $this->periodProperties() + [
                'limite' => ['type' => 'number', 'description' => 'Limite de velocidad (excesos_velocidad y conduccion_rag).'],
                'geocercas' => [
                    'type' => 'array', 'items' => ['type' => 'string'],
                    'description' => 'Nombres de geocercas (obligatorio para el tipo geocercas).',
                ],
            ],
            'required' => ['tipo', 'desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        $type = self::TYPES[$input['tipo'] ?? ''][1] ?? ($input['tipo'] ?? '');

        return implode(' · ', array_filter([
            'Genere reporte ' . $type, strtoupper($input['formato'] ?? 'pdf'),
            $this->periodLabel($input), $this->unitsLabel($input),
        ]));
    }

    protected function run(array $input): array
    {
        if (!$this->user->perm('reports', 'view')) {
            throw new ToolException('El usuario no tiene permiso para reportes.');
        }

        [$typeId, $title, $needsLimit, $needsGeofences] = self::TYPES[$input['tipo']];
        $format = self::FORMATS[$input['formato'] ?? 'pdf'] ?? 'pdf';

        if ($needsLimit && empty($input['limite'])) {
            throw new ToolException("El reporte $title necesita un limite de velocidad.");
        }

        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = $this->parsePeriod($input);

        $manager = new ReportManager();
        $report = $manager->report($typeId);

        if (!$report::checkUsable($this->user)) {
            throw new ToolException("El reporte $title no esta habilitado para este usuario.");
        }

        $data = [
            'type' => $typeId,
            'user' => $this->user,
            'format' => $format,
            'date_from' => $fromUtc,
            'date_to' => $toUtc,
            'devices' => $this->devicesQuery()->whereIn('devices.id', $ids),
            'show_addresses' => true,
        ];

        if ($needsLimit) {
            $data['speed_limit'] = (float) $input['limite'];
        }

        if ($needsGeofences) {
            $names = (array) ($input['geocercas'] ?? []);
            if (!$names) {
                throw new ToolException('Indica las geocercas para el reporte de geocercas.');
            }
            $geofences = Geofence::userAccessible($this->user)->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('name', 'like', '%' . trim($name) . '%');
                }
            })->limit(50)->get();

            if ($geofences->isEmpty()) {
                throw new ToolException('No encontre esas geocercas.');
            }
            $data['geofences'] = $geofences;
        }

        $report = $manager->from($data);
        $path = $report->save();

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $token = Str::random(40);

        Cache::put('tpai.download.' . $token, [
            'path' => $path,
            'user_id' => $this->user->id,
            'name' => Str::slug($title . ' ' . substr($fromLocal, 0, 10) . ' ' . substr($toLocal, 0, 10)) . '.' . $extension,
        ], now()->addHour());

        $this->actions[] = [
            'type' => 'download',
            'url' => route('ai.download', $token),
            'label' => 'Descargar ' . ($format === 'xls' ? 'Excel' : strtoupper($format)),
        ];

        return [
            'reporte' => $title,
            'formato' => $format,
            'periodo' => "$fromLocal a $toLocal",
            'unidades' => count($ids),
            'resultado' => 'Reporte generado. El usuario tiene un boton para descargarlo (valido 1 hora).',
        ];
    }
}
