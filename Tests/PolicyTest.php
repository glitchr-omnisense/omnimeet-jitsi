<?php

namespace Omnimeet\Jitsi\Tests;

use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Model\Meeting;
use Omnimeet\Registry;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/**
 * The jitsi gateway's policy is its instance's: meet.jit.si's terms, JaaS's,
 * or an unknown operator's. Nothing here calls the instance: opening a room
 * is naming it, so no limit nor wait can make it twice.
 */
final class PolicyTest extends TestCase
{
    public function testThePackageDeclaresThreePolicies(): void
    {
        $export = (new PolicyExport())->folders(__DIR__.'/..')->entries();
        self::assertSame(['policy-jaas.json', 'policy-meet-jit-si.json', 'policy.json'], array_column($export, 'file'));
        foreach ($export as $entry) {
            self::assertArrayNotHasKey('error', $entry, $entry['file']);
            self::assertSame('2026-11-10', UsagePolicy::fromFile(__DIR__.'/../'.$entry['file'])->provenance->verifiedAt);
        }

        $public = UsagePolicy::fromFile(__DIR__.'/../policy-meet-jit-si.json');
        self::assertNull($public->rateLimits, '25 active end points a month are people, not calls: no limit declared');
        self::assertSame(Permission::Contract, $public->rights->commercial);
        self::assertTrue($public->rights->attribution->required, 'its branding may not be removed nor hidden');
        self::assertSame('https://jitsi.org/meet-jit-si-terms-of-service/', $public->provenance->terms);

        $jaas = UsagePolicy::fromFile(__DIR__.'/../policy-jaas.json');
        self::assertTrue($jaas->requirements->key);
        self::assertSame(Permission::Contract, $jaas->rights->commercial);
        self::assertSame('https://8x8.com/cpaas-order-terms/jaas-module', $jaas->provenance->terms);
    }

    public function testTheGatewayTakesItsInstancesPolicy(): void
    {
        self::assertSame('jitsi', (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org'])->capabilities()->policy()->provider);
        self::assertSame('jitsi:meet.jit.si', (new JitsiGatewayFactory())->create(['domain' => 'https://Meet.Jit.Si/'])->capabilities()->policy()->provider);
        self::assertSame('jitsi:jaas', self::jaas()->capabilities()->policy()->provider);
    }

    public function testACommercialSiteWithoutAContractIsRefusedOnThePublicInstance(): void
    {
        $registry = new Registry([new JitsiGatewayFactory()], ['group' => ['factory' => 'jitsi', 'options' => ['domain' => 'meet.jit.si'], 'usage' => ['commercial' => true]]]);

        $this->expectException(UsageNotPermitted::class);
        $registry->guarded('group');
    }

    public function testAnUnknownRightStaysUnknownForTheSite(): void
    {
        $registry = new Registry([new JitsiGatewayFactory()], ['group' => ['factory' => 'jitsi', 'options' => ['domain' => 'meet.example.org'], 'policy' => ['rights' => ['commercial' => 'allowed']]]]);

        $this->expectException(InvalidPolicyException::class);
        $registry->policy('group');
    }

    public function testOpeningTheSameMeetingTwiceIsTheSameRoom(): void
    {
        $registry = new Registry([new JitsiGatewayFactory()], ['group' => ['factory' => 'jitsi', 'options' => ['domain' => 'meet.example.org'], 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'day', 'operations' => ['open']]]]]]);
        $gateway = $registry->guarded('group');

        self::assertSame($gateway->open(new Meeting('k'))->reference, $gateway->open(new Meeting('k'))->reference, 'nothing is created at the instance: the same key, the same room');
        self::assertSame(0, $registry->guard('group')->remaining('open'));
    }

    private static function jaas(): \Omnimeet\GatewayInterface
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($pair);
        openssl_pkey_export($pair, $private);
        $appId = 'vpaas-magic-cookie-0123456789abcdef';

        return (new JitsiGatewayFactory())->create(['domain' => '8x8.vc', 'app_id' => $appId, 'private_key' => $private, 'key_id' => $appId.'/test-key']);
    }
}
