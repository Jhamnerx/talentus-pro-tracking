<?php

namespace Tobuli\Helpers\Backup\Process;

use Tobuli\Entities\BackupProcess;

class DatabaseBackuper extends AbstractBackuper
{
    protected function backup($item): bool
    {
        // MYSQL_PWD en vez de -p'...': con contraseña vacía "-p''" queda en "-p" y mysqldump
        // pide la clave por terminal (sin TTY no vuelca nada); y una comilla en la clave rompe el comando.
        $command = "MYSQL_PWD=" . escapeshellarg((string)$item['password'])
            . " mysqldump"
            . " --single-transaction=TRUE"
            . " --lock-tables=false"
            . " -u " . escapeshellarg($item['username'])
            . " -h " . escapeshellarg($item['host'])
            . " --port=" . escapeshellarg($item['port'])
            . " --databases " . escapeshellarg($item['database']);

        $this->uploader->process($command, $this->process, $item);

        return true;
    }

    protected function getItems(): array
    {
        return [\DB::connection($this->process->source)->getConfig()];
    }

    public static function makeProcess(string $source, array $options = []): BackupProcess
    {
        return new BackupProcess([
            'type'              => static::class,
            'source'            => $source,
            'options'           => $options,
            'duration_expire'   => 3600,
            'total'             => 1,
        ]);
    }
}