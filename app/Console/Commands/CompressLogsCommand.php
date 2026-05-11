<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompressLogsCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:compress';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates server database and configuration to the newest version.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        # Traccar
        $config = config('tracker');
        $traccarLogFile = $config['logger.file'] ?? null;

        if ($traccarLogFile) {
            $path = pathinfo($traccarLogFile, PATHINFO_DIRNAME) . '/*.log.*';
            $this->line("Buscando logs Traccar en: {$path}");

            $files = glob($path);
            $this->line("Archivos encontrados: " . count($files));

            foreach ($files as $file) {
                $arr = explode('.', $file);
                $ex = end($arr);
                if ($ex == 'gz' || $ex == date('Ymd')) {
                    $this->line("Omitiendo: {$file}");
                    continue;
                }

                $this->line("Comprimiendo: {$file}");
                exec('gzip ' . escapeshellarg($file), $output, $returnCode);
                if ($returnCode !== 0) {
                    $this->error("Error al comprimir: {$file} (código: {$returnCode})");
                } else {
                    $this->line("OK: {$file}.gz");
                }
            }
        } else {
            $this->warn("tracker.logger.file no configurado, omitiendo logs Traccar.");
        }

        # HTTPD access
        $files = glob('/var/log/httpd/access_log-*');
        $this->line("HTTPD access logs encontrados: " . count($files));
        foreach ($files as $file) {
            if (str_ends_with($file, '.gz')) continue;
            $this->line("Comprimiendo: {$file}");
            exec('gzip ' . escapeshellarg($file), $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error("Error al comprimir: {$file} (código: {$returnCode})");
            }
        }

        # HTTPD error
        $files = glob('/var/log/httpd/error_log-*');
        $this->line("HTTPD error logs encontrados: " . count($files));
        foreach ($files as $file) {
            if (str_ends_with($file, '.gz')) continue;
            $this->line("Comprimiendo: {$file}");
            exec('gzip ' . escapeshellarg($file), $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error("Error al comprimir: {$file} (código: {$returnCode})");
            }
        }

        $this->info("logs:compress finalizado.");
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array();
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array();
    }
}
