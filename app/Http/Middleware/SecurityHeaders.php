<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $config = (array) config('security.security_headers', []);

        $headers = array_filter([
            'X-Frame-Options' => $config['x_frame_options'] ?? null,
            'X-Content-Type-Options' => $config['x_content_type_options'] ?? null,
            'X-XSS-Protection' => $config['x_xss_protection'] ?? null,
            'Referrer-Policy' => $config['referrer_policy'] ?? null,
            'Permissions-Policy' => $config['permissions_policy'] ?? null,
        ]);

        foreach ($headers as $header => $value) {
            $response->headers->set($header, (string) $value);
        }

        if ($request->isSecure() && ! empty($config['hsts_max_age'])) {
            $hsts = 'max-age='.(int) $config['hsts_max_age'];

            if (! empty($config['hsts_include_subdomains'])) {
                $hsts .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
