<?php

namespace Omnimeet\Jitsi\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Jitsi\Api;
use Omnimeet\Model\Access;
use Omnimeet\Request\Join;
use Omnimeet\Request\Request;

/**
 * A frame on the instance's own interface: its address (the token as the
 * jwt parameter, the overrides after the #), and, to drive it from the
 * page, this package's script with what the IFrame API's
 * JitsiMeetExternalAPI(domain, options) takes - roomName, jwt, userInfo,
 * lang, configOverwrite, interfaceConfigOverwrite. The token, when the
 * instance checks one, is made for this participant and this room, and
 * lapses a while after the meeting's end.
 */
final class JoinAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Join;
    }

    /** @param Join $request */
    public function execute(Request $request): void
    {
        $meeting = $request->meeting;
        $participant = $request->participant;
        $reference = $meeting->reference();
        $now = time();
        $token = $this->api->token($meeting, $participant, $now);

        $request->setResult(Access::frame(
            $this->api->url($reference, $token, $participant->locale),
            Api::ENGINE,
            Api::script(),
            array_filter([
                'domain' => $this->api->domain,
                'api' => $this->api->scriptUrl(),
                'room' => $this->api->roomName($reference),
                'role' => $participant->role->value,
                'jwt' => $token,
                'name' => $participant->name,
                'locale' => $participant->locale,
                'subject' => $meeting->title,
                'config' => $this->api->configOverwrite() ?: null,
                'interfaceConfig' => $this->api->interfaceConfigOverwrite() ?: null,
            ], static fn ($value) => null !== $value),
            [$this->api->origin()],
            null !== $token ? (new \DateTimeImmutable())->setTimestamp($this->api->expiry($meeting, $now)) : null,
        ));
    }
}
