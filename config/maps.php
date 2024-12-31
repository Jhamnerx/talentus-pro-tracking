<?php
return [
    'list' => [
        'Google Normal' => 1,
        'OpenStreetMap' => 2,
        'Google Hybrid' => 3,
        'Google Satellite' => 4,
        'Google Terrain' => 5,
        'Yandex' => 6,
        'Bing Normal' => 7,
        'Bing Satellite' => 8,
        'Bing Hybrid' => 9,
        'Here Normal' => 10,
        'Here Satellite' => 11,
        'Here Hybrid' => 12,
        'MapBox Normal' => 14,
        'MapBox Satellite' => 15,
        'MapBox Hybrid' => 16,
        'MapTiler Basic' => 17,
        'MapTiler Streets' => 18,
        'MapTiler Satellite' => 19,

        'OpenMapTiles OSM' => 21,

        'OpenRailway Infrastructure' => 22,
        'OpenRailway Max Speeds' => 23,
        'OpenRailway Signaling' => 24,
        'OpenRailway Electrification' => 25,

        'TomTom Basic' => 26,
        'TomTom Satellite' => 27,

        'Azure Normal' => 28,
        'Azure Hybrid' => 29,
        'Azure Satellite' => 30,
        
        'Google Normal B' => 51,
        'Google Hybrid B' => 53,
        'Google Satellite B' => 54,
        'Google Terrain B' => 55,
        'Google Hybrid Traffic' => 56,

        'Carto' => 70,
        'LocationIQ Streets' => 71,
    ],

    'zoom_levels' => array_combine(
        range(19, 0),
        range(19, 0)
    ),
];