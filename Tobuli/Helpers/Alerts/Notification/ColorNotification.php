<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\InputMeta;

class ColorNotification extends AbstractNotification implements InputAwareInterface
{
    use InputInitTrait;

    protected array $rules = [
        'input' => 'required|css_color',
    ];

    private bool $defaultInputActive = false;
    private ?string $defaultInputValue = '';

    public function getInput(array $alertData): InputMeta
    {
        return $this->initInput($alertData)
            ->setType(InputMeta::TYPE_COLOR)
            ->setTitle(trans('validation.attributes.color'));
    }
}