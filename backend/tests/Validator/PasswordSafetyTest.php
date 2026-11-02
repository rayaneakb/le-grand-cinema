<?php

namespace App\Tests\Validator;

use App\Validator\PasswordSafety;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PasswordSafetyTest extends TestCase
{
    public function testCommonPasswordIsRefusedWithoutRequest(): void
    {
        $client = new MockHttpClient(static function () { self::fail('Aucun appel attendu.'); });
        self::assertStringContainsString('courant', (new PasswordSafety($client))->error('motdepasse123'));
    }

    public function testOnlyHashPrefixIsSentAndLeakedPasswordIsRefused(): void
    {
        $password = 'une phrase de test unique';
        $hash = strtoupper(sha1($password));
        $client = new MockHttpClient(static function ($method, $url, $options) use ($hash, $password) {
            self::assertSame('GET', $method);
            self::assertSame('https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), $url);
            self::assertStringNotContainsString($password, json_encode([$url, $options]));
            return new MockResponse(substr($hash, 5) . ':12');
        });
        self::assertStringContainsString('fuite', (new PasswordSafety($client))->error($password));
    }

    public function testUnlistedPasswordAndPaddingAreAccepted(): void
    {
        $password = 'une autre phrase de test';
        $body = substr(strtoupper(sha1($password)), 5) . ':0';
        self::assertNull((new PasswordSafety(new MockHttpClient(new MockResponse($body))))->error($password));
    }

    public function testOutageDoesNotSilentlyAcceptThePassword(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        self::assertStringContainsString('indisponible', (new PasswordSafety($client))->error('une phrase de test'));
    }
}
