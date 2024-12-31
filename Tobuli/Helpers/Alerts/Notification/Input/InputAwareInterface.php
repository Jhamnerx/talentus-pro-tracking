<?php

namespace Tobuli\Helpers\Alerts\Notification\Input;

interface InputAwareInterface
{
    public function getInput(array $alertData): InputMeta;
}