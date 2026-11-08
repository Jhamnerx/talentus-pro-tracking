<?php

namespace Tobuli\Helpers\Backup\Uploader;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
        return $this->handler->getConn() !== false;
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
            } catch (ProcessFailedException $e) {
                if ($e->getProcess()->getExitCode() === self::MISSING_DIR_EXIT_CODE) {
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

        $cache->put("backups.$root.root_create", 1);

        BackupCommandRunner::shell('echo -n | ' . $this->uploadCommand("$root/.empty"));
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

        return array_map(
            fn ($row) => FileMeta::fromFtpRaw($row, $this->handler->getPath())->getName(),
            is_string($rows) ? [$rows] : $rows
        );
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
