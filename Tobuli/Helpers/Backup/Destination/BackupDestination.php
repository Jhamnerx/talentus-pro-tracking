<?php

namespace Tobuli\Helpers\Backup\Destination;

use Tobuli\Entities\BackupProcess;

interface BackupDestination
{
    public const FTP = 'ftp';
    public const DRIVE = 'drive';

    public function name(): string;

    public function check(): bool;

    /**
     * @throws \Throwable si no se pudo subir
     */
    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void;

    /**
     * @return string[] nombres de carpeta de primer nivel que empiezan por $prefix
     */
    public function listFolders(string $prefix): array;

    public function exists(string $remotePath): bool;

    /**
     * @throws \Throwable si no se pudo descargar
     */
    public function download(string $remotePath, string $localPath): void;

    /**
     * @return string|null contenido del archivo, o null si no existe
     */
    public function read(string $remotePath): ?string;
}
