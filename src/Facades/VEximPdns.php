<?php

namespace VEximweb\Plugin\PDNS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \VEximweb\Plugin\PDNS\VEximPdns
 */
class VEximPdns extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VEximweb\Plugin\PDNS\VEximPdns::class;
    }
}
