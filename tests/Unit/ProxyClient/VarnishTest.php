<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Tests\Unit\ProxyClient;

use FOS\HttpCache\Exception\ExceptionCollection;
use FOS\HttpCache\ProxyClient\Varnish;
use FOS\HttpCache\Test\HttpClient\MockHttpAdapter;
use Http\Adapter\Common\Exception\HttpAdapterException;
use \Mockery;
use Psr\Http\Message\RequestInterface;

class VarnishTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var MockHttpAdapter
     */
    protected $client;

    public function testBanEverything()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);
        $varnish->ban(array())->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Host'));
        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Url'));
        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Content-Type'));
        $this->assertEquals('fos.lo', $requests[0]->getHeaderLine('Host'));
    }

    public function testBanEverythingNoBaseUrl()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), null, $this->client);
        $varnish->ban(array())->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Host'));
        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Url'));
        $this->assertEquals('.*', $requests[0]->getHeaderLine('X-Content-Type'));
        
        // Ensure host header matches the Varnish server one.
        $this->assertEquals('http://127.0.0.1:123/', $requests[0]->getUri());
    }

    public function testBanHeaders()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);
        $varnish->setDefaultBanHeaders(
            array('A' => 'B')
        );
        $varnish->setDefaultBanHeader('Test', '.*');
        $varnish->ban(array())->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('.*', $requests[0]->getHeaderLine('Test'));
        $this->assertEquals('B', $requests[0]->getHeaderLine('A'));
        $this->assertEquals('fos.lo', $requests[0]->getHeaderLine('Host'));
    }

    public function testBanPath()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);

        $hosts = array('fos.lo', 'fos2.lo');
        $varnish->banPath('/articles/.*', 'text/html', $hosts)->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('^(fos.lo|fos2.lo)$', $requests[0]->getHeaderLine('X-Host'));
        $this->assertEquals('/articles/.*', $requests[0]->getHeaderLine('X-Url'));
        $this->assertEquals('text/html', $requests[0]->getHeaderLine('X-Content-Type'));
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidArgumentException
     */
    public function testBanPathEmptyHost()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);

        $hosts = array();
        $varnish->banPath('/articles/.*', 'text/html', $hosts);
    }

    public function testPurge()
    {
        $ips = ['127.0.0.1:8080', '123.123.123.2'];
        $varnish = new Varnish($ips, 'my_hostname.dev', $this->client);

        $count = $varnish->purge('/url/one')
            ->purge('/url/two', array('X-Foo' => 'bar'))
            ->flush()
        ;
        $this->assertEquals(2, $count);
        
        $requests = $this->getRequests();
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertEquals('PURGE', $request->getMethod());
            $this->assertEquals('my_hostname.dev', $request->getHeaderLine('Host'));
        }
    
        $this->assertEquals('http://127.0.0.1:8080/url/one', $requests[0]->getUri());
        $this->assertEquals('http://123.123.123.2/url/one', $requests[1]->getUri());
        $this->assertEquals('http://127.0.0.1:8080/url/two', $requests[2]->getUri());
        $this->assertEquals('bar', $requests[2]->getHeaderLine('X-Foo'));
        $this->assertEquals('http://123.123.123.2/url/two', $requests[3]->getUri());
        $this->assertEquals('bar', $requests[3]->getHeaderLine('X-Foo'));
    }

    public function testRefresh()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);
        $varnish->refresh('/fresh')->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('http://127.0.0.1:123/fresh', $requests[0]->getUri());
    }

    public function exceptionProvider()
    {
        // Timeout exception (without response)
        $request = \Mockery::mock('\Psr\Http\Message\RequestInterface')
            ->shouldReceive('getHeaderLine')
            ->with('Host')
            ->andReturn('bla.com')
            ->getMock()
        ;
        $unreachableException = new HttpAdapterException();
        $unreachableException->setRequest($request);
        
        // Client exception (with response)
        $response = \Mockery::mock('\Psr\Http\Message\ResponseInterface')
            ->shouldReceive('getStatusCode')->andReturn(500)
            ->shouldReceive('getReasonPhrase')->andReturn('Uh-oh!')
            ->getMock()
        ;
        $responseException = new HttpAdapterException();
        $responseException->setRequest($request);
        $responseException->setResponse($response);
        
        return [
            [
                $unreachableException,
                '\FOS\HttpCache\Exception\ProxyUnreachableException',
                'bla.com'
            ],
            [
                $responseException,
                '\FOS\HttpCache\Exception\ProxyResponseException',
                'bla.com'
            ],
        ];
    }

    /**
     * @dataProvider exceptionProvider
     *
     * @param \Exception $exception The exception that curl should throw.
     * @param string     $type      The returned exception class to be expected.
     * @param string     $message   Optional exception message to match against.
     */
    public function testExceptions(\Exception $exception, $type, $message = null)
    {
        $this->client->setException($exception);
        $varnish = new Varnish(['127.0.0.1:123'], 'my_hostname.dev', $this->client);
        
        $varnish->purge('/');

        try {
            $varnish->flush();
            $this->fail('Should have aborted with an exception');
        } catch (ExceptionCollection $exceptions) {
            $this->assertCount(1, $exceptions);
            $this->assertInstanceOf($type, $exceptions->getFirst());
            if ($message) {
                $this->assertContains(
                    $message,
                    $exceptions->getFirst()->getMessage()
                );
            }
        }
        
        $this->client->clear();

        // Queue must now be empty, so exception above must not be thrown again.
        $varnish->purge('/path')->flush();
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\MissingHostException
     * @expectedExceptionMessage cannot be invalidated without a host
     */
    public function testMissingHostExceptionIsThrown()
    {
        $varnish = new Varnish(['127.0.0.1:123'], null, $this->client);
        $varnish->purge('/path/without/hostname');
    }

    public function testSetBasePathWithHost()
    {
        $varnish = new Varnish(array('127.0.0.1'), 'fos.lo', $this->client);
        $varnish->purge('/path')->flush();
        $requests = $this->getRequests();
        $this->assertEquals('fos.lo', $requests[0]->getHeaderLine('Host'));
    }

    public function testSetBasePathWithPath()
    {
        $varnish = new Varnish(array('127.0.0.1'), 'http://fos.lo/my/path', $this->client);
        $varnish->purge('append')->flush();
        $requests = $this->getRequests();
        $this->assertEquals('fos.lo', $requests[0]->getHeaderLine('Host'));
        $this->assertEquals('http://127.0.0.1/my/path/append', $requests[0]->getUri());
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidUrlException
     */
    public function testSetBasePathThrowsInvalidUrlSchemeException()
    {
        new Varnish(array('127.0.0.1'), 'https://fos.lo/my/path');
    }

    public function testSetServersDefaultSchemeIsAdded()
    {
        $varnish = new Varnish(array('127.0.0.1'), 'fos.lo', $this->client);
        $varnish->purge('/some/path')->flush();
        $requests = $this->getRequests();
        $this->assertEquals('http://127.0.0.1/some/path', $requests[0]->getUri());
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidUrlException
     * @expectedExceptionMessage URL "http:///this is no url" is invalid.
     */
    public function testSetServersThrowsInvalidUrlException()
    {
        new Varnish(array('http:///this is no url'));
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidUrlException
     * @expectedExceptionMessage URL "this ://is no url" is invalid.
     */
    public function testSetServersThrowsWeirdInvalidUrlException()
    {
        new Varnish(array('this ://is no url'));
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidUrlException
     * @expectedExceptionMessage Host "https://127.0.0.1" with scheme "https" is invalid
     */
    public function testSetServersThrowsInvalidUrlSchemeException()
    {
        new Varnish(array('https://127.0.0.1'));
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\InvalidUrlException
     * @expectedExceptionMessage Server "http://127.0.0.1:80/some/weird/path" is invalid. Only scheme, host, port URL parts are allowed
     */
    public function testSetServersThrowsInvalidServerException()
    {
        new Varnish(array('http://127.0.0.1:80/some/weird/path'));
    }

    public function testFlushEmpty()
    {
        $varnish = new Varnish(array('127.0.0.1', '127.0.0.2'), 'fos.lo', $this->client);
        $this->assertEquals(0, $varnish->flush());
        
        $this->assertCount(0, $this->client->getRequests());
    }

    public function testFlushCountSuccess()
    {
        $self = $this;
        $httpAdapter = \Mockery::mock('\Http\Adapter\HttpAdapter')
            ->shouldReceive('sendRequests')
            ->once()
            ->with(
                \Mockery::on(
                    function ($requests) use ($self) {
                        /** @type RequestInterface[] $requests */
                        $self->assertCount(4, $requests);
                        foreach ($requests as $request) {
                            $self->assertEquals('PURGE', $request->getMethod());
                        }

                        return true;
                    }
                )
            )
            ->getMock();

        $varnish = new Varnish(['127.0.0.1', '127.0.0.2'], 'fos.lo', $httpAdapter);

        $this->assertEquals(
            2,
            $varnish
                ->purge('/c')
                ->purge('/b')
                ->flush()
        );
    }

    public function testEliminateDuplicates()
    {
        $self = $this;
        $client = \Mockery::mock('\Http\Adapter\HttpAdapter')
            ->shouldReceive('sendRequests')
            ->once()
            ->with(
                \Mockery::on(
                    function ($requests) use ($self) {
                        /** @type RequestInterface[] $requests */
                        $self->assertCount(4, $requests);
                        foreach ($requests as $request) {
                            $self->assertEquals('PURGE', $request->getMethod());
                        }

                        return true;
                    }
                )
            )
            ->getMock();

        $varnish = new Varnish(array('127.0.0.1', '127.0.0.2'), 'fos.lo', $client);

        $this->assertEquals(
            2,
            $varnish
                ->purge('/c', array('a' => 'b', 'c' => 'd'))
                ->purge('/c', array('c' => 'd', 'a' => 'b')) // same request (header order is not significant)
                ->purge('/c') // different request as headers different
                ->purge('/c')
                ->flush()
        );
    }

    protected function setUp()
    {
        $this->client = new MockHttpAdapter();
    }

    /**
     * @return array|RequestInterface[]
     */
    protected function getRequests()
    {
        return $this->client->getRequests();
    }
}
