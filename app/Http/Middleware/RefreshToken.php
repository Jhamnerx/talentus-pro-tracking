<?php namespace App\Http\Middleware;

use Closure;
use App;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        
        if (!($response instanceof BinaryFileResponse))
            $response->header('X-Refresh-Token', $token);

        return $response;
    }

}
