<?php

namespace Tobuli\Helpers\Backup\Destination;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupCommandRunner;

class DriveDestination implements BackupDestination
{
    private const NOT_FOUND_EXIT_CODES = [3, 4];
    private const TEST_FILE = '.prueba-backup';

    private string $remote;
    private string $path;
    private ?string $configPath;

    public function __construct(string $remote, string $path, ?string $configPath = null)
    {
        $this->remote = rtrim($remote, ':');
        $this->path = trim($path, '/');
        $this->configPath = $configPath ?: null;
    }

    public function name(): string
    {
        return self::DRIVE;
    }

    public function check(): bool
    {
        try {
            $this->about();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Sube y borra un archivo pequeño. Lanza excepción si algo falla.
     */
    public function test(): void
    {
        $this->put(self::TEST_FILE, (string)time());
        $this->deleteFile(self::TEST_FILE);
    }

    /**
     * @return array{total: float, used: float, free: float} en bytes
     */
    public function about(): array
    {
        $output = $this->rclone(['about', $this->remote . ':', '--json'])->getOutput();
        $data = json_decode($output, true) ?: [];

        return [
            'total' => (float)($data['total'] ?? 0),
            'used'  => (float)($data['used'] ?? 0),
            'free'  => (float)($data['free'] ?? 0),
        ];
    }

    public function upload(string $localPath, string $remotePath, ?BackupProcess $keepAlive = null): void
    {
        $this->rclone(['copyto', $localPath, $this->full($remotePath), '--retries', '3'], $keepAlive);
    }

    public function listFolders(string $prefix): array
    {
        $folders = array_map(fn ($name) => rtrim($name, '/'), $this->list('', ['--dirs-only']));

        return array_values(array_filter($folders, fn ($name) => str_starts_with($name, $prefix)));
    }

    /**
     * @return string[] nombres de archivo directamente dentro de la carpeta
     */
    public function listFiles(string $folder): array
    {
        return $this->list($folder, ['--files-only']);
    }

    public function exists(string $remotePath): bool
    {
        return !empty($this->list($remotePath, []));
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->rclone(['copyto', $this->full($remotePath), $localPath, '--retries', '3']);
    }

    public function read(string $remotePath): ?string
    {
        try {
            return $this->rclone(['cat', $this->full($remotePath)])->getOutput();
        } catch (ProcessFailedException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }

    public function put(string $remotePath, string $content): void
    {
        $local = \Tobuli\Helpers\Backup\BackupTempFile::path(basename($remotePath));
        file_put_contents($local, $content);

        try {
            $this->upload($local, $remotePath);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Sin papelera: lo borrado seguiría ocupando la cuota de la cuenta.
     */
    public function purge(string $remotePath): void
    {
        try {
            $this->rclone(['purge', $this->full($remotePath), '--drive-use-trash=false']);
        } catch (ProcessFailedException $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    public function deleteFile(string $remotePath): void
    {
        try {
            $this->rclone(['deletefile', $this->full($remotePath), '--drive-use-trash=false']);
        } catch (ProcessFailedException $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    public function move(string $fromFolder, string $toFolder): void
    {
        $this->rclone(['moveto', $this->full($fromFolder), $this->full($toFolder)]);
    }

    private function list(string $path, array $flags): array
    {
        try {
            $output = $this->rclone(array_merge(['lsf', $this->full($path), '--max-depth', '1'], $flags))->getOutput();
        } catch (ProcessFailedException $e) {
            if ($this->isNotFound($e)) {
                return [];
            }

            throw $e;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
    }

    private function isNotFound(ProcessFailedException $e): bool
    {
        return in_array($e->getProcess()->getExitCode(), self::NOT_FOUND_EXIT_CODES, true);
    }

    private function full(string $remotePath): string
    {
        return $this->remote . ':' . trim($this->path . '/' . ltrim($remotePath, '/'), '/');
    }

    private function rclone(array $args, ?BackupProcess $keepAlive = null)
    {
        $command = array_merge(['rclone'], $this->configPath ? ['--config', $this->configPath] : [], $args);

        return BackupCommandRunner::args($command, $keepAlive);
    }
}
