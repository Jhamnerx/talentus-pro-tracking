<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\Mininter;

class CleanMininterErrorsCommand extends Command
{
    protected $name = 'mininter:clean-errors';

    protected $description = 'Elimina registros de la tabla mininter con status = error';

    public function handle()
    {
        $count = Mininter::where('status', 'error')->count();

        if ($count === 0) {
            $this->line('No hay registros con status=error en mininter.');
            return 0;
        }

        Mininter::where('status', 'error')->delete();

        $this->info("Eliminados {$count} registros con status=error de la tabla mininter.");

        return 0;
    }
}
