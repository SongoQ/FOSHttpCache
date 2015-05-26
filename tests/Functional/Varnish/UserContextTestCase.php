<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Tests\Functional\Varnish;

use FOS\HttpCache\Test\VarnishTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;

/**
 * @group webserver
 * @group varnish
 */
abstract class UserContextTestCase extends VarnishTestCase
{
    /**
     * Assert that the context cache status is as expected.
     *
     * @param string $hashCache The cache status of the context request.
     */
    abstract protected function assertContextCache($hashCache);

    /**
     * Sending requests without an Accept: header so none should arrive at the
     * backend for the actual request.
     */
    public function testUserContextHash()
    {
        $response1 = $this->getResponse('/user_context.php', array(), array('cookies' => array('foo')));
        $this->assertEquals('foo', (string) $response1->getBody());
        $this->assertEquals('MISS', $response1->getHeaderLine('X-HashCache'));

        $response2 = $this->getResponse('/user_context.php', array(), array('cookies' => array('bar')));
        $this->assertEquals('bar', (string) $response2->getBody());
        $this->assertEquals('MISS', $response2->getHeaderLine('X-HashCache'));

        $cachedResponse1 = $this->getResponse('/user_context.php', array(), array('cookies' => array('foo')));
        $this->assertEquals('foo', (string) $cachedResponse1->getBody());
        $this->assertContextCache($cachedResponse1->getHeaderLine('X-HashCache'));
        $this->assertHit($cachedResponse1);

        $cachedResponse2 = $this->getResponse('/user_context.php', array(), array('cookies' => array('bar')));
        $this->assertEquals('bar', $cachedResponse2->getBody());
        $this->assertContextCache($cachedResponse2->getHeaderLine('X-HashCache'));
        $this->assertHit($cachedResponse2);

        $headResponse1 = $this->getHttpClient()->head(
            '/user_context.php',
            ['cookies' => CookieJar::fromArray(['foo'], $this->getHostName())]
        );

        $this->assertEquals('foo', $headResponse1->getHeaderLine('X-HashTest'));
        $this->assertContextCache($headResponse1->getHeaderLine('X-HashCache'));
        $this->assertHit($headResponse1);

        $headResponse2 = $this->getHttpClient()->head(
            '/user_context.php',
            ['cookies' => CookieJar::fromArray(['bar'], $this->getHostName())]
        );

        $this->assertEquals('bar', $headResponse2->getHeaderLine('X-HashTest'));
        $this->assertContextCache($headResponse2->getHeaderLine('X-HashCache'));
        $this->assertHit($headResponse2);
    }

    /**
     * Making sure that non-authenticated and authenticated cache are not mixed up.
     */
    public function testUserContextNoAuth()
    {
        $response1 = $this->getResponse('/user_context_anon.php');
        $this->assertEquals('anonymous', $response1->getBody());
        $this->assertEquals('MISS', $response1->getHeaderLine('X-HashCache'));

        $response1 = $this->getResponse('/user_context_anon.php', array(), array('cookies' => array('foo')));
        $this->assertEquals('foo', $response1->getBody());
        $this->assertEquals('MISS', $response1->getHeaderLine('X-HashCache'));

        $cachedResponse1 = $this->getResponse('/user_context_anon.php');
        $this->assertEquals('anonymous', $cachedResponse1->getBody());
        $this->assertHit($cachedResponse1);

        $cachedResponse1 = $this->getResponse('/user_context_anon.php', array(), array('cookies' => array('foo')));
        $this->assertEquals('foo', $cachedResponse1->getBody());
        $this->assertContextCache($cachedResponse1->getHeaderLine('X-HashCache'));
        $this->assertHit($cachedResponse1);
    }

    public function testAcceptHeader()
    {
        $response1 = $this->getResponse(
            '/user_context.php?accept=text/plain',
            array('Accept' => 'text/plain'),
            array('cookies' => array('foo'))
        );
        $this->assertEquals('foo', $response1->getBody());
    }

    public function testUserContextUnauthorized()
    {
        try {
            $this->getResponse('/user_context.php', array(), array('cookies' => array('miam')));

            $this->fail('Request should have failed with a 403 response');
        } catch (ClientException $e) {
            $this->assertEquals('MISS', $e->getResponse()->getHeaderLine('X-HashCache'));
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
        }

        try {
            $this->getResponse('/user_context.php', array(), array('cookies' => array('miam')));

            $this->fail('Request should have failed with a 403 response');
        } catch (ClientException $e) {
            $this->assertContextCache($e->getResponse()->getHeaderLine('X-HashCache'));
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
        }
    }
}
