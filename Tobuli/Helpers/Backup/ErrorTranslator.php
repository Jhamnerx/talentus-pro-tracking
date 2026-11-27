<?php

namespace Tobuli\Helpers\Backup;

class ErrorTranslator
{
    public function translate(\Throwable $e): string
    {
        return trans('front.unexpected_error');
    }
}
