<?php

function domain_info(string $domain): array
{
    static $service = null;
    static $cache = [];

    if(  ! $service) {
        $service = \SPeter81\DomainInfo\DomainInfoService::create();
    }
    $key = md5($domain);
    if ( ! array_key_exists($key, $cache)) {
        $cache[$key] = $service->lookup($domain);
    }

    return $cache[$key];
}
