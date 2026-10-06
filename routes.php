<?php

use Webula\Beacon\Classes\Signature;
use Webula\Beacon\Classes\StatusCollector;

/*
 * Beacon status endpoint. Answers only signed requests from the Lighthouse hub,
 * everything else gets a plain 404 so the endpoint is not discoverable.
 * Both answers are marked no-store: a CDN must neither serve the status to others
 * nor keep a 404 that would lock out the hub.
 */
Route::get(Signature::ROUTE_PATH, function () {
    $request = Request::instance();

    if (!Signature::verifyRequest($request)) {
        return Response::make('Not Found', 404)
            ->header('Cache-Control', 'no-store, private');
    }

    $collector = new StatusCollector;
    $status = $collector->collect();
    $body = json_encode($status);

    // Keep the response signed and parseable even when some value cannot be encoded
    if ($body === false) {
        $body = json_encode([
            'beacon' => $status['beacon'],
            'errors' => ['json' => json_last_error_msg()],
        ]);
    }

    $nonce = (string) $request->header('X-Beacon-Nonce');

    return Response::make($body, 200)
        ->header('Content-Type', 'application/json')
        ->header('Cache-Control', 'no-store, private')
        ->header('X-Robots-Tag', 'noindex')
        ->header('X-Beacon-Response-Signature', Signature::signResponse($nonce, $body, Signature::getSecret()));
});
