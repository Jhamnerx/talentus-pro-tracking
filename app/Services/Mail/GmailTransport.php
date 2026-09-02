<?php
namespace App\Services\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redirect;
use Swift_Mime_SimpleMessage;
use Swift_TransportException;

/**
 * Proveedor Gmail (GPSWox 3.7.12) adaptado a SwiftMailer/Laravel 8.
 * Envia por la API REST de Gmail (users/me/messages/send) con OAuth2:
 * el refresh_token guardado en settings('email') se canjea por un
 * access_token cacheado hasta su expiracion. El flujo de autorizacion
 * (initAuthentication/authenticate) es identico al upstream 3.7.13.
 */
class GmailTransport extends Transport
{
    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var array
     */
    private $config;

    public function __construct(ClientInterface $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $encodedEmail = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        try {
            $response = $this->client->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::JSON => [
                    'raw' => $encodedEmail,
                ],
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300 || $status < 200) {
                throw new Swift_TransportException("Status code $status: " . $response->getBody());
            }
        } catch (Swift_TransportException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new Swift_TransportException('Gmail API transport failed: ' . $e->getMessage(), 0, $e);
        }

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    protected function getAccessToken(): string
    {
        if (Cache::has('gmail_api_access_token')) {
            return Cache::get('gmail_api_access_token');
        }

        $response = $this->client->request('POST', 'https://oauth2.googleapis.com/token', [
            RequestOptions::FORM_PARAMS => [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $this->config['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if (!isset($response['access_token'])) {
            throw new Swift_TransportException('Unable to retrieve access token from refresh token.');
        }

        Cache::put('gmail_api_access_token', $response['access_token'], $response['expires_in'] - 60);

        return $response['access_token'];
    }

    public static function authenticate(array $config): array
    {
        try {
            $response = (new Client())->request('POST', 'https://oauth2.googleapis.com/token', [
                RequestOptions::FORM_PARAMS => [
                    'code' => $config['code'],
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri' => route('gmail.oauth2.callback'),
                    'grant_type' => 'authorization_code',
                ]
            ])->getBody()->getContents();

            return json_decode($response, true);
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function initAuthentication(array $config): RedirectResponse
    {
        return Redirect::away('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => route('gmail.oauth2.callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]));
    }
}
