<?php

namespace Tobuli\Helpers\GeoLocation\GeoServices;


use Illuminate\Support\Facades\Cache;
use Tobuli\Helpers\GeoLocation\GeoSettings;

class GeoDefault extends GeoNominatim
{
    const LOCK_TTL = 60;

    /**
     * @var array|null
     */
    protected $servers = null;

    public function __construct(GeoSettings $settings)
    {
        parent::__construct($settings);

        $this->servers = config('services.nominatims');

        $this->setServerCurrent();
    }

    protected function request($method, $options)
    {
        try {
            return parent::request($method, $options);
        } catch (\CurlException $e) {
            $this->setServerLock($this->url);
            $this->setServerCurrent();
        }

        return $this->request($method, $options);
    }

    protected function setServerCurrent() : string
    {
        $url = $this->getServerRandom();

        if ($this->hasServerLock($url)) {
            return $this->setServerCurrent();
        }

        return $this->url = $url;
    }

    protected function getServerRandom() : string
    {
        if (empty($this->servers)) {
            throw new \Exception('No working geocode servers');
        }

        $randomIndex = rand(0, count($this->servers) - 1);

        $server = $this->servers[$randomIndex];

        unset($this->servers[$randomIndex]);

        $this->servers = array_values($this->servers);

        return $server;
    }

    protected function hasServerLock(string $server) : bool
    {
        return Cache::has(self::lockKey($server));
    }

    protected function setServerLock(string $server)
    {
        Cache::put(self::lockKey($server), true, self::LOCK_TTL);
    }

    protected static function lockKey(string $server)
    {
        return "GeoDefault.lock." . md5($server);
    }
}