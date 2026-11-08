<?php

namespace Tobuli\Helpers\Backup;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tobuli\Entities\BackupProcess;

class BackupCommandRunner
{
    /**
     * Ejecuta una línea de shell con pipefail: sin él, "mysqldump | gzip" devolvería el código
     * del último comando y un volcado fallido parecería correcto.
     */
    public static function shell(string $command, ?BackupProcess $keepAlive = null, array $env = []): Process
    {
        $process = Process::fromShellCommandline('bash -o pipefail -c ' . escapeshellarg($command), null, $env ?: null);

        return self::run($process, $keepAlive);
    }

    /**
     * Ejecuta un comando sin shell, con sus argumentos ya separados.
     */
    public static function args(array $command, ?BackupProcess $keepAlive = null): Process
    {
        return self::run(new Process($command), $keepAlive);
    }

    private static function run(Process $process, ?BackupProcess $keepAlive): Process
    {
        $process->setTimeout(null);
        $process->start();

        $timer = 0;

        while ($process->isRunning()) {
            sleep(1);
            $timer++;

            if ($keepAlive && ($timer % BackupProcess::DURATION_ALIVE === 0)) {
                $keepAlive->updateReservation();
            }
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }
}
