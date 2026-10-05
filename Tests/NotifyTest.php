<?php

namespace Omnimeet\Jitsi\Tests;

use Omnimeet\Exception\InvalidNotificationException;
use Omnimeet\GatewayInterface;
use Omnimeet\Jitsi\JitsiGatewayFactory;
use Omnimeet\Model\Event;
use Omnimeet\Model\Role;
use Omnimeet\Request\Notify;
use PHPUnit\Framework\TestCase;

/**
 * A webhook of Jitsi as a Service, as its documentation gives it: the
 * payloads have the fields of the documentation's examples, the signature its
 * X-Jaas-Signature (t=<timestamp>,v1=base64 HMAC-SHA256 of "<t>.<body>").
 * Every identifier and secret here is made up.
 */
final class NotifyTest extends TestCase
{
    // Made up, in the shape JaaS gives them: not an account, not a secret.
    private const APP = 'vpaas-magic-cookie-00000000000000000000000000000000';
    private const SECRET = 'whsec_test_not_a_real_secret';

    private function gateway(int $tolerance = 300): GatewayInterface
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);

        return (new JitsiGatewayFactory())->create(['domain' => '8x8.vc', 'app_id' => self::APP, 'private_key' => $private, 'key_id' => self::APP.'/k', 'webhook_secret' => self::SECRET, 'webhook_tolerance' => $tolerance]);
    }

    private static function signature(string $body, ?int $at = null, string $secret = self::SECRET): string
    {
        $at ??= time();

        return 't='.$at.',v1='.base64_encode(hash_hmac('sha256', $at.'.'.$body, $secret, true));
    }

    private static function joined(): string
    {
        // The fields of the documentation's PARTICIPANT_JOINED example, every identifier replaced by a made-up one.
        return json_encode([
            'idempotencyKey' => 'idempotency-key-of-the-test-1',
            'customerId' => '00000000000000000000000000000000',
            'appId' => self::APP,
            'eventType' => 'PARTICIPANT_JOINED',
            'sessionId' => 'session-of-the-test-1',
            'timestamp' => 1600951818143,
            'fqn' => self::APP.'/testroom2',
            'data' => [
                'moderator' => 'true',
                'name' => 'John Doe',
                'group' => self::APP,
                'email' => 'john.doe@example.org',
                'id' => 'uniqueUserIdentifier',
                'participantJid' => 'participant-of-the-test@stage.8x8.vc',
                'participantId' => 'participant-of-the-test',
                'avatar' => 'https://example.org/avatar.png',
            ],
        ], \JSON_UNESCAPED_SLASHES);
    }

    public function testAParticipantCameIn(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(Notify::class));
        $body = self::joined();

        $notification = $gateway->notify($body, ['x-jaas-signature' => [self::signature($body)], 'Content-Type' => 'application/json']);

        self::assertSame(Event::JOINED, $notification->event);
        self::assertSame('PARTICIPANT_JOINED', $notification->type);
        self::assertSame('testroom2', $notification->reference, 'the room as open() named it, without the AppID');
        self::assertSame(['uniqueUserIdentifier', Role::HOST, 'John Doe'], [$notification->participant->id, $notification->participant->role, $notification->participant->name], 'the id the token carried');
        self::assertSame('2020-09-24 12:50:18', $notification->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'milliseconds');
        self::assertSame('idempotency-key-of-the-test-1', $notification->id, 'what a callback sent twice is recognised by');
        self::assertSame('session-of-the-test-1', $notification->data['sessionId']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('events')]
    public function testEachEventIsReadAsWhatItMeans(string $type, Event $event): void
    {
        $body = json_encode(['idempotencyKey' => 'k', 'appId' => self::APP, 'eventType' => $type, 'timestamp' => 1600952155289, 'fqn' => self::APP.'/testroom2', 'data' => ['conference' => 'testroom2@conference.'.self::APP.'.stage.8x8.vc', 'preAuthenticatedLink' => 'https://objectstorage.example/rec.mp4']]);
        $notification = $this->gateway(0)->notify($body, ['X-Jaas-Signature' => self::signature($body, 1600952155)]);

        self::assertSame([$event, $type, 'testroom2'], [$notification->event, $notification->type, $notification->reference]);
        self::assertNull($notification->participant);
        self::assertSame('https://objectstorage.example/rec.mp4', $notification->data['data']['preAuthenticatedLink']);
    }

    /** @return iterable<string, array{string, Event}> */
    public static function events(): iterable
    {
        yield 'ROOM_CREATED' => ['ROOM_CREATED', Event::OPENED];
        yield 'ROOM_DESTROYED' => ['ROOM_DESTROYED', Event::ENDED];
        yield 'PARTICIPANT_LEFT' => ['PARTICIPANT_LEFT', Event::LEFT];
        yield 'RECORDING_STARTED' => ['RECORDING_STARTED', Event::RECORDING_STARTED];
        yield 'RECORDING_ENDED' => ['RECORDING_ENDED', Event::RECORDING_ENDED];
        yield 'RECORDING_UPLOADED' => ['RECORDING_UPLOADED', Event::RECORDING_READY];
        yield 'anything else' => ['SPEAKER_STATS', Event::OTHER];
    }

    /** @param array<string, string> $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('forged')]
    public function testACallbackThatIsNotJaasIsRefused(string $body, array $headers, string $message): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage($message);
        $this->gateway()->notify($body, $headers);
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function forged(): iterable
    {
        $body = self::joined();
        yield 'no signature' => [$body, [], 'carries no X-Jaas-Signature'];
        yield 'another secret' => [$body, ['X-Jaas-Signature' => self::signature($body, null, 'whsec_test_another_made_up_secret')], 'signature does not hold'];
        yield 'a body changed on the way' => [str_replace('John Doe', 'Jane Doe', $body), ['X-Jaas-Signature' => self::signature($body)], 'signature does not hold'];
        yield 'replayed an hour later' => [$body, ['X-Jaas-Signature' => self::signature($body, time() - 3600)], 'too old'];
        yield 'signed, not an event' => ['[1]', ['X-Jaas-Signature' => self::signature('[1]')], 'not a JaaS event'];
    }

    public function testTheDocumentationsOwnSignatureFormat(): void
    {
        // "t=<a Unix timestamp>,v1=<the base64 of 32 bytes>": the header as JaaS's documentation shows it.
        $signature = self::signature('{"eventType":"PARTICIPANT_JOINED"}', 1632490060);

        self::assertMatchesRegularExpression('~^t=1632490060,v1=[A-Za-z0-9+/]{43}=$~', $signature);
    }
}
