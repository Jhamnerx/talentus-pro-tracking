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
            $dir = pathinfo($traccarLogFile, PATHINFO_DIRNAME);

            // 1. Comprimir logs ya rotados: tracker-server.log.YYYYMMDD
            $rotatedFiles = glob($dir . '/*.log.*');
            foreach ($rotatedFiles as $file) {
                $arr = explode('.', $file);
                $ex  = end($arr);
                if ($ex === 'gz' || $ex === date('Ymd')) {
                    $this->line("Omitiendo: {$file}");
                    continue;
                }
                $this->line("Comprimiendo rotado: {$file}");
                exec('gzip ' . escapeshellarg($file), $output, $returnCode);
                if ($returnCode !== 0) {
                    $this->error("Error al comprimir: {$file} (código: {$returnCode})");
                } else {
                    $this->line("OK: {$file}.gz");
                }
            }

            // 2. Rotar el log activo: copiar a archivo fechado, truncar original
            if (file_exists($traccarLogFile) && filesize($traccarLogFile) > 0) {
                $dated = $traccarLogFile . '.' . date('Ymd');
                if (file_exists($dated) || file_exists($dated . '.gz')) {
                    $this->line("Ya existe rotación de hoy, omitiendo log activo.");
                } else {
                    $this->line("Rotando log activo: {$traccarLogFile}");
                    copy($traccarLogFile, $dated);
                    file_put_contents($traccarLogFile, ''); // truncar, Traccar sigue escribiendo
                    exec('gzip ' . escapeshellarg($dated), $output, $returnCode);
                    if ($returnCode !== 0) {
                        $this->error("Error al comprimir log activo (código: {$returnCode})");
                    } else {
                        $this->line("OK: {$dated}.gz");
                    }
                }
            } else {
                $this->line("Log activo vacío o no encontrado: {$traccarLogFile}");
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
