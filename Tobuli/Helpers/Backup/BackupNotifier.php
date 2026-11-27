<?php

namespace Tobuli\Helpers\Backup;

use CustomFacades\MailHelper;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Backup;
use Tobuli\Entities\User;

class BackupNotifier
{
    private const ADMIN_GROUP_ID = 1;

    public function notify(string $subject, string $body): bool
    {
        $emails = User::where('group_id', self::ADMIN_GROUP_ID)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        try {
            $result = MailHelper::send($emails, $subject, nl2br(e(BackupSecrets::scrub($body))));

            if (is_array($result) && empty($result['status'])) {
                logger()->error('Backup notification failed: ' . BackupSecrets::scrub((string)($result['error'] ?? 'unknown error')));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            logger()->error('Backup notification failed: ' . BackupSecrets::scrub($e->getMessage()));

            return false;
        }
    }

    /**
     * Un solo aviso por backup y motivo: un paso fallido se reintenta cada hora.
     */
    public function notifyOnce(Backup $backup, string $reason, string $subject, string $body): void
    {
        $key = "backup_notified.{$backup->id}.$reason";

        if (Cache::add($key, 1, 86400 * 35) && !$this->notify($subject, $body)) {
            Cache::forget($key); // se reintentara en la proxima pasada
        }
    }
}
