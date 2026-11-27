<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Services\FtpUserService;

/**
 * Crea la cuenta FTP donde las cámaras suben las grabaciones (JT1078 0x9206), con vsftpd como las cámaras FTP.
 */
class VideoFtpUserCommand extends Command
{
    protected $signature = 'video:ftp-user {--password= : contraseña; si se omite se genera una}';

    protected $description = 'Create the FTP account used by cameras to upload video recordings';

    public function handle(FtpUserService $ftpUserService)
    {
        $username = config('video.ftp.username');
        $root = config('video.ftp.root');
        $password = $this->option('password') ?: (config('video.ftp.password') ?: bin2hex(random_bytes(12)));

        if ( ! preg_match('/^[a-z_][a-z0-9_-]{0,30}$/', $username)) {
            $this->error("Invalid FTP username: {$username}");

            return 1;
        }

        if (preg_match('/[\'"\s\\\\$`]/', $password)) {
            $this->error('The password cannot contain quotes, spaces, $, ` or \\');

            return 1;
        }

        $result = $ftpUserService->createFtpUser($username, $password, $root);

        if (isset($result['error'])) {
            $this->error($result['error']);

            return 1;
        }

        $this->info("FTP user {$username} ready, home {$root}");
        $this->line('Set in .env:');
        $this->line('VIDEO_FTP_ENABLED=true');
        $this->line('VIDEO_FTP_HOST=<public address of this server>');
        $this->line("VIDEO_FTP_USERNAME={$username}");
        $this->line("VIDEO_FTP_PASSWORD={$password}");
        $this->line("VIDEO_FTP_ROOT={$root}");

        return 0;
    }
}
