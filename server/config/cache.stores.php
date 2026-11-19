<?php

/*
| The one-time hand-off of the ERPNext key and secret to the queued job. Its own fixed prefix,
| because the application and queue containers can run with different CACHE_PREFIX values and
| the job must read exactly what the web request wrote.
*/
return [
    'alvora_handoff' => [
        'driver'          => 'redis',
        'connection'      => 'cache',
        'lock_connection' => 'default',
        'prefix'          => 'alvora_handoff:',
    ],
];
