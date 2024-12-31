<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\SelectMeta;
use Tobuli\Services\AlertSoundService;

/**
 * @method SelectMeta initInput(array $alertData)
 */
class SoundNotification extends AbstractNotification implements InputAwareInterface
{
    use InputInitTrait;

    private string $inputClass = SelectMeta::class;
    private bool $defaultInputActive = true;
    private ?string $defaultInputValue = '';

    public function getInput(array $alertData): SelectMeta
    {
        return $this->initInput($alertData)
            ->setTitle(trans('validation.attributes.sound_notification'))
            ->setOptions(toOptions(AlertSoundService::getList()));
    }
}