<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;

class SilentNotification extends AbstractNotification implements InputAwareInterface
{
    use InputInitTrait;

    protected array $rules = [
        'input' => 'required|integer|min:1',
    ];

    private bool $defaultInputActive = false;
    private string $defaultInputValue = '0';

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setType(InputMeta::TYPE_INTEGER)
            ->setTitle(trans('validation.attributes.silent_notification'));
    }
}