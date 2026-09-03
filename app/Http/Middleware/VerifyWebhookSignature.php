<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Verify an inbound payment/provider webhook using an HMAC-SHA256 signature.
     *
     * The gateway is resolved from the route parameter {gateway} so that each
     * provider can carry its own secret and signature header.
     */
    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = $gateway ?? (string) $request->route('gateway');

        $secret = config("payment.gateways.{$gateway}.webhook_secret");
        $header = config("payment.gateways.{$gateway}.signature_header", 'X-Signature');

        if (empty($secret)) {
            Log::warning('Webhook rejected: no signing secret configured.', ['gateway' => $gateway]);

            abort(403, 'Webhook signature verification is not configured.');
        }

        $provided = (string) $request->header($header, '');

        if ($provided === '') {
            abort(403, 'Missing webhook signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            Log::warning('Webhook rejected: signature mismatch.', ['gateway' => $gateway]);

            abort(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
