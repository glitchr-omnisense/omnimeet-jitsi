<?php

namespace Omnimeet\Jitsi\Tests;

use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Testing\BareTestCase;

/** Every class of omnimeet/jitsi loads with the core and omnisense/contracts alone. */
final class LoadAllTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return JitsiGatewayFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container'];
    }
}
