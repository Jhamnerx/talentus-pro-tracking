<?php

namespace App\Services\Ai\Tools\Actions;

use App\Services\Ai\ToolException;
use App\Services\Ai\Tools\ExportarReporteTool;
use Carbon\Carbon;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;

/**
 * Guarda un reporte con envio automatico por correo (diario, semanal o
 * mensual) usando la misma logica que el formulario de Reportes.
 */
class ProgramarReporteTool extends ActionTool
{
    const FREQUENCIES = [
        'diario'  => ['daily', 1, 'diario, con los datos del dia anterior'],
        'semanal' => ['weekly', 7, 'semanal, con los ultimos 7 dias'],
        'mensual' => ['monthly', 30, 'mensual, con los ultimos 30 dias'],
    ];

    public function name(): string
    {
        return 'programar_reporte';
    }

    public function description(): string
    {
        return 'Prepara un reporte que se envia automaticamente por correo cada dia, semana o mes. NO lo guarda: '
            . 'muestra una tarjeta que el usuario debe confirmar. Tipos: '
            . implode(', ', array_keys(ExportarReporteTool::TYPES)) . '.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => array_keys(ExportarReporteTool::TYPES)],
                'frecuencia' => ['type' => 'string', 'enum' => array_keys(self::FREQUENCIES)],
                'correos' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Destinatarios. Vacio = correo del usuario.'],
                'hora' => ['type' => 'string', 'description' => 'Hora de envio "HH:MM" (por defecto 08:00).'],
                'formato' => ['type' => 'string', 'enum' => ['pdf', 'excel'], 'description' => 'Por defecto excel.'],
                'unidades' => $this->unitsProperty(),
                'limite' => ['type' => 'number', 'description' => 'Limite de velocidad si el tipo lo necesita.'],
                'geocercas' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['tipo', 'frecuencia'],
            'additionalProperties' => false,
        ];
    }

    public function label(array $input): string
    {
        return 'Programar reporte · ' . (ExportarReporteTool::TYPES[$input['tipo'] ?? ''][1] ?? '') . ' · ' . ($input['frecuencia'] ?? '');
    }

    public static function allowed(User $user, array $params): bool
    {
        return (bool) $user->perm('reports', 'edit');
    }

    protected function prepare(array $input): array
    {
        if (!self::allowed($this->user, [])) {
            throw new ToolException('El usuario no tiene permiso para guardar reportes.');
        }

        [$typeId, $title, $needsLimit, $needsGeofences] = ExportarReporteTool::TYPES[$input['tipo']];
        [$flag, $days, $freqLabel] = self::FREQUENCIES[$input['frecuencia']];

        if ($needsLimit && empty($input['limite'])) {
            throw new ToolException("El reporte $title necesita un limite de velocidad.");
        }

        $emails = array_values(array_filter(array_map('trim', (array) ($input['correos'] ?? []))));
        if (!$emails) {
            $emails = [$this->user->email];
        }
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ToolException("Correo no valido: $email");
            }
        }

        $time = $input['hora'] ?? '08:00';
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ToolException('La hora debe tener formato HH:MM.');
        }

        $ids = $this->resolveDeviceIds($input['unidades'] ?? ['todas']);
        $today = Carbon::parse(\Formatter::time()->convert(date('Y-m-d H:i:s'), 'Y-m-d'));

        $data = [
            'title' => $title . ' - ' . $input['frecuencia'],
            'type' => $typeId,
            'format' => ($input['formato'] ?? 'excel') === 'pdf' ? 'pdf' : 'xls',
            'devices' => $ids,
            'send_to_email' => implode(';', $emails),
            'date_from' => $today->copy()->subDays($days)->format('Y-m-d'),
            'date_to' => $today->format('Y-m-d'),
            'from_time' => '00:00',
            'to_time' => '00:00',
            'show_addresses' => 1,
            'stops' => 60,
            $flag => 1,
            $flag . '_time' => $time,
        ];

        if ($needsLimit) {
            $data['speed_limit'] = (float) $input['limite'];
        }

        $details = [
            'Reporte: ' . $title . ' en ' . ($data['format'] === 'pdf' ? 'PDF' : 'Excel'),
            'Envio: ' . $freqLabel . ' a las ' . $time,
            'Para: ' . implode(', ', $emails),
            'Unidades: ' . (count($ids) > 3 ? count($ids) . ' unidades' : $this->unitsLabel($input)),
        ];

        if ($needsGeofences) {
            $names = (array) ($input['geocercas'] ?? []);
            $geofences = $names ? Geofence::userAccessible($this->user)->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('name', 'like', '%' . trim($name) . '%');
                }
            })->limit(50)->get() : collect();

            if ($geofences->isEmpty()) {
                throw new ToolException('Indica geocercas existentes para este reporte.');
            }
            $data['geofences'] = $geofences->pluck('id')->all();
            $details[] = 'Geocercas: ' . $geofences->pluck('name')->implode(', ');
        }

        return [
            'title' => 'Programar "' . $data['title'] . '"',
            'details' => $details,
            'confirm' => 'Programar envio',
            'params' => $data,
        ];
    }

    public static function execute(User $user, array $params): string
    {
        $helper = new \ModalHelpers\ReportModalHelper();
        $helper->setUser($user);
        $helper->setApi(true);
        $helper->setData($params);

        try {
            $helper->create();
        } catch (ValidationException $e) {
            throw new ToolException('No se pudo guardar el reporte: ' . self::errorsText($e));
        }

        return 'Reporte "' . $params['title'] . '" programado. Lo veras en Reportes > guardados.';
    }
}
