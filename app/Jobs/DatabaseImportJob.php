<?php

namespace App\Jobs;

use App\Events\NoticeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tobuli\Entities\User;

class DatabaseImportJob implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public array $config;
    public string $file;
    public ?User $actor;

    public function __construct(array $config, string $file, ?User $actor)
    {
        $this->config = $config;
        $this->file = $file;
        $this->actor = $actor;
    }

    public function handle(): void
    {
        if (File::missing($this->file)) {
            return;
        }

        $process = $this->runProcess();

        $this->sendNotice($process->isSuccessful());

        File::delete($this->file);
    }

    protected function runProcess(): Process
    {
        // La clave va por entorno: con -p'clave' una clave vacía pide contraseña por terminal.
        $process = Process::fromShellCommandline(
            'bash -o pipefail -c ' . escapeshellarg($this->buildCommand()),
            null,
            ['MYSQL_PWD' => (string)$this->config['password']]
        );
        $process->setTimeout(0);
        $process->run();

        if ($process->isSuccessful()) {
            return $process;
        }

        throw new ProcessFailedException($process);
    }

    protected function buildCommand(): string
    {
        return self::buildDatabaseImportCommand($this->file, $this->config);
    }

    protected function sendNotice(bool $success): void
    {
        if (!$this->actor) {
            return;
        }

        $event = $success
            ? new NoticeEvent($this->actor, NoticeEvent::TYPE_SUCCESS, trans('front.successfully_uploaded'))
            : new NoticeEvent($this->actor, NoticeEvent::TYPE_ERROR, trans('front.upload_failed'));

        event($event);
    }

    public static function buildDatabaseImportCommand(string $file, array $config): string
    {
        $command = 'mysql'
            . ' -h ' . escapeshellarg($config['host'])
            . ' -u ' . escapeshellarg($config['username'])
            . ' --port=' . escapeshellarg($config['port'])
            . ' ' . escapeshellarg($config['database']);

        if (str_ends_with($file, '.gz')) {
            return 'zcat ' . escapeshellarg($file) . ' | ' . $command;
        }

        return $command . ' < ' . escapeshellarg($file);
    }
}
