<?php

namespace Tobuli\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FirebaseConfig
{
    public const KEY_ACCESS_TOKEN = 'fcm-access-token';

    private GoogleAuthClient $googleAuthClient;
    private string $configPath;

    public function __construct()
    {
        $this->googleAuthClient = new GoogleAuthClient();
        $this->configPath = storage_path('app/firebase-config.json');
    }

    public function isDefaultConfig(): bool
    {
        return !self::isCustomConfig();
    }

    public function isCustomConfig(): bool
    {
        return File::exists($this->configPath);
    }

    public function getAccessToken(): ?string
    {
        if ($this->isDefaultConfig()) {
            return null;
        }

        $config = $this->getCustomConfig();

        return $this->googleAuthClient->getOAuth2Token('firebase.messaging', $config, self::KEY_ACCESS_TOKEN);
    }

    public function resetAccessToken(): void
    {
        $this->googleAuthClient->removeToken(self::KEY_ACCESS_TOKEN);
    }
    
    public function getCustomConfig(): ?array
    {
        return json_decode(File::get($this->configPath), true);
    }

    public function storeCustom(UploadedFile $file): void
    {
        $data = json_decode($file->getContent(), true);

        Validator::validate($data, [
            'type'                          => 'required',
            'project_id'                    => 'required',
            'private_key_id'                => 'required',
            'private_key'                   => 'required',
            'client_email'                  => 'required',
            'client_id'                     => 'required',
            'auth_uri'                      => 'required',
            'token_uri'                     => 'required',
            'auth_provider_x509_cert_url'   => 'required',
            'client_x509_cert_url'          => 'required',
            'universe_domain'               => 'required',
        ]);

        $this->removeCustom();

        Storage::disk('local')->put('firebase-config.json', $file->getContent());
    }

    public function removeCustom(): void
    {
        if (File::exists($this->configPath)) {
            File::delete($this->configPath);
        }

        $this->googleAuthClient->removeToken(self::KEY_ACCESS_TOKEN);
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }
}