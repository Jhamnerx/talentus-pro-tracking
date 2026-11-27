<?php

namespace Tobuli\Helpers\Alerts\Notification;

use App\Jobs\SendAlertReport;
use Illuminate\Support\Arr;
use Tobuli\Entities\Alert;
use Tobuli\Entities\SendQueue;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;
use Tobuli\Helpers\Alerts\Notification\Send\SendingInterface;

/**
 * Manda por correo un reporte del vehiculo centrado en el evento: X minutos
 * antes y X minutos despues, con el tipo y el formato que diga la alerta.
 *
 * No genera nada aqui. El evento acaba de ocurrir, asi que la parte "despues"
 * todavia no ha pasado: encolar un trabajo retrasado es la unica forma de que
 * el reporte incluya lo que aun no ha llegado. Ademas generar un reporte es
 * caro y este metodo corre dentro del bucle que vacia la cola de envios.
 */
class ReportNotification extends AbstractNotification implements InputAwareInterface, SendingInterface
{
    /** Margen sobre la ventana, para dar tiempo a que lleguen las tramas. */
    private const MARGEN_SEGUNDOS = 60;

    public function __construct()
    {
        $this->rules = [
            'input'   => 'required|array_max:' . config('tobuli.limits.alert_emails'),
            'input.*' => 'email',
        ];
    }

    public function getInput(Alert $alert): InputMeta
    {
        $key = static::getKey();

        $alertData = Arr::get($alert->notifications ?? [], $key);

        return (new InputMeta($key, trans('front.alert_report_notification')))
            ->setActive(Arr::get($alertData, 'active', false))
            ->setInput(Arr::get($alertData, 'input', ''))
            ->setType(InputMeta::TYPE_STRING)
            ->setDescription(trans('front.email_semicolon'));
    }

    protected function prepareDataForValidation(array &$data): void
    {
        $data['input'] = semicol_explode(Arr::get($data, 'input'));
    }

    public function canSend(SendQueue $sendQueue): bool
    {
        return $this->isEnabled($sendQueue->user);
    }

    public function send(SendQueue $sendQueue, $receiver): void
    {
        $event = $sendQueue->data;

        if (empty($event->alert_id) || empty($event->device_id)) {
            return;
        }

        $alert = Alert::find($event->alert_id);

        if (! $alert || ! $alert->report_type) {
            return;
        }

        $retraso = ((int) $alert->report_minutes_after) * 60 + self::MARGEN_SEGUNDOS;

        dispatch(
            (new SendAlertReport(
                (int) $event->device_id,
                (int) $sendQueue->user_id,
                (int) $alert->id,
                (string) $event->time,
                (array) $receiver
            ))->delay($retraso)
        );
    }
}
