<?php

namespace App\Jobs;

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Formatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Entities\EmailTemplate;
use Tobuli\Entities\User;
use Tobuli\Reports\ReportManager;

/**
 * Genera y manda por correo el reporte que acompana a un evento de alerta.
 *
 * Corre retrasado a proposito (lo encola ReportNotification): la ventana del
 * reporte llega hasta X minutos DESPUES del evento, asi que en el momento del
 * evento esa parte todavia no existe.
 *
 * Solo se guardan ids y la hora del evento, no entidades: entre el encolado y
 * la ejecucion pasan minutos y el vehiculo, la alerta o el usuario pueden
 * haber cambiado. Se leen frescos al ejecutar.
 */
class SendAlertReport extends Job implements ShouldQueue
{
    use InteractsWithQueue;
    use SerializesModels;

    private int $deviceId;
    private int $userId;
    private int $alertId;
    private string $eventTime;
    private array $recipients;

    public function __construct(int $deviceId, int $userId, int $alertId, string $eventTime, array $recipients)
    {
        $this->deviceId   = $deviceId;
        $this->userId     = $userId;
        $this->alertId    = $alertId;
        $this->eventTime  = $eventTime;
        $this->recipients = $recipients;
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        $alert = Alert::find($this->alertId);
        $device = Device::find($this->deviceId);

        if (! $user || ! $alert || ! $device || ! $alert->report_type) {
            return;
        }

        $destinos = array_filter($this->recipients, function ($correo) {
            return filter_var($correo, FILTER_VALIDATE_EMAIL);
        });

        if (! $destinos) {
            return;
        }

        setActingUser($user);
        Formatter::byUser($user);

        // La hora del evento esta en UTC, igual que el rango que espera el
        // reporte: la ventana se calcula sin pasar por la hora del usuario.
        $evento = strtotime($this->eventTime);

        if (! $evento) {
            return;
        }

        $desde = date('Y-m-d H:i:s', $evento - ((int) $alert->report_minutes_before) * 60);
        $hasta = date('Y-m-d H:i:s', $evento + ((int) $alert->report_minutes_after) * 60);

        $fichero = null;

        try {
            $reporte = (new ReportManager())->from([
                'type'      => (int) $alert->report_type,
                'user'      => $user,
                'format'    => $alert->report_format ?: 'pdf',
                'date_from' => $desde,
                'date_to'   => $hasta,
                'devices'   => Device::whereIn('devices.id', [$device->id]),
                'geofences' => $user->geofences,
            ]);

            if (! $reporte->checkUsable($user)) {
                return;
            }

            $fichero = $reporte->save();

            if (! $fichero) {
                return;
            }

            $plantilla = EmailTemplate::getTemplate('report', $user);

            $datos = [
                'title'      => $alert->name . ' · ' . $device->name,
                'date_from'  => Formatter::time()->human($desde),
                'date_to'    => Formatter::time()->human($hasta),
                'device'     => $device->name,
                'alert'      => $alert->name,
                'event_time' => Formatter::time()->human($this->eventTime),
            ];

            sendTemplateEmail(implode(';', $destinos), $plantilla, $datos, [$fichero]);
        } catch (\Throwable $e) {
            // Un reporte que falla no puede tumbar la cola de trabajos: se
            // reporta y se sigue.
            Bugsnag::notifyException($e);
        } finally {
            if ($fichero && is_file($fichero)) {
                @unlink($fichero);
            }
        }
    }
}
