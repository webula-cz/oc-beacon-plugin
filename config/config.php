<?php

return [
    /*
     * Shared secret for signing requests from the Lighthouse hub. Never stored in the database,
     * so backend users cannot change it. Set it per website in one of these ways:
     *   - October CMS 3/4: BEACON_SECRET in .env
     *   - October CMS 1: config/webula/beacon/config.php with ['secret' => '...'] (overrides .env)
     * Without a secret the endpoint always answers 404.
     */
    'secret' => env('BEACON_SECRET', ''),
];
