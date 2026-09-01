<?php

namespace App\Console;

use App\Jobs\CleanOldLogsJob;
use App\Jobs\DispatchSutranJobs;
use App\Jobs\PushMaintenanceToTalentus;
use App\Jobs\DispatchComsatelJobs;
use App\Jobs\DispatchMininterJobs;
use App\Jobs\DispatchWisetrackJobs;
use App\Jobs\DispatchOsinergminJobs;
use App\Jobs\DispatchSidersalesJobs;
use App\Jobs\DispatchEpsCenterJobs;
use App\Jobs\RenewSidersalesToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\Inspire',
        'App\Console\Commands\BootTest',
        'App\Console\Commands\DispatchPendingCommands',
        'App\Console\Commands\ImportHitosKmCommand',
        'App\Console\Commands\IntegrationStatusCommand',
        'App\Console\Commands\Tracker\RestartCommand',
        'App\Console\Commands\Tracker\ConfigCommand',
        'App\Console\Commands\Tracker\ConfigAliasCommand',
        'App\Console\Commands\Socket\SSLCertCommand',
        'App\Console\Commands\Socket\ServiceCommand',
        'App\Console\Commands\AutoCleanServerCommand',
        'App\Console\Commands\AutoCleanServerFilterCommand',
        'App\Console\Commands\BackupMysqlCommand',
        'App\Console\Commands\CheckAlertsCommand',
        'App\Console\Commands\CheckServerCommand',
        'App\Console\Commands\CheckServiceCommand',
        'App\Console\Commands\CheckServiceExpireCommand',
        'App\Console\Commands\CheckDeviceModelCacheCommand',
        'App\Console\Commands\CheckSchedulesCommand',
        'App\Console\Commands\CleanServerCommand',
        'App\Console\Commands\CleanReportLogCommand',
        'App\Console\Commands\CleanDevicesCommand',
        'App\Console\Commands\CleanEventsCommand',
        'App\Console\Commands\CleanUnregisteredDeviceLogCommand',
        'App\Console\Commands\CleanUserCommand',
        'App\Console\Commands\CleanDeviceCamerasCommand',
        'App\Console\Commands\CleanExpiredSharingsCommand',
        'App\Console\Commands\CleanModelCommand',
        'App\Console\Commands\ReportsDailyCommand',
        'App\Console\Commands\SendEventsCommand',
        'App\Console\Commands\ReportsCleanCommand',
        'App\Console\Commands\ServerTranslationsCommand',
        'App\Console\Commands\OptimizeServerDBCommand',
        'App\Console\Commands\CompressLogsCommand',
        'App\Console\Commands\UpdateIconsCommand',
        'App\Console\Commands\InsertCommand',
        'App\Console\Commands\CheckPositionsCommand',
        'App\Console\Commands\CheckTimeCommand',
        'App\Console\Commands\DevicesTimezoneResetCommand',
        'App\Console\Commands\DevicesTimezoneApplyCommand',
        'App\Console\Commands\CheckSubscriptionsCommand',
        'App\Console\Commands\CheckDevicesExpirationCommand',
        'App\Console\Commands\CheckOfflineDevices',
        'App\Console\Commands\CheckUsersExpirationCommand',
        'App\Console\Commands\SettingsCommand',
        'App\Console\Commands\ArchiveChecklistsCommand',
        'App\Console\Commands\KeyCreateCommand',
        'App\Console\Commands\GeoCacheSetupCommand',
        'App\Console\Commands\CopyDevicesCommand',
        'App\Console\Commands\CalcVirtualOdometerCommand',
        'App\Console\Commands\RecalculateDeviceHistoryCommand',
        'App\Console\Commands\SplitDevicesCommand',
        'App\Console\Commands\FakeDevicesCommand',
        'App\Console\Commands\DropDeviceFreeTablesCommand',
        'App\Console\Commands\RegenerateDeviceCamerasCommand',
        'App\Console\Commands\ServerPassportCommand',
        'App\Console\Commands\AlterPositionTablesCommand',
        'App\Console\Commands\BackupManageCommand',
        'App\Console\Commands\BackupImportCommand',
        'App\Console\Commands\DeltaPositionsBackupCommand',
        'App\Console\Commands\DeltaPositionsImportCommand',
        'App\Console\Commands\DeleteInvalidFuelEventsCommand',
        'App\Console\Commands\DeviceServerReconfigCommand',
        \App\Console\Commands\CacheOdometerValuesCommand::class,
        'App\Console\Commands\CleanMininterErrorsCommand',
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule
            ->command('queue:restart')
            ->hourly();

        $schedule->command('queue:work redis --sleep=3 --tries=1 --queue=tracker')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule
            ->exec('systemctl restart supervisord')
            ->hourlyAt(3);

        $schedule
            ->command('device_history:recalculate')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule
            ->command('alerts:check')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule
            ->command('events:check')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule
            ->command('schedules:check')
            ->everyMinute();

        $schedule
            ->command('subscriptions:check')
            ->hourly();

        $schedule
            ->command('devices_expiration:check')
            ->hourly();

        $schedule
            ->command('users_expiration:check')
            ->hourly();

        $schedule
            ->command('camera:clean')
            ->daily();

        $schedule
            ->command('server:check')
            ->daily()
            ->withoutOverlapping();

        $schedule
            ->command('passport:purge')
            ->daily();

        $schedule
            ->command('sharing:clean')
            ->everyMinute();

        $schedule
            ->command('checklists:archive')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule
            ->command('devices:check_offline')
            ->everyMinute()
            ->withoutOverlapping();

        //WEB SERVICES JOBS — dispatch manejado por Supervisor, estos solo programan
        $schedule->job(new DispatchSutranJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchComsatelJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchOsinergminJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchMininterJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchWisetrackJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchSidersalesJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new DispatchEpsCenterJobs)->everyMinute()->withoutOverlapping();
        $schedule->job(new RenewSidersalesToken)->everyFifteenMinutes()->withoutOverlapping();
        $schedule->job(new CleanOldLogsJob(10))->daily();

        // Comando para precalcular valores de odómetro cada 15 minutos
        $schedule->command('odometer:cache')->everyFifteenMinutes()->withoutOverlapping();

        // Procesamiento de posiciones GPS (ProcessManager controla solapamiento)
        $schedule->command('insert:run')->everyMinute();

        // Resumen de reenvio para el panel de cumplimiento. Cada 10 min:
        // el panel lee el resumen y nunca las colas.
        $schedule->command('integrations:status')->everyTenMinutes()->withoutOverlapping();

        // Regeneración de cámaras
        $schedule->command('device_cameras:regenerate')->everyMinute()->withoutOverlapping();

        // Reinicio del tracker cada hora en el minuto 1
        $schedule->command('tracker:restart')->hourlyAt(1);

        // Reportes diarios y semanales
        $schedule->command('reports:daily daily')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('reports:daily weekly')->everyThreeMinutes()->withoutOverlapping();

        // Revisión de servicios
        $schedule->command('service:check')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('service:check_expire')->everyThreeMinutes()->withoutOverlapping();

        // Auto-send when online: comandos GPRS en cola → enviar al reconectar
        $schedule->command('commands:dispatch-pending')->everyMinute()->withoutOverlapping();

        // Tareas nocturnas
        $schedule->command('backup:mysql')->hourly();
        $schedule->command('logs:compress')->dailyAt('00:00');
        $schedule->command('server:autoclean')->dailyAt('01:00');
        $schedule->command('server:translations')->dailyAt('02:00');
        $schedule->command('mininter:clean-errors')->dailyAt('02:30');

        // Reintento de sincronización de mantenimientos con Talentus
        $schedule->job(new PushMaintenanceToTalentus)->everyTwoHours()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    // protected function commands()
    // {
    //     $this->load(__DIR__.'/Commands');

    //     require base_path('routes/console.php');
    // }
}
