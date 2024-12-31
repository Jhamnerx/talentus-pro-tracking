<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Illuminate\Support\Arr;
use Tobuli\Entities\EmailTemplate;
use Tobuli\Entities\SendQueue;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;
use Tobuli\Helpers\Alerts\Notification\Send\SendingInterface;

class EmailNotification extends AbstractNotification implements InputAwareInterface, SendingInterface
{
    use InputInitTrait;

    public function __construct()
    {
        $this->rules = [
            'input'   => 'required|array_max:' . config('tobuli.limits.alert_emails'),
            'input.*' => 'email',
        ];
    }

    private bool $defaultInputActive = false;
    private ?string $defaultInputValue = '';

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setType(InputMeta::TYPE_STRING)
            ->setTitle(trans('validation.attributes.email_notification'))
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
        $template = EmailTemplate::getTemplate($sendQueue->type, $sendQueue->user, 'event');

        sendTemplateEmail($receiver, $template, $sendQueue->data);
    }
}