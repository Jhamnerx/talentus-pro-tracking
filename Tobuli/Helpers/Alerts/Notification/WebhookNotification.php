<?php

namespace Tobuli\Helpers\Alerts\Notification;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Arr;
use Tobuli\Entities\Device;
use Tobuli\Entities\SendQueue;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;
use Tobuli\Helpers\Alerts\Notification\Send\SendException;
use Tobuli\Helpers\Alerts\Notification\Send\SendingInterface;
use Tobuli\Helpers\ParsedCurl;

class WebhookNotification extends AbstractNotification implements InputAwareInterface, SendingInterface
{
    use InputInitTrait;

    private bool $defaultInputActive = false;
    private ?string $defaultInputValue = '';

    public function __construct()
    {
        $this->rules = [
            'input'   => 'required|array_max:' . config('tobuli.limits.alert_webhooks'),
            'input.*' => 'curl_request',
        ];
    }

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setType(InputMeta::TYPE_STRING)
            ->setTitle(trans('validation.attributes.webhook_notification'))
            ->setDescription(trans('front.webhook'));
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
        $data = $sendQueue->data->toArray();

        $data['user'] = [
            'id'    => $sendQueue->user->id,
            'email' => $sendQueue->user->email,
            'phone_number' => $sendQueue->user->phone_number,
        ];

        if (!empty($data['latitude']) && !empty($data['longitude'])) {
            $data['address'] = getGeoAddress($data['latitude'], $data['longitude']);
        }

        $data['geofence'] = $sendQueue->data->geofence;

        $device = $sendQueue->data instanceof Device ? $sendQueue->data : $sendQueue->data->device;

        if ($device) {
            $data['device'] = $device->toArray();
            $data['sensors'] = $device->sensors->map(function ($sensor) use ($device) {
                $value = $sensor->getValueCurrent($device);

                return [
                    'id' => (int)$sensor->id,
                    'type' => $sensor->type,
                    'name' => $sensor->formatName(),
                    'unit' => $sensor->getUnit(),
                    'value' => $value->getValue(),
                    'formatted' => $value->getFormatted(),
                ];
            })->all();
        }

        unset($data['device']['traccar']);

        $curl = new ParsedCurl($receiver);

        try {
            sendWebhook($curl->getFullUrl(), $data, $curl->getHeaders());
        } catch (ConnectException | ClientException | ServerException $e) {
            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }
    }
}