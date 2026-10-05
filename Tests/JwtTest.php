<?php

namespace Omnimeet\Jitsi\Tests;

use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\Jitsi\Jwt;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    public function testHs256IsTheTokenOfRfc7519(): void
    {
        // The example of jwt.io: the same header, claims and secret give the same token.
        $token = Jwt::sign(['sub' => '1234567890', 'name' => 'John Doe', 'iat' => 1516239022], 'your-256-bit-secret');

        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], Jwt::header($token));
        self::assertSame(base64url(hash_hmac('sha256', explode('.', $token)[0].'.'.explode('.', $token)[1], 'your-256-bit-secret', true)), explode('.', $token)[2]);
        self::assertSame(['sub' => '1234567890', 'name' => 'John Doe', 'iat' => 1516239022], Jwt::verify($token, 'your-256-bit-secret'));
        self::assertNull(Jwt::verify($token, 'another-secret'));
        self::assertNull(Jwt::verify(substr($token, 0, -2).'xx', 'your-256-bit-secret'));
        self::assertStringNotContainsString('=', $token, 'base64url, no padding');
    }

    public function testRs256IsSignedWithAPrivateKeyAndNamesItsPublicKey(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);
        $public = openssl_pkey_get_details($pair)['key'];

        $token = Jwt::sign(['room' => 'r', 'context' => ['user' => ['name' => 'Camille Érable']]], $private, Jwt::RS256, 'app/key1');
        self::assertSame(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'app/key1'], Jwt::header($token));
        self::assertSame('Camille Érable', Jwt::verify($token, $public)['context']['user']['name']);

        $file = tempnam(sys_get_temp_dir(), 'omnimeet');
        file_put_contents($file, $private);
        try {
            self::assertSame('r', Jwt::verify(Jwt::sign(['room' => 'r'], $file, Jwt::RS256, 'k'), $public)['room'], 'the key by the path of its file');
        } finally {
            unlink($file);
        }

        $this->expectException(InvalidConfigException::class);
        Jwt::sign(['room' => 'r'], 'not a key', Jwt::RS256, 'k');
    }

    public function testNoOtherAlgorithm(): void
    {
        $this->expectException(InvalidConfigException::class);
        Jwt::sign([], 'secret', 'none');
    }
}

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}
