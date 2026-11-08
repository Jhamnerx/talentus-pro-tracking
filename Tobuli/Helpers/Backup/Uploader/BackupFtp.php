<?php

namespace Tobuli\Helpers\Backup\Uploader;

use Illuminate\Support\Facades\Cache;
use Tobuli\Helpers\Backup\Exception\BackupCommandException;
use Tobuli\Clients\FtpHandler;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupCommandRunner;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\FileMeta;

class BackupFtp implements BackupDestination
{
    private const UPLOAD_ATTEMPTS = 3;
    private const MISSING_DIR_EXIT_CODE = 14;

    private FtpHandler $handler;

    public function __construct($host, $user, $pass, $port, $path)
    {
        $this->handler = new FtpHandler($host, $user, $pass, $port, rtrim($path, '/') . '/');
    }

    public function name(): string
    {
        return self::FTP;
    }

    public function getHost()
    {
        return $this->handler->getHost();
    }

    public function check(): bool
    {
        try {
            return $this->handler->getConn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testCommand(): void
    {
        BackupCommandRunner::shell('echo "test" | ' . $this->uploadCommand(time() . 'test.txt'));
    }

    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void
    {
        $command = $this->uploadCommand($remotePath) . ' < ' . escapeshellarg($localPath);

        for ($attempt = 1; ; $attempt++) {
            try {
                BackupCommandRunner::shell($command, $keepAlive);

                return;
            } catch (BackupCommandException $e) {
                if ($e->getExitCode() === self::MISSING_DIR_EXIT_CODE) {
                    $this->createRootFolder($remotePath);
                }

                if ($attempt >= self::UPLOAD_ATTEMPTS) {
                    throw $e;
                }

                sleep(5);
            }
        }
    }

    /**
     * La carpeta raíz del backup puede no existir todavía.
     */
    private function createRootFolder(string $remotePath): void
    {
        $root = strtok($remotePath, '/');
        $cache = Cache::store('array');

        if ($cache->has("backups.$root.root_create")) {
            return;
        }

        BackupCommandRunner::shell('echo -n | ' . $this->uploadCommand("$root/.empty"));

        $cache->put("backups.$root.root_create", 1);
    }

    private function uploadCommand(string $remotePath): string
    {
        return 'ncftpput -m -c'
            . ' -u ' . escapeshellarg($this->handler->getUser())
            . ' -p ' . escapeshellarg($this->handler->getPass())
            . ' -P ' . escapeshellarg($this->handler->getPort())
            . ' ' . escapeshellarg($this->handler->getHost())
            . ' ' . escapeshellarg($this->handler->getPath() . $remotePath);
    }

    public function listFolders(string $prefix): array
    {
        $rows = $this->handler->rawlist($prefix . '*') ?: [];

        $rows = array_filter(
            is_string($rows) ? [$rows] : $rows,
            fn ($row) => str_starts_with($row, 'd')
        );

        return array_values(array_map(
            fn ($row) => FileMeta::fromFtpRaw($row, $this->handler->getPath())->getName(),
            $rows
        ));
    }

    public function exists(string $remotePath): bool
    {
        return !empty($this->handler->rawlist($remotePath));
    }

    public function download(string $remotePath, string $localPath): void
    {
        $result = $this->handler->get($localPath, $remotePath);

        if ($result === false || !is_file($localPath) || filesize($localPath) === 0) {
            @unlink($localPath);

            throw new \RuntimeException("FTP download returned no data: $remotePath");
        }
    }

    public function read(string $remotePath): ?string
    {
        if (!$this->exists($remotePath)) {
            return null;
        }

        $localPath = BackupTempFile::path(basename($remotePath));

        try {
            $this->download($remotePath, $localPath);

            return file_get_contents($localPath);
        } finally {
            @unlink($localPath);
        }
    }
}
