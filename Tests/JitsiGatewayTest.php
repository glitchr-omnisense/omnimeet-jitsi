<?php

namespace Omnimeet\Jitsi\Tests;

use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Exception\NotSupportedException;
use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Jitsi\Jwt;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Status;
use Omnimeet\Request\Close;
use Omnimeet\Request\Fetch;
use Omnimeet\Request\Join;
use Omnimeet\Request\Notify;
use Omnimeet\Request\Open;
use PHPUnit\Framework\TestCase;

/**
 * The three kinds of instance - open, with token authentication, Jitsi as a
 * Service - as their documentation describes them. No instance is called:
 * what is checked is what would be sent.
 */
final class JitsiGatewayTest extends TestCase
{
    private function meeting(): Meeting
    {
        return new Meeting('a-key-nobody-guesses', new \DateTimeImmutable('+5 minutes'), new \DateTimeImmutable('+35 minutes'), 'Consultation');
    }

    public function testAnOpenInstanceIsEnteredByTheRoomsNameAlone(): void
    {
        $gateway = (new JitsiGatewayFactory())->create(['domain' => 'https://Meet.Example.org/']);

        self::assertSame(['jitsi', 'Jitsi Meet'], [$gateway->getName(), $gateway->getTitle()]);
        $capabilities = $gateway->capabilities();
        self::assertNull($capabilities->maxParticipants, 'several people: the instance\'s own limit');
        self::assertTrue($capabilities->thirdParty);
        self::assertFalse($capabilities->endToEnd);
        self::assertFalse($capabilities->tokens);
        self::assertFalse($capabilities->recording);
        self::assertSame([AccessKind::FRAME, AccessKind::LINK], $capabilities->access);

        $meeting = $gateway->open($this->meeting());
        self::assertSame(Status::OPEN, $meeting->status);
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $meeting->reference(), 'lower case, as Jitsi reads room names; nothing of the key');
        self::assertStringNotContainsString('a-key', $meeting->reference());
        self::assertSame($meeting->reference, $gateway->open($this->meeting())->reference, 'the same key, the same room');
        self::assertNotSame($meeting->reference, $gateway->open(new Meeting('another-key'))->reference);

