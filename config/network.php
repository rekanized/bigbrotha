<?php

$trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')))));
$websiteAllowedIps = array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_ALLOWED_IPS', '')))));

return [
    'trusted_proxies' => $trustedProxies,
    'website_allowed_ips' => $websiteAllowedIps,
];
