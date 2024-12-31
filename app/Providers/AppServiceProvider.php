<?php namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
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
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        View::addLocation(base_path().'/Tobuli/Views');
        View::addNamespace('admin', base_path().'/Tobuli/Views/Admin');
        View::addNamespace('front', base_path().'/Tobuli/Views/Frontend');

        Blade::withoutDoubleEncoding();

        Paginator::useBootstrap();
    }
}