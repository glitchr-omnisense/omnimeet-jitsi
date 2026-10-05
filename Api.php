<?php

namespace Omnimeet\Jitsi;

use Omnimeet\Config;
use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;

/**
 * One Jitsi Meet instance, as configured: its address, how its rooms are
 * named, and how a participant's token is made. Nothing here calls the
 * instance - a Jitsi room exists from the moment its first participant
 * comes in, and a token is signed on this side.
 *
 * Three kinds of instance, told apart by the options:
 *   - open (no app_secret, no private_key): anyone with the room's name
 *     gets in - the name is the only secret;
 *   - with token authentication (app_id and app_secret, or a private_key
 *     the instance validates against its key server): a JWT per participant;
 *   - Jitsi as a Service (8x8.vc, an AppID "vpaas-magic-cookie-..."): rooms
 *     under the AppID, RS256 tokens with JaaS's own claims.
 */
final class Api
{
    public const ENGINE = 'jitsi';
    public const JAAS_DOMAIN = '8x8.vc';

    public readonly string $domain;
    public readonly ?string $appId;
    public readonly bool $jaas;

    public function __construct(private readonly Config $config)
    {
        $domain = strtolower(trim((string) $config['domain']));
        $domain = (string) preg_replace('~^https?://~', '', rtrim($domain, '/'));
        if (!preg_match('~^[a-z0-9.-]+(?::\d+)?$~', $domain)) {
            throw new InvalidConfigException(\sprintf('The jitsi gateway\'s domain is a host name ("meet.example.org"), not "%s".', $config['domain']));
        }
        $this->domain = $domain;
        $appId = trim((string) $config->get('app_id', ''));
        $this->appId = '' === $appId ? null : $appId;
        $this->jaas = (bool) ($config->get('jaas') ?? (self::JAAS_DOMAIN === $domain || str_starts_with($appId, 'vpaas-magic-cookie-')));
        if ($this->jaas && (null === $this->appId || !$this->hasKeyPair())) {
            throw new InvalidConfigException('Jitsi as a Service needs app_id (the AppID), private_key and key_id (the API key uploaded in its console).');
        }
        if ($this->hasKeyPair() && '' === (string) $config->get('key_id', '')) {
            throw new InvalidConfigException('A token signed with a private key names its public key: the jitsi gateway\'s key_id.');
        }
        if ($this->hasSecret() && null === $this->appId) {
            throw new InvalidConfigException('A token signed with app_secret names its application: the jitsi gateway\'s app_id.');
        }
    }

    /** Whether participants enter with a token. */
    public function signs(): bool
    {
        return $this->hasSecret() || $this->hasKeyPair();
    }

    /**
     * The room of a meeting: the same key, the same room; lower case, as
     * Jitsi reads room names. It says nothing of the key - and cannot be
     * guessed unless the key can: set room_secret when keys are guessable.
     */
    public function room(Meeting $meeting): string
    {
        $secret = (string) $this->config->get('room_secret', '');
        $digest = '' === $secret ? hash('sha256', 'omnimeet:'.$meeting->key) : hash_hmac('sha256', $meeting->key, $secret);

        return strtolower((string) $this->config->get('room_prefix', '')).substr($digest, 0, 32);
    }

    /** The room as the IFrame API and the address name it: under the AppID on JaaS. */
    public function roomName(string $reference): string
    {
        return $this->jaas ? $this->appId.'/'.$reference : $reference;
    }

    /** The reference of a room named by JaaS ("<AppID>/<room>"). */
    public function reference(string $fqn): string
    {
        return $this->jaas && str_starts_with($fqn, $this->appId.'/') ? substr($fqn, \strlen((string) $this->appId) + 1) : $fqn;
    }

    public function origin(): string
    {
        return 'https://'.$this->domain;
    }

    /** The IFrame API's script, served by the instance itself. */
    public function scriptUrl(): string
    {
        return $this->origin().($this->jaas ? '/'.$this->appId : '').'/external_api.js';
    }

