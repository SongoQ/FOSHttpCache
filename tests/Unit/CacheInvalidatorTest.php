<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Tests\Unit;

use FOS\HttpCache\CacheInvalidator;
use FOS\HttpCache\EventListener\LogSubscriber;
use FOS\HttpCache\Exception\ExceptionCollection;
use FOS\HttpCache\Exception\ProxyResponseException;
use FOS\HttpCache\Exception\ProxyUnreachableException;
use FOS\HttpCache\Exception\UnsupportedProxyOperationException;
use FOS\HttpCache\ProxyClient\Varnish;
use \Mockery;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CacheInvalidatorTest extends \PHPUnit_Framework_TestCase
{
    public function testSupportsTrue()
    {
        $proxyClient = new Varnish(array('localhost'));

        $cacheInvalidator = new CacheInvalidator($proxyClient);

        $this->assertTrue($cacheInvalidator->supports(CacheInvalidator::PATH));
        $this->assertTrue($cacheInvalidator->supports(CacheInvalidator::REFRESH));
        $this->assertTrue($cacheInvalidator->supports(CacheInvalidator::INVALIDATE));
    }

    public function testSupportsFalse()
    {
        $proxyClient = \Mockery::mock('\FOS\HttpCache\ProxyClient\ProxyClientInterface');

        $cacheInvalidator = new CacheInvalidator($proxyClient);

        $this->assertFalse($cacheInvalidator->supports(CacheInvalidator::PATH));
        $this->assertFalse($cacheInvalidator->supports(CacheInvalidator::REFRESH));
        $this->assertFalse($cacheInvalidator->supports(CacheInvalidator::INVALIDATE));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSupportsInvalid()
    {
        $proxyClient = \Mockery::mock('\FOS\HttpCache\ProxyClient\ProxyClientInterface');

        $cacheInvalidator = new CacheInvalidator($proxyClient);

        $cacheInvalidator->supports('garbage');
    }

    public function testInvalidatePath()
    {
        $purge = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\PurgeInterface')
            ->shouldReceive('purge')->once()->with('/my/route', array())
            ->shouldReceive('purge')->once()->with('/my/route', array('X-Test-Header' => 'xyz'))
            ->shouldReceive('flush')->once()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($purge);

        $cacheInvalidator
            ->invalidatePath('/my/route')
            ->invalidatePath('/my/route', array('X-Test-Header' => 'xyz'))
            ->flush()
        ;
    }

    public function testRefreshPath()
    {
        $headers = array('X' => 'Y');
        $refresh = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\RefreshInterface')
            ->shouldReceive('refresh')->once()->with('/my/route', $headers)
            ->shouldReceive('flush')->never()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($refresh);

        $cacheInvalidator
            ->refreshPath('/my/route', $headers)
        ;
    }

    public function testInvalidate()
    {
        $headers = array(
            'X-Header' => '^value.*$',
            'Other-Header' => '^a|b|c$',
        );

        $ban = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\BanInterface')
            ->shouldReceive('ban')
            ->with($headers)
            ->once()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($ban);
        $cacheInvalidator->invalidate($headers);
    }

    public function testInvalidateRegex()
    {
        $ban = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\BanInterface')
            ->shouldReceive('banPath')
            ->with('/a', 'b', array('example.com'))
            ->once()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($ban);
        $cacheInvalidator->invalidateRegex('/a', 'b', array('example.com'));
    }

    public function testInvalidateTags()
    {
        $ban = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\BanInterface')
            ->shouldReceive('ban')
            ->with(array('X-Cache-Tags' => '(post\-1|posts)(,.+)?$'))
            ->once()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($ban);
        $cacheInvalidator->invalidateTags(array('post-1', 'posts'));
    }

    public function testInvalidateTagsCustomHeader()
    {
        $ban = \Mockery::mock('\FOS\HttpCache\ProxyClient\Invalidation\BanInterface')
            ->shouldReceive('ban')
            ->with(array('Custom-Tags' => '(post\-1)(,.+)?$'))
            ->once()
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($ban);
        $cacheInvalidator->setTagsHeader('Custom-Tags');
        $cacheInvalidator->invalidateTags(array('post-1'));
    }

    public function testMethodException()
    {
        $proxyClient = \Mockery::mock('\FOS\HttpCache\ProxyClient\ProxyClientInterface');
        $cacheInvalidator = new CacheInvalidator($proxyClient);
        try {
            $cacheInvalidator->invalidatePath('/');
            $this->fail('Expected exception');
        } catch (UnsupportedProxyOperationException $e) {
            // success
        }
        try {
            $cacheInvalidator->refreshPath('/');
            $this->fail('Expected exception');
        } catch (UnsupportedProxyOperationException $e) {
            // success
        }
        try {
            $cacheInvalidator->invalidate(array());
            $this->fail('Expected exception');
        } catch (UnsupportedProxyOperationException $e) {
            // success
        }
        try {
            $cacheInvalidator->invalidateRegex('/');
            $this->fail('Expected exception');
        } catch (UnsupportedProxyOperationException $e) {
            // success
        }
        try {
            $cacheInvalidator->invalidateTags(array());
            $this->fail('Expected exception');
        } catch (UnsupportedProxyOperationException $e) {
            // success
        }
    }

    /**
     * @expectedException \FOS\HttpCache\Exception\ExceptionCollection
     */
    public function testProxyClientExceptionsAreLogged()
    {
        $failedRequest = \Mockery::mock('\Psr\Http\Message\RequestInterface')
            ->shouldReceive('getHeaderLine')->with('Host')->andReturn('127.0.0.1')
            ->getMock();
        $adapterException = \Mockery::mock(
            '\Http\Adapter\Common\Exception\HttpAdapterException',
            ['Couldn\'t connect to host']
        )
            ->shouldReceive('getRequest')->andReturn($failedRequest)
            ->getMock();
        ;
        $unreachableException = ProxyUnreachableException::proxyUnreachable($adapterException);
        
        $response = \Mockery::mock('\Psr\Http\Message\ResponseInterface')
            ->shouldReceive('getStatusCode')->andReturn(403)
            ->shouldReceive('getReasonPhrase')->andReturn('Forbidden')
            ->getMock();
        $adapterException = \Mockery::mock('\Http\Adapter\Common\Exception\HttpAdapterException')
            ->shouldReceive('getMessage')->andReturn('Forbidden')
            ->shouldReceive('getRequest')->andReturn($failedRequest)
            ->shouldReceive('getResponse')->andReturn($response)
            ->getMock()
        ;
        $responseException = ProxyResponseException::proxyResponse($adapterException);

        $exceptions = new ExceptionCollection();
        $exceptions->add($unreachableException)->add($responseException);

        $proxyClient = \Mockery::mock('\FOS\HttpCache\ProxyClient\ProxyClientInterface')
            ->shouldReceive('flush')->once()->andThrow($exceptions)
            ->getMock();

        $cacheInvalidator = new CacheInvalidator($proxyClient);

        $logger = \Mockery::mock('\Psr\Log\LoggerInterface')
            ->shouldReceive('log')->once()
            ->with(
                'critical',
                'Request to caching proxy at 127.0.0.1 failed with message "Couldn\'t connect to host"',
                ['exception' => $unreachableException]
            )
            ->shouldReceive('log')->once()
            ->with(
                'critical',
                '403 error response "Forbidden" from caching proxy at 127.0.0.1',
                ['exception' => $responseException]
            )
            ->getMock();

        $cacheInvalidator->getEventDispatcher()->addSubscriber(new LogSubscriber($logger));

        $cacheInvalidator
            ->flush()
        ;
    }

    public function testEventDispatcher()
    {
        $proxyClient = new Varnish(array('localhost'));

        $cacheInvalidator = new CacheInvalidator($proxyClient);
        $eventDispatcher = new EventDispatcher();
        $cacheInvalidator->setEventDispatcher($eventDispatcher);
        $this->assertSame($eventDispatcher, $cacheInvalidator->getEventDispatcher());
    }
}
