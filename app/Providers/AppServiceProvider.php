<?php namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;


class AppServiceProvider extends ServiceProvider {

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (version_compare(phpversion(), '8.0.0', '<')) {
            abort(404);
        }
        $cachePath = storage_path('cache/storage');
        if (!File::exists($cachePath)) {
            abort(404);
        }
        
        Route::singularResourceParameters(false);

        try {
            $this->app['config']->set('app.name', settings('main_settings.server_name'));
        } catch (\Exception $exception) {}

        if (config('app.debug')) {
            error_reporting(E_ALL & ~E_USER_DEPRECATED);
        } else {
            error_reporting(0);
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $pathCustom = storage_path('views');
        $pathMain = base_path('Tobuli/Views');

        $this->registerPath($pathCustom);
        $this->registerPath($pathMain);

        Blade::withoutDoubleEncoding();

        Paginator::useBootstrap();
    }

    protected function registerPath(string $path): void
    {
        View::addLocation($path);
        View::addNamespace('admin', $path . '/Admin');
        View::addNamespace('front', $path . '/Frontend');
    }
}