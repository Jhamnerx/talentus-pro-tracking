<?php

namespace Tobuli\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Tobuli\Entities\FcmToken;
use Tobuli\Entities\FcmTokenableInterface;
use Tobuli\Helpers\FirebaseConfig;

class FcmService
{
    private FirebaseConfig $firebaseConfig;
    private Client $client;
    private string $firebaseUrl;

    public function __construct()
    {
        $this->firebaseConfig = new FirebaseConfig();
    }

    public function setFcmToken(FcmTokenableInterface $tokenable, string $fcmToken)
    {
        $token = $tokenable->fcmTokens()->firstOrNew(['token' => $fcmToken]);
        $token->save();
    }

    /**
     * @param Model&FcmTokenableInterface $tokenable
     */
    public function send($tokenable, $title, $body, array $data = [], $type = null)
    {
        if (!$tokenable instanceof FcmTokenableInterface) {
            return;
        }

        $tokens = $tokenable->fcmTokens()->latest()->get()->pluck('token')->toArray();

        if (!$tokens) {
            return;
        }

        $payload = array_merge($data, ['title' => $title, 'content' => $body]);

        $this->sendToTokens($tokens, $title, $body, $payload, $type);
    }

    public function sendToTokens(array $tokens, string $title, string $body, ?array $payloadData = null, $type = null): void
    {
        $message = $this->buildMessage($title, $body, $payloadData, $type);

        if ($this->firebaseConfig->isCustomConfig()) {
            $this->sendDirect($tokens, $message);
            return;
        }

        // user has custom config but did not upload firebase-config.json yet
        if (config('fcm.http.sender_id')) {
            return;
        }

        // $this->sendViaBridge($tokens, $message);
    }

