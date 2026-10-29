<?php

return [
    /**
     * LAN IP/hostname of the HR PC/server as seen by the K40 Pro.
     * Required when APP_URL is http://127.0.0.1 — the device cannot push to localhost.
     * Example: 192.168.1.147
     */
    'lan_host' => env('ZKTECO_LAN_HOST'),

    /**
     * Optional full ADMS base URL override (no trailing slash), e.g. http://192.168.1.147:8000/iclock
     */
    'adms_public_url' => env('ZKTECO_ADMS_PUBLIC_URL'),
];
