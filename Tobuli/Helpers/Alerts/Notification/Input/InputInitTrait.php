<?php

namespace Tobuli\Helpers\Alerts\Notification\Input;

use Illuminate\Support\Arr;

trait InputInitTrait
{
    private function initInput(array $alertData): InputMeta
    {
        $key = static::getKey();

        $alertData = Arr::get($alertData, $key);

        $meta = isset($this->inputClass)
            ? new $this->inputClass($key, '')
            : new InputMeta($key, '');

        return $meta
            ->setActive(Arr::get($alertData, 'active', $this->defaultInputActive))
            ->setInput(Arr::get($alertData, 'input', $this->defaultInputValue));
    }
}