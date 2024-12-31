<?php

namespace Tobuli\Helpers\GeoLocation\GeoServices;

use Language;
use Illuminate\Support\Arr;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\GeoLocation\GeoSettings;
use Tobuli\Helpers\GeoLocation\Location;


class GeoHere extends AbstractGeoService
{
    private $curl;
    private $urls;
    private $requestOptions = [];


    public function __construct(GeoSettings $settings)
    {
        parent::__construct($settings);

        $curl = new \Curl;
        $curl->options['CURLOPT_SSL_VERIFYPEER'] = false;
        $curl->options['CURLOPT_TIMEOUT'] = 5;

        $this->curl = $curl;

        $this->urls = [
            "reverse" => "https://revgeocode.search.hereapi.com/v1/revgeocode",
            "geocode" => "https://geocode.search.hereapi.com/v1/geocode",
            "autocomplete" => "https://autocomplete.search.hereapi.com/v1/autocomplete",
            "lookup" => "https://lookup.search.hereapi.com/v1/lookup"
        ];

        $this->requestOptions = [
            'apiKey' => $this->settings->getApiKey(),
            'language' => Language::iso(),
        ];
    }


    public function byAddress($address)
    {
        $addresses = $this->request(
            'geocode',
            [
                'q' => $address,
                'limit' => 1
            ]
        )['items'] ?? null;

        return $addresses ? $this->locationObject($addresses[0]) : null;
    }


    public function listByAddress($address)
    {
        $addresses = $this->request(
            'autocomplete',
            [
                'q' => $address,
                'limit' => 5
            ]
        )['items'] ?? null;

        $locations = [];

        if (empty($addresses)) {
            return $locations;
        }

        foreach ($addresses as $address) {
            $lookup = $this->request('lookup', ['id' => $address['id']]);

            $locations[] = $this->locationObject($lookup);
        }

        return $locations;
    }


    public function byCoordinates($lat, $lng)
    {
        $addresses = $this->request(
            'reverse',
            [
                'at' => $lat . ',' . $lng,
                'limit' => 1,
            ]
        )['items'] ?? null;

        return $addresses ? $this->locationObject($addresses[0]) : null;
    }

    private function request($method, $options)
    {
        $response = $this->curl->get(
            $this->urls[$method],
            array_merge($options, $this->requestOptions)
        );

        $response_body = json_decode($response->body, true);

        if ($response->headers['Status-Code'] != 200 || $response_body == null)
            throw new \Exception('Geocoder API error.');

        return $response_body ?? null;
    }

    private function locationObject($address)
    {
        return new Location([
            'place_id'      => Arr::get($address, 'id'),
            'lat'           => Arr::get($address, 'position.lat'),
            'lng'           => Arr::get($address, 'position.lng'),
            'address'       => Arr::get($address, 'address.label'),
            'country'       => Arr::get($address, 'address.countryName'),
            'country_code'  => Arr::get($address, 'address.countryCode'),
            'state'         => Arr::get($address, 'address.state'),
            'county'        => Arr::get($address, 'address.county'),
            'city'          => Arr::get($address, 'address.city'),
            'road'          => Arr::get($address, 'address.street'),
            'house'         => Arr::get($address, 'address.houseNumber'),
            'zip'           => Arr::get($address, 'address.postalCode'),
            'type'          => Arr::get($address, 'resultType'),
        ]);
    }
}