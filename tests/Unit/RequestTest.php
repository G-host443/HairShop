<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testInvalidUtf8IsScrubbedRecursively(): void
    {
        $latin1 = "M\xE8ches"; // « Mèches » encodé en Latin-1, invalide en UTF-8

        $clean = Request::utf8(['comment' => $latin1, 'options' => ['a' => "x\xFFy"], 'ok' => 'Mèches', 'n' => 3]);

        self::assertTrue(mb_check_encoding($clean['comment'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($clean['options']['a'], 'UTF-8'));
        self::assertSame('Mèches', $clean['ok'], 'Le texte UTF-8 valide est intact.');
        self::assertSame(3, $clean['n']);
    }

    public function testForwardedHeadersAreIgnoredWithoutTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https'];

        self::assertSame($server, Request::applyTrustedProxies($server, []), 'Aucun proxy déclaré : en-têtes ignorés.');
        self::assertSame($server, Request::applyTrustedProxies($server, ['10.0.0.0/8']), 'Connexion hors des proxys déclarés : en-têtes ignorés.');
    }

    public function testTrustedProxyProvidesClientIpAndHttps(): void
    {
        $server = Request::applyTrustedProxies(
            ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['10.0.0.0/8'],
        );
        self::assertSame('198.51.100.20', $server['REMOTE_ADDR']);
        self::assertSame('on', $server['HTTPS']);

        $request = new Request('GET', '/', [], [], [], [], [], $server);
        self::assertTrue($request->isSecure());
        self::assertSame('198.51.100.20', $request->ip());
    }

    public function testSpoofedForwardedChainCannotHideTheRealAddress(): void
    {
        // Le client envoie lui-même « X-Forwarded-For: 1.1.1.1 » ; le proxy ajoute la vraie adresse à droite.
        $server = Request::applyTrustedProxies(
            ['REMOTE_ADDR' => '172.16.0.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.99, 172.16.0.9'],
            ['172.16.0.0/12'],
        );
        self::assertSame('198.51.100.99', $server['REMOTE_ADDR']);
    }

    public function testIpMatching(): void
    {
        self::assertTrue(Request::ipMatches('192.168.1.40', ['192.168.1.0/24']));
        self::assertFalse(Request::ipMatches('192.168.2.40', ['192.168.1.0/24']));
        self::assertTrue(Request::ipMatches('203.0.113.7', ['203.0.113.7']));
        self::assertTrue(Request::ipMatches('2001:db8::1', ['2001:db8::/32']));
        self::assertFalse(Request::ipMatches('2001:db9::1', ['2001:db8::/32']));
        self::assertFalse(Request::ipMatches('pas-une-ip', ['0.0.0.0/0']));
    }
}
