<?php

namespace App\Services\Ai;

class ClaudeException extends \Exception
{
    /** @var string */
    public $type;

    /** @var int */
    public $status;

    public function __construct(string $type, string $message, int $status = 0)
    {
        parent::__construct($message, $status);

        $this->type = $type;
        $this->status = $status;
    }
}