    private function sendDirect(array $tokens, array $message): void
    {
        $resendCount = 0;

        foreach ($tokens as $token) {
            $message['message']['token'] = $token;

            try {
                $this->sendDirectToken($token, $message);
            } catch (ClientException $e) {
                if ($this->isFailedAuthError($e) && $resendCount++ < 1) {
                    $this->firebaseConfig->resetAccessToken();

                    $this->sendDirectToken($token, $message);
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * @throws ClientException
     */
    private function sendDirectToken(string $token, array $message): void
    {
        try {
            $this->getClient()->post($this->getFirebaseUrl(), [
                RequestOptions::JSON => $message,
                RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . ($token === 'test' ? 'x' : '').$this->firebaseConfig->getAccessToken()],
            ]);
        } catch (ClientException $exception) {
            $data = json_decode($exception->getResponse()->getBody()->getContents(), true) ?? [];

            $success = $this->handleSendError($token, $exception->getCode(), $data);

            if (!$success) {
                throw $exception;
            }
        }
    }

    private function sendViaBridge(array $tokens, array $message): void
    {
        foreach ($tokens as $token) {
            $message['message']['token'] = $token;

            try {
                $this->getClient()->post(config('fcm.http.bridge_url'), [
                    RequestOptions::JSON => $message,
                ])->getBody()->getContents();
            } catch (ClientException $exception) {
                $data = json_decode($exception->getResponse()->getBody()->getContents(), true) ?? [];

                $success = $this->handleSendError($token, $exception->getCode(), $data);

                if (!$success) {
                    throw $exception;
                }
            }
        }
    }

    private function handleSendError(string $token, int $code, $data): bool
    {
        if (!is_array($data)) {
            $data = (array)$data;
        }

        if ($code === Response::HTTP_UNPROCESSABLE_ENTITY) {
            $invalidToken = isset($data['message.token']);

            if ($invalidToken) {
                FcmToken::where('token', $token)->delete();
            }

            return $invalidToken && count($data) === 1;
        }

        if (!isset($data['error']['details'])) {
            return false;
        }

        $msg = $data['error']['message'] ?? '';
        $data = $data['error']['details'];

        if (!is_array($data)) {
            return false;
        }

        $success = true;

        foreach ($data as $details) {
            if (!isset($details['errorCode'])) {
                $success = false;
                continue;
            }

            $code = $details['errorCode'];

            // https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode
            switch (true) {
                case $code === 'INVALID_ARGUMENT' && $msg === 'The registration token is not a valid FCM registration token':
                case $code === 'UNREGISTERED':
                case $code === 'SENDER_ID_MISMATCH':
                    FcmToken::where('token', $token)->delete();
                    break;
                default:
                    $success = false;
            }
        }

        return $success;
    }

    private function isFailedAuthError(\Exception $e): bool
    {
        return $e->getCode() === Response::HTTP_UNAUTHORIZED;
    }

    private function buildMessage(string $title, string $body, ?array $payloadData = null, $type = null): array
    {
        $message = [
            'token' => null,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
        ];

        if ($payloadData) {
            $message['data'] = $payloadData;
        }

        if (isset($message['data'])) {
            array_walk($message['data'], function (&$value) {
                if (is_string($value)) {
                    return;
                }

                if (is_scalar($value)) {
                    $value = (string)$value;
                } else {
                    $value = json_encode($value);
                }
            });
        }

        $typesSounds = [
            'custom',
            'device_subscription_expired',
            'distance',
            'driver',
            'driver_authorized',
            'driver_change',
            'driver_unauthorized',
            'expired_device',
            'expired_sim',
            'expired_user',
            'expiring_device',
            'expiring_sim',
            'expiring_user',
            'fuel_fill',
            'fuel_theft',
            'idle_duration',
            'ignition_duration',
            'ignition_off',
            'ignition_on',
            'move_start',
            'offline_duration',
            'overspeed',
            'plugged_in',
            'poi_idle_duration',
            'poi_stop_duration',
            'sos',
            'stop_duration',
            'task_complete',
            'task_failed',
            'task_in_progress',
            'task_new',
            'time_duration',
            'unplugged',
            'zone_in',
            'zone_out'
        ];

        if ($type == null){
            $type = 'general';
            $typeSound = 'default';
            foreach ($typesSounds as $sound) {
                if (strpos($title, $sound) !== false) {
                    $type = $sound;
                    break;
                }
            }
        }else{
            $typesSounds = array_filter($typesSounds, function($sound) {
                return $sound !== 'custom';
            });
            if ($type == 'custom'){
                $type = 'general';
                $typeSound = 'default';
            }

            if (preg_match('/\((.*?)\)/', $title, $matches)) {
                $extractedSound = $matches[1];
                if (in_array($extractedSound, $typesSounds)) {
                    $type = $extractedSound;
                }
            }

            if ($type == 'general') {
                foreach ($typesSounds as $sound) {
                    if (strpos($title, $sound) !== false) {
                        $type = $sound;
                        break;
                    }
                }
            }

            if ($type!='general'){
                $typeSound = $type . '.mp3';
            }
        }

        $channelId = config('fcm.channel_id');
        $sound = config('fcm.sound');
        
        if (config('addon.custom_sound_notifications')){
            $channelId = $type ? $type : $channelId;
            $sound = $typeSound ? $typeSound : $sound;
        }

        $ttl = 20 * 60; // https://firebase.google.com/docs/cloud-messaging/concept-options#ttl

        $message['android']['ttl'] = $ttl . 's';
        $message['android']['priority'] = 'high';
        $message['apns']['headers'] = ['apns-expiration' => (string)(time() + $ttl)];
        // default apns-priority is immediate: https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns

        $android = array_filter([
            'sound' => $sound,
            'channel_id' => $channelId,
        ]);

        if ($android) {
            $message['android']['notification'] = $android;
        }

        $apple = array_filter(['sound' => $sound]);

        if ($apple) {
            $message['apns']['payload'] = ['aps' => $apple];
        }

        return ['message' => $message];
    }

    private function getFirebaseUrl(): string
    {
        if (isset($this->firebaseUrl)) {
            return $this->firebaseUrl;
        }

        $projectId = $this->firebaseConfig->getCustomConfig()['project_id'] ?? '';

        return $this->firebaseUrl = "v1/projects/$projectId/messages:send";
    }

    private function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $uri = $this->firebaseConfig->isCustomConfig()
            ? 'https://fcm.googleapis.com'
            : null;

        // client does not handle access token without providing base_uri
        return $this->client = new Client(array_filter(['base_uri' => $uri]));
    }
}
