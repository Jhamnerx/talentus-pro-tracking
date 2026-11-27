<?php

namespace App\Jobs;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tobuli\Entities\CommandSchedule;
use Tobuli\Services\Commands\SendCommandService;


class RunCommandScheduleJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    private $commandSchedule;
    private $sendCommandService;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(CommandSchedule $schedule)
    {
        $this->commandSchedule = $schedule;
        $this->sendCommandService = new SendCommandService($schedule);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $devices = $this->commandSchedule->devices;
        $user    = $this->commandSchedule->user;

        setActingUser($user);

        if ($this->commandSchedule->getAttribute('connection') == SendCommandService::CONNECTION_GPRS) {
            $data = (array) $this->commandSchedule->parameters;
            $autoOnline = ! empty($data['auto_online']);
            unset($data['auto_online']);
            $data['type'] = $this->commandSchedule->command;

            // Auto-send when online (UI v2): los devices desconectados al momento
            // de ejecutarse el horario se ENCOLAN (pending_commands) en vez de
            // fallar; commands:dispatch-pending los envía al reconectar.
            if ($autoOnline) {
                $offline = $devices->filter(function ($device) {
                    return ! $device->isConnected();
                });

                foreach ($offline as $device) {
                    \Tobuli\Entities\PendingCommand::create([
                        'user_id'    => $user->id,
                        'device_id'  => $device->id,
                        'connection' => 'gprs',
                        'command'    => (string) $this->commandSchedule->command,
                        'parameters' => \Illuminate\Support\Arr::except($data, ['type']),
                    ]);
                }

                $devices = $devices->filter(function ($device) {
                    return $device->isConnected();
                });

                if ($devices->isEmpty()) {
                    return;
                }
            }

            $this->sendCommandService->gprs($devices, $data, $user);
        } else {
            $data = [
                'type' => $this->commandSchedule->command,
                'message' => $this->commandSchedule->message
            ];
            $this->sendCommandService->sms($devices, $data, $user);
        }
    }
}
