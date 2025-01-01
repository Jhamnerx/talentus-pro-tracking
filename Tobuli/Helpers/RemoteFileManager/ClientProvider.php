<?php

namespace Tobuli\Helpers\RemoteFileManager;

use Illuminate\Support\Facades\Log;
use PgSql\Lob;
use Tobuli\Helpers\RemoteFileManager\Client\ClientInterface;
use Tobuli\Helpers\RemoteFileManager\Client\FtpClient;
use Tobuli\Helpers\RemoteFileManager\Client\SftpClient;
use Tobuli\Helpers\RemoteFileManager\Exception\UnsupportedProtocolException;

class ClientProvider
{
    public function fromUrl(string $url): ClientInterface
    {
        $url = $this->sanitizeUrl($url);

        $params = parse_url($url);


        if (!isset($params['scheme'])) {
            throw new UnsupportedProtocolException('protocol not found');
        }

        $scheme = strtolower($params['scheme']);
        Log::info('scheme: ' . $scheme);

        switch ($scheme) {
            case 'ftp':
                return new FtpClient(
                    $params['host'],
                    $params['user'],
                    $params['pass'],
                    $params['port'] ?? 21,
                    $params['path'] ?? '',
                );
            case 'sftp':
                return new SftpClient(
                    $params['host'],
                    $params['user'],
                    $params['pass'],
                    $params['port'] ?? 22,
                    $params['path'] ?? '',
                );
            default:
                throw new UnsupportedProtocolException();
        }
    }

    private function sanitizeUrl(string $url): string
    {
        $pattern = '/^(ftp|sftp):\/\/(.*?):(.*?)@(.*?)$/';
        return preg_replace_callback($pattern, function ($matches) {
            return sprintf(
                '%s://%s:%s@%s',
                $matches[1],
                rawurlencode($matches[2]),
                rawurlencode($matches[3]),
                $matches[4]
            );
        }, $url);
    }
}
