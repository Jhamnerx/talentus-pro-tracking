<?php

namespace Tobuli\Helpers\Alerts\Notification;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Tobuli\Entities\Device;
use Tobuli\Entities\SendQueue;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;
use Tobuli\Helpers\Alerts\Notification\Send\SendException;
use Tobuli\Helpers\Alerts\Notification\Send\SendingInterface;
use Tobuli\Helpers\Formatter\Facades\Formatter;
use Tobuli\Services\FcmService;

class PushNotification extends AbstractNotification implements InputAwareInterface, SendingInterface
{
    use InputInitTrait;

    private bool $defaultInputActive = true;
    private ?string $defaultInputValue = null;
    private FcmService $fcmService;

    public function __construct()
    {
        $this->fcmService = new FcmService();
    }

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setInput(null)
            ->setTitle(trans('validation.attributes.push_notification'));
    }

    public function canSend(SendQueue $sendQueue): bool
    {
        return $this->isEnabled($sendQueue->user);
    }

    public function send(SendQueue $sendQueue, $receiver): void
    {
        $device = $sendQueue->data instanceof Device ? $sendQueue->data : $sendQueue->data->device;

        switch ($sendQueue->type) {
            case 'expiring_user':
            case 'expired_user':
                $type = 'front.'.$sendQueue->type;
                $title = trans($type).' '.$sendQueue->user->email;
                $body = $sendQueue->user->email;
                break;
            case 'expiring_device':
            case 'expired_device':
            case 'expiring_sim':
            case 'expired_sim':
                $type = 'front.'.$sendQueue->type;
                $title = trans($type).' '.$device->name;
                $body = $device->name;
                break;
            default:
                $title = ($device->name ?? '') . ' ' . $sendQueue->data->message;
                $body = trans('front.speed') . ': ' . Formatter::speed()->human($sendQueue->data->speed);

                if (in_array($sendQueue->type, ['zone_out', 'zone_in'])) {
                    $body .= "\n" . trans('front.geofence') . ': ' . $sendQueue->data->geofence->name;

                    $sendQueue->data->makeHidden('geofence');
                }
                break;
        }

        $data = $sendQueue->data ? $sendQueue->data->toArray() : [];
        $type = $sendQueue->type;

        try {
            $this->fcmService->send($sendQueue->user, $title, $body, $data, $type);
        } catch (ConnectException | ClientException | ServerException $e) {
            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }
    }
}