        $access = $gateway->join($meeting, Participant::guest('u7', 'Camille Érable', 'fr'));
        self::assertSame(AccessKind::FRAME, $access->kind);
        self::assertSame(['https://meet.example.org'], $access->origins);
        self::assertSame('https://meet.example.org/'.$meeting->reference.'#config.disableInviteFunctions=true&config.disableThirdPartyRequests=true&config.defaultLanguage=%22fr%22', $access->url, 'no token; the overrides after the #');
        self::assertNull($access->expiresAt);
        self::assertSame('jitsi', $access->engine);
        self::assertFileExists((string) $access->script);
        self::assertSame([
            'domain' => 'meet.example.org',
            'api' => 'https://meet.example.org/external_api.js',
            'room' => $meeting->reference,
            'role' => 'guest',
            'name' => 'Camille Érable',
            'locale' => 'fr',
            'subject' => 'Consultation',
            'config' => ['disableInviteFunctions' => true, 'disableThirdPartyRequests' => true],
        ], $access->options, 'what JitsiMeetExternalAPI(domain, options) takes');
    }

    public function testWhatTheDocumentationDoesNotShowIsNotSupported(): void
    {
        $gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org']);

        self::assertTrue($gateway->supports(Open::class));
        self::assertTrue($gateway->supports(Join::class));
        self::assertFalse($gateway->supports(Close::class), 'no documented way to end a conference from the server');
        self::assertFalse($gateway->supports(Fetch::class), 'nor to read its state');
        self::assertFalse($gateway->supports(Notify::class), 'callbacks are JaaS\'s, and need its signing secret');

        $meeting = $gateway->open($this->meeting());
        foreach (['close', 'fetch'] as $method) {
            try {
                $gateway->{$method}($meeting);
                self::fail($method.'() is not supported.');
            } catch (NotSupportedException) {
            }
        }
        $this->expectException(NotSupportedException::class);
        $gateway->notify('{}');
    }

    public function testAnInstanceWithTokenAuthenticationGetsAJwtPerParticipant(): void
    {
        $gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org', 'app_id' => 'my_app', 'app_secret' => 'my_app_secret', 'room_prefix' => 'Cabinet-']);
        self::assertTrue($gateway->capabilities()->tokens);

        $meeting = $gateway->open($this->meeting());
        self::assertStringStartsWith('cabinet-', $meeting->reference());
        $access = $gateway->join($meeting, Participant::host('u42', 'Dr Claire Tilleul'));
        $token = $access->options['jwt'];

        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], Jwt::header($token), 'shared secret validation');
        $claims = Jwt::verify($token, 'my_app_secret');
        self::assertNotNull($claims, 'signed with the application secret');
        self::assertSame(['aud', 'iss', 'sub', 'room', 'exp', 'context'], array_keys($claims));
        self::assertSame(['jitsi', 'my_app', 'meet.example.org', $meeting->reference], [$claims['aud'], $claims['iss'], $claims['sub'], $claims['room']], 'the application, the domain, this room and no other');
        self::assertSame(['user' => ['id' => 'u42', 'name' => 'Dr Claire Tilleul']], $claims['context'], 'display information: strings only, no e-mail, no avatar');
        self::assertSame($meeting->endsAt->getTimestamp() + 300, $claims['exp'], 'lapses a while after the meeting\'s end');
        self::assertSame($claims['exp'], $access->expiresAt->getTimestamp());
        self::assertStringStartsWith('https://meet.example.org/'.$meeting->reference.'?jwt='.$token.'#config.', $access->url, 'the token as the jwt parameter');

        $guest = Jwt::verify($gateway->join($meeting, Participant::guest('u7'))->options['jwt'], 'my_app_secret');
        self::assertSame(['id' => 'u7'], $guest['context']['user'], 'no name when the application gives none');
    }

    public function testAudienceIssuerSubjectAndLifetimeFollowTheInstance(): void
    {
        $gateway = (new JitsiGatewayFactory())->create([
            'domain' => 'meet.example.org:8443', 'app_id' => 'my_app', 'app_secret' => 's',
            'audience' => 'my_server', 'issuer' => 'my_web_client', 'subject' => 'tenant1', 'token_ttl' => 900,
        ]);
        $meeting = $gateway->open(new Meeting('k'));
        $access = $gateway->join($meeting, Participant::guest('u7'));
        $claims = Jwt::verify($access->options['jwt'], 's');

        self::assertSame(['my_server', 'my_web_client', 'tenant1'], [$claims['aud'], $claims['iss'], $claims['sub']]);
        self::assertEqualsWithDelta(time() + 900, $claims['exp'], 5, 'a meeting without an end: token_ttl');
        self::assertSame('https://meet.example.org:8443/external_api.js', $access->options['api']);
        self::assertSame('meet.example.org', Jwt::verify((new JitsiGatewayFactory())->create(['domain' => 'meet.example.org:8443', 'app_id' => 'a', 'app_secret' => 's'])->join($meeting, Participant::guest('u'))->options['jwt'], 's')['sub'], 'the domain without its port');
    }

    public function testJitsiAsAServiceHasItsOwnClaimsRoomsAndScript(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);
        $public = openssl_pkey_get_details($pair)['key'];
        $appId = 'vpaas-magic-cookie-1fc542a3e4414a44b2611668195e2bfe';

        $gateway = (new JitsiGatewayFactory())->create(['domain' => '8x8.vc', 'app_id' => $appId, 'private_key' => $private, 'key_id' => $appId.'/4f4910', 'features' => ['recording' => true]]);
        self::assertTrue($gateway->capabilities()->recording);

        $meeting = $gateway->open($this->meeting());
        $access = $gateway->join($meeting, Participant::host('u42', 'Dr Claire Tilleul'));
        self::assertSame('https://8x8.vc/'.$appId.'/external_api.js', $access->options['api']);
        self::assertSame($appId.'/'.$meeting->reference, $access->options['room'], '"<AppID>/<room>"');
        self::assertStringStartsWith('https://8x8.vc/'.$appId.'/'.$meeting->reference.'?jwt=', $access->url);

        $token = $access->options['jwt'];
        self::assertSame(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $appId.'/4f4910'], Jwt::header($token));
        $claims = Jwt::verify($token, $public);
        self::assertSame(['jitsi', 'chat', $appId, $meeting->reference], [$claims['aud'], $claims['iss'], $claims['sub'], $claims['room']], 'aud and iss are JaaS\'s constants, sub the AppID');
        self::assertLessThanOrEqual(time(), $claims['nbf']);
        self::assertSame(['id' => 'u42', 'name' => 'Dr Claire Tilleul', 'moderator' => 'true'], $claims['context']['user'], 'moderator as a string');
        self::assertSame(['recording' => true], $claims['context']['features']);
        self::assertSame('false', Jwt::verify($gateway->join($meeting, Participant::guest('u7'))->options['jwt'], $public)['context']['user']['moderator']);
    }

    public function testRoomSecretKeepsAGuessableKeyFromNamingTheRoom(): void
    {
        $plain = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org'])->open(new Meeting('appointment:12'));
        $keyed = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org', 'room_secret' => 'only-this-application-knows'])->open(new Meeting('appointment:12'));

        self::assertSame(substr(hash('sha256', 'omnimeet:appointment:12'), 0, 32), $plain->reference, 'anyone who guesses the key finds the room');
        self::assertNotSame($plain->reference, $keyed->reference);
        self::assertSame(substr(hash_hmac('sha256', 'appointment:12', 'only-this-application-knows'), 0, 32), $keyed->reference);
    }

    public function testConfigurationOverridesGoToTheFrameAndTheAddress(): void
    {
        $gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org', 'config' => ['prejoinConfig' => ['enabled' => false], 'toolbarButtons' => ['microphone', 'camera']], 'interface_config' => ['TILE_VIEW_MAX_COLUMNS' => 2]]);
        $access = $gateway->join($gateway->open(new Meeting('k')), Participant::guest('u7'));

        self::assertSame(['prejoinConfig' => ['enabled' => false], 'toolbarButtons' => ['microphone', 'camera']], $access->options['config']);
        self::assertSame(['TILE_VIEW_MAX_COLUMNS' => 2], $access->options['interfaceConfig']);
        self::assertStringEndsWith('#config.prejoinConfig.enabled=false&config.toolbarButtons=%5B%22microphone%22%2C%22camera%22%5D', $access->url);
        self::assertArrayNotHasKey('jwt', $access->options);
        self::assertArrayNotHasKey('name', $access->options);
    }

    public function testAnInstanceThatForbidsBeingFramedGivesItsAddress(): void
    {
        $gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org', 'app_id' => 'a', 'app_secret' => 's', 'embed' => false]);
        self::assertSame([AccessKind::LINK], $gateway->capabilities()->access);

        $meeting = $gateway->open($this->meeting());
        $access = $gateway->join($meeting, Participant::guest('u7'));
        self::assertSame(AccessKind::LINK, $access->kind);
        self::assertStringStartsWith('https://meet.example.org/'.$meeting->reference.'?jwt=', (string) $access->url);
        self::assertNull($access->script, 'nothing to drive');
        self::assertSame(['https://meet.example.org'], $access->origins);
        self::assertSame($meeting->endsAt->getTimestamp() + 300, $access->expiresAt->getTimestamp());
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('misconfigured')]
    public function testAnInstanceMisconfiguredIsSaidWhenItIsFirstAsked(array $options, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);
        $gateway = (new JitsiGatewayFactory())->create($options);
        $gateway->open(new Meeting('k'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function misconfigured(): iterable
    {
        yield 'no instance' => [[], 'The "jitsi" gateway needs: domain.'];
        yield 'not a host name' => [['domain' => 'meet.example.org/room'], 'The jitsi gateway\'s domain is a host name'];
        yield 'a secret without its application' => [['domain' => 'meet.example.org', 'app_secret' => 's'], 'names its application'];
        yield 'a key without its name' => [['domain' => 'meet.example.org', 'private_key' => '-----BEGIN PRIVATE KEY-----'], 'names its public key'];
        yield 'JaaS without a key' => [['domain' => '8x8.vc', 'app_id' => 'vpaas-magic-cookie-1'], 'Jitsi as a Service needs app_id'];
    }
}
