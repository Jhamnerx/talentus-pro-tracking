<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Arr;
use Tobuli\Entities\Device;
use Tobuli\Entities\SendQueue;
use Tobuli\Entities\Sharing;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;
use Tobuli\Helpers\Alerts\Notification\Send\SendException;
use Tobuli\Helpers\Alerts\Notification\Send\SendingInterface;
use Tobuli\Services\SharingService;

class SharingSmsNotification extends AbstractNotification implements InputAwareInterface, SendingInterface
{
    use InputInitTrait;

    private bool $defaultInputActive = false;
    private ?string $defaultInputValue = '';
    private SharingService $sharingService;

    public function __construct()
    {
        $this->sharingService = new SharingService();
        $this->rules = [
            'input' => 'required|array',
        ];
    }

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setType(InputMeta::TYPE_STRING)
            ->setTitle(trans('validation.attributes.sharing_sms'))
            ->setDescription(trans('front.sms_semicolon'));
    }

    public function canSend(SendQueue $sendQueue): bool
    {
        return $this->isEnabled($sendQueue->user);
    }

    public function isEnabled(User $user): bool
    {
        return $user->canSendSMS()
            && settings('plugins.alert_sharing.status')
            && $user->can('create', new Sharing());
    }

    protected function prepareDataForValidation(array &$data): void
    {
        $data['input'] = semicol_explode(Arr::get($data, 'input'));
    }

    public function send(SendQueue $sendQueue, $receiver): void
    {
        $plugin = settings('plugins.alert_sharing.options');

        $sharingData = [
            'expiration_date'         => null,
            'delete_after_expiration' => Arr::get($plugin, 'delete_after_expiration.status')
        ];

        if (Arr::get($plugin, 'duration.active') && Arr::get($plugin, 'duration.value')) {
            $sharingData['expiration_date'] = Carbon::now()->addMinutes(
                Arr::get($plugin,
                    'duration.value'));
        }

        $sharing = $this->sharingService->create($sendQueue->user_id, $sharingData);

        $device = $sendQueue->data instanceof Device ? $sendQueue->data : $sendQueue->data->device;

        $this->sharingService->addDevices($sharing, $device);

        try {
            $this->sharingService->sendSms($sharing, $receiver);
        } catch (ConnectException | ClientException | ServerException | ValidationException $e) {
            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }
    }
}