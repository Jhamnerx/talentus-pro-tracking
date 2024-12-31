<?php

namespace Tobuli\Helpers\Alerts\Notification;

use Illuminate\Support\Arr;
use Tobuli\Helpers\Alerts\Notification\Input\InputAwareInterface;
use Tobuli\Helpers\Alerts\Notification\Input\InputInitTrait;
use Tobuli\Helpers\Alerts\Notification\Input\SelectMeta;

/**
 * @method SelectMeta initInput(array $alertData)
 */
class PopupNotification extends AbstractNotification implements InputAwareInterface
{
    use InputInitTrait;

    protected array $rules = [
        'input' => 'required|in:0,5,10',
    ];

    private string $inputClass = SelectMeta::class;
    private bool $defaultInputActive = true;
    private int $defaultInputValue = 10;

    public function getInput(array $alertData): SelectMeta
    {
        return $this->initInput($alertData)
            ->setInput(Arr::get($alertData, 'popup.input', Arr::get($alertData, 'auto_hide.active', true) ? 10 : 0))
            ->setTitle(trans('validation.attributes.popup_notification'))
            ->setOptions(toOptions([
                0 => trans('front.sticky'),
                5 => '5 ' . trans('front.second_short'),
                10 => '10 ' . trans('front.second_short'),
            ]));
    }
}