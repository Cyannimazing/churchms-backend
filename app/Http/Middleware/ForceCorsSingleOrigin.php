<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCorsSingleOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Get the origin from request
        $origin = $request->header('Origin');
        
        // If origin matches our frontend, force single origin header
        if ($origin && (str_contains($origin, 'church-ms') || str_contains($origin, 'localhost'))) {
            // Remove any existing CORS headers to prevent duplicates
            $response->headers->remove('Access-Control-Allow-Origin');
            $response->headers->remove('Access-Control-Allow-Methods');
            $response->headers->remove('Access-Control-Allow-Headers');
            $response->headers->remove('Access-Control-Allow-Credentials');
            
            // Set fresh CORS headers with single origin
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            $response->headers->set('Access-Control-Allow-Credentials', 'false');
            $response->headers->set('X-Cors-Adjusted', 'yes');
        }
        
        return $response;
    }
}