    /**
     * The address of the room, for a bare frame or a link: the token as the
     * jwt parameter, the configuration overrides after the #, each as
     * config.<key>=<JSON value>.
     */
    public function url(string $reference, ?string $token = null, ?string $locale = null): string
    {
        $overrides = $this->configOverwrite($locale);
        $fragment = [];
        foreach (self::flatten($overrides) as $key => $value) {
            $fragment[] = 'config.'.$key.'='.rawurlencode(json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return $this->origin().'/'.$this->roomName($reference)
            .(null !== $token ? '?jwt='.$token : '')
            .($fragment ? '#'.implode('&', $fragment) : '');
    }

    /** @return array<string, mixed> config.js overrides: the configured ones, and the participant's language */
    public function configOverwrite(?string $locale = null): array
    {
        return (array) $this->config->get('config', []) + (null !== $locale && '' !== $locale ? ['defaultLanguage' => $locale] : []);
    }

    /** @return array<string, mixed> */
    public function interfaceConfigOverwrite(): array
    {
        return (array) $this->config->get('interface_config', []);
    }

    /** When a token made now for this meeting lapses: a while after the meeting's end, or token_ttl seconds from now. */
    public function expiry(Meeting $meeting, ?int $now = null): int
    {
        $now ??= time();
        $ttl = max(60, (int) $this->config->get('token_ttl', 7200));

        return null !== $meeting->endsAt && $meeting->endsAt->getTimestamp() > $now ? $meeting->endsAt->getTimestamp() + 300 : $now + $ttl;
    }

    /**
     * The participant's token for this room, or null on an open instance.
     *
     * Self-hosted (lib-jitsi-meet's token plugin): iss, the application's
     * id; aud; sub, the instance's domain or tenant; room; exp; and the
     * display information under context.user (id, name). JaaS: aud "jitsi",
     * iss "chat", sub the AppID, nbf, context.user.moderator as the string
     * "true" or "false", context.features, and a kid in the header.
     */
    public function token(Meeting $meeting, Participant $participant, ?int $now = null): ?string
    {
        if (!$this->signs()) {
            return null;
        }
        $now ??= time();
        $room = $meeting->reference();
        // "At the moment all fields in 'user' need to be a valid string": no null, no number.
        $user = ['id' => $participant->id] + (null !== $participant->name && '' !== $participant->name ? ['name' => $participant->name] : []);

        if ($this->jaas) {
            $claims = [
                'aud' => 'jitsi',
                'iss' => 'chat',
                'sub' => $this->appId,
                'room' => $room,
                'nbf' => $now - 10,
                'exp' => $this->expiry($meeting, $now),
                'context' => [
                    'user' => $user + ['moderator' => $participant->isHost() ? 'true' : 'false'],
                    'features' => array_map(static fn ($on) => (bool) $on, (array) $this->config->get('features', [])),
                ],
            ];
        } else {
            $claims = [
                'aud' => (string) ($this->config->get('audience') ?: 'jitsi'),
                'iss' => (string) ($this->config->get('issuer') ?: $this->appId),
                'sub' => (string) ($this->config->get('subject') ?: preg_replace('~:\d+$~', '', $this->domain)),
                'room' => $room,
                'exp' => $this->expiry($meeting, $now),
                'context' => ['user' => $user],
            ];
        }

        return $this->hasKeyPair()
            ? Jwt::sign($claims, (string) $this->config['private_key'], Jwt::RS256, (string) $this->config['key_id'])
            : Jwt::sign($claims, (string) $this->config['app_secret'], Jwt::HS256);
    }

    /** Whether the instance may be shown in a frame: some forbid it (X-Frame-Options, frame-ancestors). */
    public function embeds(): bool
    {
        return (bool) $this->config->get('embed', true);
    }

    public function webhookSecret(): ?string
    {
        $secret = (string) $this->config->get('webhook_secret', '');

        return '' === $secret ? null : $secret;
    }

    public function webhookTolerance(): int
    {
        return max(0, (int) $this->config->get('webhook_tolerance', 300));
    }

    /** The JavaScript that drives the frame, as the package ships it: the application serves this file. */
    public static function script(): string
    {
        return __DIR__.'/public/js/jitsi.js';
    }

    private function hasSecret(): bool
    {
        return '' !== (string) $this->config->get('app_secret', '');
    }

    private function hasKeyPair(): bool
    {
        return '' !== (string) $this->config->get('private_key', '');
    }

    /**
     * {prejoinConfig: {enabled: false}} -> {"prejoinConfig.enabled": false}; a list stays a value.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $flat += self::flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
