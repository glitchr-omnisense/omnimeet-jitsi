<?php

namespace Omnimeet\Jitsi;

use Omnimeet\Exception\InvalidConfigException;

/**
 * The JSON Web Token (RFC 7519) a Jitsi Meet instance checks, signed with
 * what PHP has: HS256 with the instance's application secret (hash_hmac),
 * RS256 with a private key (openssl) - the two validations Jitsi's token
 * plugin knows, shared secret and public key.
 */
final class Jwt
{
    public const HS256 = 'HS256';
    public const RS256 = 'RS256';

    /**
     * @param array<string, mixed> $claims
     * @param string               $key   the shared secret (HS256), or the private key in PEM or the path of its file (RS256)
     * @param string|null          $kid   RS256: the name of the public key the instance validates against
     */
    public static function sign(array $claims, #[\SensitiveParameter] string $key, string $algorithm = self::HS256, ?string $kid = null): string
    {
        $header = ['typ' => 'JWT', 'alg' => $algorithm] + (null !== $kid ? ['kid' => $kid] : []);
        $input = self::encode(json_encode($header, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)).'.'.self::encode(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return $input.'.'.self::encode(match ($algorithm) {
            self::HS256 => hash_hmac('sha256', $input, $key, true),
            self::RS256 => self::rsa($input, $key),
            default => throw new InvalidConfigException(\sprintf('Tokens are signed with HS256 or RS256, not "%s".', $algorithm)),
        });
    }

    /**
     * The claims of a token this side signed, or null when the signature
     * does not hold: for tests, and for whoever wants to check what is sent.
     *
     * @param string $key the shared secret (HS256), or the public key in PEM (RS256)
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, #[\SensitiveParameter] string $key): ?array
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            return null;
        }
        $header = json_decode(self::decode($parts[0]), true);
        $signature = self::decode($parts[2]);
        $valid = match ($header['alg'] ?? null) {
            self::HS256 => hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], $key, true), $signature),
            self::RS256 => \function_exists('openssl_verify') && 1 === openssl_verify($parts[0].'.'.$parts[1], $signature, $key, \OPENSSL_ALGO_SHA256),
            default => false,
        };

        return $valid ? json_decode(self::decode($parts[1]), true) : null;
    }

    /** @return array<string, mixed> the header of a token, unchecked */
    public static function header(string $token): array
    {
        return (array) json_decode(self::decode(explode('.', $token)[0]), true);
    }

    private static function rsa(string $input, #[\SensitiveParameter] string $key): string
    {
        if (!\function_exists('openssl_sign')) {
            throw new InvalidConfigException('Signing a token with a private key (RS256) needs PHP\'s openssl extension.');
        }
        $pem = str_contains($key, '-----BEGIN') ? $key : (is_file($key) ? (string) file_get_contents($key) : '');
        $private = '' === $pem ? false : openssl_pkey_get_private($pem);
        if (false === $private || !openssl_sign($input, $signature, $private, \OPENSSL_ALGO_SHA256)) {
            throw new InvalidConfigException('The private key of the jitsi gateway cannot be read: a PEM, or the path of its file.');
        }

        return $signature;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), false);
    }
}
