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
use FOS\HttpCache\Test\HttpClient\HttpAdapterMock;
use Guzzle\Http\Exception\MultiTransferException;
use Guzzle\Http\Message\Request;
use Ivory\HttpAdapter\HttpAdapterException;
use Ivory\HttpAdapter\Message\InternalRequest;
use Ivory\HttpAdapter\MultiHttpAdapterException;
use \Mockery;
use Psr\Http\Message\OutgoingRequestInterface;

class VarnishTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var HttpAdapterMock
     */
    protected $client;

    public function testBanEverything()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);
        $varnish->ban(array())->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('.*', $requests[0]->getHeader('X-Host'));
        $this->assertEquals('.*', $requests[0]->getHeader('X-Url'));
        $this->assertEquals('.*', $requests[0]->getHeader('X-Content-Type'));
        $this->assertEquals('fos.lo', $requests[0]->getHeader('Host'));
    }

    public function testBanEverythingNoBaseUrl()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), null, $this->client);
        $varnish->ban(array())->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('.*', $requests[0]->getHeader('X-Host'));
        $this->assertEquals('.*', $requests[0]->getHeader('X-Url'));
        $this->assertEquals('.*', $requests[0]->getHeader('X-Content-Type'));
        
        // Ensure host header matches the Varnish server one.
        $this->assertEquals('http://127.0.0.1:123/', $requests[0]->getUrl());
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

        $this->assertEquals('.*', $requests[0]->getHeader('Test'));
        $this->assertEquals('B', $requests[0]->getHeader('A'));
        $this->assertEquals('fos.lo', $requests[0]->getHeader('Host'));
    }

    public function testBanPath()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);

        $hosts = array('fos.lo', 'fos2.lo');
        $varnish->banPath('/articles/.*', 'text/html', $hosts)->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('BAN', $requests[0]->getMethod());

        $this->assertEquals('^(fos.lo|fos2.lo)$', $requests[0]->getHeader('X-Host'));
        $this->assertEquals('/articles/.*', $requests[0]->getHeader('X-Url'));
        $this->assertEquals('text/html', $requests[0]->getHeader('X-Content-Type'));
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
        $ips = array(
            '127.0.0.1:8080',
            '123.123.123.2',
        );

        $varnish = new Varnish($ips, 'my_hostname.dev', $this->client);

        $count = $varnish->purge('/url/one')
            ->purge('/url/two', array('X-Foo' => 'bar'))
            ->flush()
        ;
        
        $this->assertEquals(4, $count);
        
        $requests = $this->getRequests();
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertEquals('PURGE', $request->getMethod());
            $this->assertEquals('my_hostname.dev', $request->getHeader('Host'));
        }
    
        $this->assertEquals('http://127.0.0.1:8080/url/one', $requests[0]->getUrl());
        $this->assertEquals('http://123.123.123.2/url/one', $requests[1]->getUrl());
        $this->assertEquals('http://127.0.0.1:8080/url/two', $requests[2]->getUrl());
        $this->assertEquals('bar', $requests[2]->getHeader('X-Foo'));
        $this->assertEquals('http://123.123.123.2/url/two', $requests[3]->getUrl());
        $this->assertEquals('bar', $requests[3]->getHeader('X-Foo'));
    }

    public function testRefresh()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), 'fos.lo', $this->client);
        $varnish->refresh('/fresh')->flush();

        $requests = $this->getRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('http://127.0.0.1:123/fresh', $requests[0]->getUrl());
    }

    public function exceptionProvider()
    {
        $timeout = HttpAdapterException::timeoutExceeded('http://bla.com/', 60, 'guzzle');
        $timeout->setRequest(new InternalRequest('http://bla.com/'));

        return array(
//            array($curlException, '\FOS\HttpCache\Exception\ProxyUnreachableException'),
            array(
                $timeout,
                '\FOS\HttpCache\Exception\ProxyUnreachableException',
                'bla.com'
            ),
        );
    }

    /**
     * @dataProvider exceptionProvider
     *
     * @param \Exception $exception The exception that curl should throw.
     * @param string     $type      The returned exception class to be expected.
     */
    public function testExceptions(\Exception $exception, $type, $message = null)
    {
        $this->client->setException(
            new MultiHttpAdapterException(array($exception))
        );
        $varnish = new Varnish(array('127.0.0.1:123'), 'my_hostname.dev', $this->client);
        
        $varnish->purge('/');

        try {
            $varnish->flush();
            $this->fail('Should have aborted with an exception');
        } catch (ExceptionCollection $exceptions) {
            $this->assertCount(1, $exceptions);
            $this->assertInstanceOf($type, $exceptions->getFirst());
            if ($message) {
                $this->assertContains(
                    $exceptions->getFirst()->getMessage(),
                    $message
                );
            }
        }
        
        $this->client->clear();

        // Queue must now be empty, so exception above must not be thrown again.
        $varnish->purge('/path')->flush();

        
        return;
        // the guzzle mock plugin does not allow arbitrary exceptions
        // mockery does not provide all methods of the interface
        $collection = new MultiTransferException();
        $collection->setExceptions(array($exception));
        $client = $this->getMock('\Guzzle\Http\ClientInterface');
        $client->expects($this->any())
            ->method('createRequest')
            ->willReturn(new Request('BAN', '/'))
        ;
        $client->expects($this->once())
            ->method('send')
            ->willThrowException($collection)
        ;

        $varnish = new Varnish(array('127.0.0.1:123'), 'my_hostname.dev', $client);

        $varnish->ban(array());
        try {
            $varnish->flush();
            $this->fail('Should have aborted with an exception');
        } catch (ExceptionCollection $exceptions) {
            $this->assertCount(1, $exceptions);
            $this->assertInstanceOf($type, $exceptions->getFirst());
        }
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\MissingHostException
     * @expectedExceptionMessage cannot be invalidated without a host
     */
    public function testMissingHostExceptionIsThrown()
    {
        $varnish = new Varnish(array('127.0.0.1:123'), null, $this->client);
        $varnish->purge('/path/without/hostname');
    }

    public function testSetBasePathWithHost()
    {
        $varnish = new Varnish(array('127.0.0.1'), 'fos.lo', $this->client);
        $varnish->purge('/path')->flush();
        $requests = $this->getRequests();
        $this->assertEquals('fos.lo', $requests[0]->getHeader('Host'));
    }

    public function testSetBasePathWithPath()
    {
        $varnish = new Varnish(array('127.0.0.1'), 'http://fos.lo/my/path', $this->client);
        $varnish->purge('append')->flush();
        $requests = $this->getRequests();
        $this->assertEquals('fos.lo', $requests[0]->getHeader('Host'));
        $this->assertEquals('http://127.0.0.1/my/path/append', $requests[0]->getUrl());
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
        $this->assertEquals('http://127.0.0.1/some/path', $requests[0]->getUrl());
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
        $client = \Mockery::mock('\Guzzle\Http\Client[send]', array('', null))
            ->shouldReceive('send')
            ->once()
            ->with(
                \Mockery::on(
                    function ($requests) use ($self) {
                        /** @type Request[] $requests */
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
                ->purge('/c')
                ->purge('/b')
                ->flush()
        );
    }

    public function testEliminateDuplicates()
    {
        $self = $this;
        $client = \Mockery::mock('\Guzzle\Http\Client[send]', array('', null))
            ->shouldReceive('send')
            ->once()
            ->with(
                \Mockery::on(
                    function ($requests) use ($self) {
                        /** @type Request[] $requests */
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
        $this->client = new HttpAdapterMock();
    }

    /**
     * @return array|OutgoingRequestInterface[]
     */
    protected function getRequests()
    {
        return $this->client->getRequests();
    }
}
