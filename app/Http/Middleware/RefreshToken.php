<?php namespace App\Http\Middleware;

use Closure;
use App;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefreshToken {

    // const TOKEN = "";

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = bin2hex(random_bytes(16));
        $response = $next($request);
        
        // Las descargas no llevan la cabecera: son respuestas de Symfony y no
        // tienen el helper header() de Laravel, asi que llamarlo revienta.
        // BinaryFileResponse ya estaba contemplado; StreamedResponse no, y
        // cualquier descarga en streaming daba un 500 en este middleware.
        $descarga = $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse;

        if (! $descarga)
            $response->header('X-Refresh-Token', $token);

        return $response;
    }

}
