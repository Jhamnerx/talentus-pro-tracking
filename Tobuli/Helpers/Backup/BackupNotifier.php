<?php

namespace Tobuli\Helpers\Backup;

use CustomFacades\MailHelper;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Backup;
use Tobuli\Entities\User;

class BackupNotifier
{
    private const ADMIN_GROUP_ID = 1;

    public function notify(string $subject, string $body): void
    {
        $emails = User::where('group_id', self::ADMIN_GROUP_ID)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        try {
            MailHelper::send($emails, $subject, nl2br(e(BackupSecrets::scrub($body))));
        } catch (\Throwable $e) {
            logger()->error('Backup notification failed: ' . BackupSecrets::scrub($e->getMessage()));
        }
    }

    /**
     * Un solo aviso por backup y motivo: un paso fallido se reintenta cada hora.
     */
    public function notifyOnce(Backup $backup, string $reason, string $subject, string $body): void
    {
        if (Cache::add("backup_notified.{$backup->id}.$reason", 1, 86400 * 7)) {
            $this->notify($subject, $body);
        }
    }
}
