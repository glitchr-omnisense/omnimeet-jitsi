<?php

namespace Omnimeet\Jitsi\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Exception\InvalidNotificationException;
use Omnimeet\Jitsi\Api;
use Omnimeet\Model\Event;
use Omnimeet\Model\Notification;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Role;
use Omnimeet\Request\Notify;
use Omnimeet\Request\Request;

/**
 * A webhook of Jitsi as a Service: a POST with a JSON body, signed in the
 * X-Jaas-Signature header ("t=<timestamp>,v1=<signature>": the base64
 * HMAC-SHA256 of "<timestamp>.<body>" with the endpoint's signing secret).
 * A signature that does not hold, or older than the tolerance, is refused.
 * The meeting is named by "fqn" (<AppID>/<room>), the same callback sent
 * twice by "idempotencyKey".
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const EVENTS = [
        'ROOM_CREATED' => Event::OPENED,
        'ROOM_DESTROYED' => Event::ENDED,
        'PARTICIPANT_JOINED' => Event::JOINED,
        'PARTICIPANT_LEFT' => Event::LEFT,
        'RECORDING_STARTED' => Event::RECORDING_STARTED,
        'RECORDING_ENDED' => Event::RECORDING_ENDED,
        'RECORDING_UPLOADED' => Event::RECORDING_READY,
    ];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    /** @param Notify $request */
    public function execute(Request $request): void
    {
        $secret = $this->api->webhookSecret() ?? throw new InvalidNotificationException('jitsi', 'No webhook_secret: a callback cannot be checked.');
        $header = (string) $request->header('X-Jaas-Signature');
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$name] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ('' === $timestamp || '' === $signature || !ctype_digit($timestamp)) {
            throw new InvalidNotificationException('jitsi', 'The callback carries no X-Jaas-Signature (t=..., v1=...).');
        }
        if (!hash_equals(base64_encode(hash_hmac('sha256', $timestamp.'.'.$request->body, $secret, true)), $signature)) {
            throw new InvalidNotificationException('jitsi', 'The callback\'s signature does not hold.');
        }
        $tolerance = $this->api->webhookTolerance();
        if ($tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            throw new InvalidNotificationException('jitsi', 'The callback is too old.');
        }

        $payload = json_decode($request->body, true);
        if (!\is_array($payload) || !isset($payload['eventType'])) {
            throw new InvalidNotificationException('jitsi', 'The callback is not a JaaS event.');
        }
        $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $type = (string) $payload['eventType'];
        $event = self::EVENTS[$type] ?? Event::OTHER;

        $participant = null;
        if (\in_array($event, [Event::JOINED, Event::LEFT], true) && isset($data['id']) && '' !== (string) $data['id']) {
            // The id the token carried (context.user.id): the application's own, opaque.
            $participant = new Participant((string) $data['id'], 'true' === ($data['moderator'] ?? null) || true === ($data['moderator'] ?? null) ? Role::HOST : Role::GUEST, isset($data['name']) ? (string) $data['name'] : null);
        }

        $request->setResult(new Notification(
            $event,
            $type,
            isset($payload['fqn']) ? $this->api->reference((string) $payload['fqn']) : null,
            $participant,
            // "timestamp": Unix time in milliseconds.
            isset($payload['timestamp']) ? (new \DateTimeImmutable())->setTimestamp((int) floor(((int) $payload['timestamp']) / 1000)) : null,
            isset($payload['idempotencyKey']) ? (string) $payload['idempotencyKey'] : null,
            $payload,
        ));
    }
}
