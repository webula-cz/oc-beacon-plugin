<?php namespace Webula\Beacon\Classes;

use Cache;
use Config;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Signature verifies signed requests from the Lighthouse hub and signs the responses (protocol v2).
 *
 * The hub sends three headers:
 *   X-Beacon-Timestamp: unix timestamp
 *   X-Beacon-Nonce:     32 hex characters, used only once
 *   X-Beacon-Signature: hex hmac_sha256("beacon-request-v2\nGET\n{host}\nbeacon/status\n{timestamp}\n{nonce}", secret)
 *
 * The response carries X-Beacon-Response-Signature:
 *   hex hmac_sha256("beacon-response-v2\n{nonce}\n{body}", secret)
 *
 * The host binds a signature to one website, the nonce stops replays and the response signature
 * lets the hub detect forged data. Webula\Lighthouse\Classes\BeaconClient must use the same algorithm.
 */
class Signature
{
    /**
     * @var string ROUTE_PATH of the status endpoint (without leading slash)
     */
    const ROUTE_PATH = 'beacon/status';

    /**
     * @var int MAX_CLOCK_SKEW allowed difference between hub and site clocks, in seconds
     */
    const MAX_CLOCK_SKEW = 300;

    /**
     * @var int NONCE_TTL_MINUTES how long a used nonce is remembered, longer than the whole skew window
     */
    const NONCE_TTL_MINUTES = 11;

    /**
     * @var int MIN_SECRET_LENGTH shorter secrets are refused, the hub generates 48 characters
     */
    const MIN_SECRET_LENGTH = 32;

    /**
     * verifyRequest returns true for a correctly signed, fresh request with an unused nonce.
     */
    public static function verifyRequest(Request $request)
    {
        // A missing or weak secret disables the endpoint instead of accepting guessable signatures
        $secret = static::getSecret();
        if (strlen($secret) < static::MIN_SECRET_LENGTH) {
            return false;
        }

        $timestamp = (string) $request->header('X-Beacon-Timestamp');
        $nonce = (string) $request->header('X-Beacon-Nonce');
        $signature = (string) $request->header('X-Beacon-Signature');
        if (!ctype_digit($timestamp) || !static::isValidNonce($nonce) || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > static::MAX_CLOCK_SKEW) {
            return false;
        }

        $expected = static::signRequest($request->getMethod(), $request->getHost(), $timestamp, $nonce, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // Only a valid request uses up its nonce, the same request sent again is rejected
        return Cache::add('webula.beacon.nonce.' . $nonce, 1, Carbon::now()->addMinutes(static::NONCE_TTL_MINUTES));
    }

    /**
     * signRequest builds the request signature, the hub uses the same algorithm.
     */
    public static function signRequest($method, $host, $timestamp, $nonce, $secret)
    {
        $payload = implode("\n", [
            'beacon-request-v2',
            strtoupper($method),
            strtolower($host),
            static::ROUTE_PATH,
            $timestamp,
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * signResponse builds the response signature, bound to the request nonce.
     */
    public static function signResponse($nonce, $body, $secret)
    {
        return hash_hmac('sha256', "beacon-response-v2\n" . $nonce . "\n" . $body, $secret);
    }

    /**
     * isValidNonce accepts exactly 32 hex characters (16 random bytes).
     */
    public static function isValidNonce($nonce)
    {
        return strlen($nonce) === 32 && ctype_xdigit($nonce);
    }

    /**
     * getSecret returns the shared secret from the file configuration only, so backend users
     * cannot change it: config/webula/beacon/config.php overrides BEACON_SECRET from .env.
     */
    public static function getSecret()
    {
        return trim((string) Config::get('webula.beacon::secret'));
    }
}
