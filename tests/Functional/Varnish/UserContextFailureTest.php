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
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Test edge conditions and attacks.
 *
 * @group webserver
 * @group varnish
 */
class UserContextFailureTest extends VarnishTestCase
{
    private $mode;

    public function setUp()
    {
        $this->mode = 'testHashRequestFailure' == $this->getName() ? 'failure' : 'cache';

        parent::setUp();
    }

    /**
     * The user hash must not be exposed to the client under any circumstances.
     */
    public function testUserContextNoExposeHash()
    {
        try {
            $response = $this->getResponse(
                '/user_context_hash_nocache.php',
                array('accept' => 'application/vnd.fos.user-context-hash'),
                array('cookies' => array('miam'))
            );

            $this->fail("Request should have failed with a 400 response.\n\n" . $response->getRawHeaders() . "\n" . $response->getBody(true));
        } catch (ClientException $e) {
            $this->assertEquals(400, $e->getResponse()->getStatusCode());
            $this->assertFalse($e->getResponse()->hasHeader('X-User-Context-Hash'));
        }
    }

    /**
     * A hash sent by the client must not be used by varnish.
     */
    public function testUserContextNoForgedHash()
    {
        try {
            $response = $this->getResponse(
                '/user_context_hash_nocache.php',
                array('X-User-Context-Hash' => 'miam'),
                array('cookies' => array('miam'))
            );

            $this->fail("Request should have failed with a 400 response.\n\n" . $response->getRawHeaders() . "\n" . $response->getBody(true));
        } catch (ClientException $e) {
            $this->assertEquals(400, $e->getResponse()->getStatusCode());
        }
    }

    /**
     * A request on POST should not use the context.
     */
    public function testUserContextNotUsed()
    {
        //First request in get
        $this->getResponse('/user_context.php', array(), array('cookies' => array('foo')));

        //Second request in head or post
        $postResponse = $this->getHttpClient()->post(
            '/user_context.php',
            ['cookies' => CookieJar::fromArray(['foo'], $this->getHostName())]
        );

        $this->assertEquals('POST', $postResponse->getBody(true));
        $this->assertEquals('MISS', $postResponse->getHeaderLine('X-HashCache'));
        $this->assertMiss($postResponse);
    }

    public function testHashRequestFailure()
    {
        try {
            $response = $this->getResponse('/user_context.php', array(), array('cookies' => array('foo')));

            $this->fail("Request should have failed with a 500 response.\n\n" . $response->getRawHeaders() . "\n" . $response->getBody(true));
        } catch (ServerException $e) {
            $this->assertEquals(503, $e->getResponse()->getStatusCode());
        }
    }

    protected function getConfigFile()
    {
        switch ($this->getVarnishVersion()) {
            case '4.0':
                return sprintf('./tests/Functional/Fixtures/varnish-4/user_context_%s.vcl', $this->mode);
            default:
                return sprintf('./tests/Functional/Fixtures/varnish-3/user_context_%s.vcl', $this->mode);
        }
    }
}
