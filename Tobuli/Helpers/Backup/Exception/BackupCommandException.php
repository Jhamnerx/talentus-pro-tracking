<?php

namespace Tobuli\Helpers\Backup\Exception;

/**
 * Fallo de un comando externo. A diferencia de ProcessFailedException, el mensaje no incluye la
 * línea de comandos (que puede llevar claves). El código de la excepción es siempre 0: el código
 * de salida va aparte para no confundirse con los códigos de error generales.
 */
class BackupCommandException extends \RuntimeException
{
    private int $exitCode;

    public function __construct(int $exitCode, string $errorOutput)
    {
        $this->exitCode = $exitCode;

        parent::__construct("Exit code $exitCode: " . trim($errorOutput), 0);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
