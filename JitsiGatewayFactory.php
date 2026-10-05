<?php

namespace Omnimeet\Jitsi;

use Omnimeet\Config;
use Omnimeet\GatewayFactory;
use Omnimeet\Jitsi\Action\JoinAction;
use Omnimeet\Jitsi\Action\NotifyAction;
use Omnimeet\Jitsi\Action\OpenAction;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Capabilities;

/**
 * Jitsi Meet: an instance of one's own, a public one, or 8x8's Jitsi as a
 * Service - embedded in the page through the IFrame API, several people,
 * the media through the instance's bridge (a third party unless the
 * instance is yours).
 *
 *   options:
 *     domain: meet.example.org                 # required: the instance (8x8.vc for JaaS)
 *     # an instance with token authentication, shared secret (HS256):
 *     app_id: my_app                           # Prosody's app_id: the token's iss
 *     app_secret: '%env(JITSI_APP_SECRET)%'    # Prosody's app_secret
 *     audience: jitsi                          # aud, as asap_accepted_audiences has it
 *     issuer: ~                                # iss, when it is not app_id
 *     subject: ~                               # sub, when it is not the domain (a tenant)
 *     # or a key pair (RS256) - the instance's key server, or JaaS:
 *     private_key: '%env(JITSI_PRIVATE_KEY)%'  # PEM, or the path of its file
 *     key_id: my_key                           # the token's kid (JaaS: "<AppID>/<key id>")
 *     # Jitsi as a Service: domain 8x8.vc, app_id the AppID (vpaas-magic-cookie-...)
 *     features: { recording: false, livestreaming: false, transcription: false, outbound-call: false }
 *     webhook_secret: '%env(JAAS_WEBHOOK_SECRET)%'   # whsec_...: notify() checks X-Jaas-Signature with it
 *     webhook_tolerance: 300                   # seconds a callback may be old; 0: any age
 *     # all:
 *     token_ttl: 7200                          # seconds a token lasts when the meeting has no end
 *     room_prefix: ''                          # before the room's name
 *     room_secret: ~                           # set it when meeting keys can be guessed
 *     config: { disableInviteFunctions: true, disableThirdPartyRequests: true }   # config.js overrides
 *     interface_config: {}                     # interface_config.js overrides
 *
 * Without app_secret nor private_key the instance is taken as open: anyone
 * who knows the room's name gets in, the name is the only secret.
 *
 * What Jitsi's documentation does not show stays unsupported: closing a
 * room and reading its state from the server (close(), fetch()) - the page
 * of a moderator ends the conference through the IFrame API instead - and
 * callbacks outside JaaS (notify() needs webhook_secret).
 */
final class JitsiGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnimeet.factory_name' => 'jitsi',
            'omnimeet.factory_title' => 'Jitsi Meet',
            'omnimeet.required_options' => ['domain'],
            'app_id' => null,
            'app_secret' => null,
            'private_key' => null,
            'key_id' => null,
            'jaas' => null,
            'audience' => null,
            'issuer' => null,
            'subject' => null,
            'features' => ['recording' => false, 'livestreaming' => false, 'transcription' => false, 'outbound-call' => false],
            'webhook_secret' => null,
            'webhook_tolerance' => 300,
            'token_ttl' => 7200,
            'room_prefix' => '',
            'room_secret' => null,
            'config' => ['disableInviteFunctions' => true, 'disableThirdPartyRequests' => true],
            'interface_config' => [],
            'omnimeet.capabilities' => static fn (Config $c) => new Capabilities(
                maxParticipants: null,
                thirdParty: true,
                endToEnd: false,
                recording: true === (((array) $c['features'])['recording'] ?? false),
                screenSharing: true,
                chat: true,
                tokens: '' !== (string) $c['app_secret'] || '' !== (string) $c['private_key'],
                access: [AccessKind::FRAME, AccessKind::LINK],
            ),
            'omnimeet.api' => static fn (Config $c) => new Api($c),
            'omnimeet.action.open' => new OpenAction(),
            'omnimeet.action.join' => new JoinAction(),
            // A callback can only be checked with JaaS's signing secret: without one the gateway hears none.
            'omnimeet.action.notify' => static fn (Config $c) => '' !== (string) $c['webhook_secret'] ? new NotifyAction() : null,
        ]);
    }
}
