<?php

namespace Omnifood\UberEats\Tests;

use Omnifood\UberEats\UberEatsPlatformFactory;
use Omnifood\Testing\BareTestCase;

/** omnifood/ubereats's classes load with omnisense/omnifood, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return UberEatsPlatformFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container', 'symfony/polyfill-mbstring'];
    }
}
