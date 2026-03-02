<?php
// Decrypted by https://bolt.pawan.krd - Educational Use Only
//
// DISCLAIMER: This file was decrypted for educational and research purposes only.
// Use of this file is at your own risk. We make no warranties, express or implied,
// regarding the accuracy, reliability, or legality of the content within. We are not
// responsible for any damages, direct or indirect, resulting from the use of this file.
// You are solely responsible for ensuring compliance with all applicable laws and
// regulations in your jurisdiction.
//
// By using this file, you acknowledge and agree to these terms.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use CustomFacades\Repositories\DeviceCameraRepo;
use Tobuli\Entities\File\DeviceCameraMedia;
use Illuminate\Support\Facades\File;

class CleanDeviceCamerasCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'camera:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean device cameras storage';

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
        try {
            $images = DeviceCameraMedia::olderThan(settings('main_settings.device_cameras_days'));

            foreach ($images as $image) {
                $path = $image->path;

                if (File::exists($path)) {
                    if (!File::delete($path)) {
                        $this->line('Couldn\'t delete: ' . $path);
                    }
                }
            }

            $this->line('Ok');
        } catch (\Exception $e) {
        }
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
