<?php

namespace Tobuli\Helpers\Backup;

class BackupSecrets
{
    private const MASK = '***';

    /**
     * Quita contraseñas de un mensaje antes de guardarlo o enviarlo.
     */
    public static function scrub(string $text): string
    {
        foreach (self::knownSecrets() as $secret) {
            $text = str_replace([$secret, escapeshellarg($secret)], self::MASK, $text);
        }

        $patterns = [
            "/MYSQL_PWD='[^']*'/"   => 'MYSQL_PWD=' . self::MASK,
            "/-p\s*'[^']*'/"        => '-p ' . self::MASK,
            "/--password=[^\s\"]*/" => '--password=' . self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private static function knownSecrets(): array
    {
        $secrets = [
            config('database.connections.mysql.password'),
            config('database.connections.traccar_mysql.password'),
            settings('backups.ftp_password'),
        ];

        try {
            $secrets = array_merge(
                $secrets,
                (new \Tobuli\Services\DatabaseService())->getDatabases()->pluck('password')->all()
            );
        } catch (\Throwable $e) {
            // Sin acceso a la tabla de bases, se quedan las claves conocidas.
        }

        return array_unique(array_filter($secrets, fn ($secret) => is_string($secret) && $secret !== ''));
    }
}
