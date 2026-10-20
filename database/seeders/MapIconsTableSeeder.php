<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\File;
use Tobuli\Repositories\MapIcon\MapIconRepositoryInterface as MapIcon;
use Illuminate\Database\Seeder;

class MapIconsTableSeeder extends Seeder {
    /**
     * @var MapIcon
     */
    private $mapIcon;

    public function __construct(MapIcon $mapIcon)
    {
        $this->mapIcon = $mapIcon;
    }

	public function run()
    {
        # Icons
        $folder = base_path('images/map_icons');

        if( ! File::isDirectory($folder))
            $folder = public_path('images/map_icons');

        $files = File::allFiles($folder);

        foreach ($files as $file) {
            if (!is_object($file) || empty($file->getFilename()))
                continue;

            list($width, $height) = getimagesize($file);
            if (!$width || !$height)
                continue;

            // allFiles() es recursivo: conservar la subcarpeta (poi/) en el path.
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $path = 'images/map_icons/' . $relative;

            if ($this->mapIcon->findWhere(['path' => $path]))
                continue;

            // Los iconos de POI rediseñados (poi/) son de 64 px para pantallas
            // retina, pero en el mapa se dibujan a 32 px.
            if (str_starts_with($relative, 'poi/')) {
                $width = $height = 32;
            }

            $this->mapIcon->create([
                'path'   => $path,
                'width'  => $width,
                'height' => $height,
            ]);
        }
    }
}