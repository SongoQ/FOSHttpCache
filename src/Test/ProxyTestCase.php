<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Test;

use FOS\HttpCache\Test\PHPUnit\IsCacheHitConstraint;
use FOS\HttpCache\Test\PHPUnit\IsCacheMissConstraint;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Abstract caching proxy test case
 *
 */
abstract class ProxyTestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * A Guzzle HTTP client.
     *
     * @var Client
     */
    protected $httpClient;

    /**
     * Assert a cache miss
     *
     * @param ResponseInterface $response
     * @param string            $message  Test failure message (optional)
     */
    public function assertMiss(ResponseInterface $response, $message = null)
    {
        self::assertThat($response, self::isCacheMiss(), $message);
    }

    /**
     * Assert a cache hit
     *
     * @param ResponseInterface $response
     * @param string            $message  Test failure message (optional)
     */
    public function assertHit(ResponseInterface $response, $message = null)
    {
        self::assertThat($response, self::isCacheHit(), $message);
    }

    public static function isCacheHit()
    {
        return new IsCacheHitConstraint();
    }

    public static function isCacheMiss()
    {
        return new IsCacheMissConstraint();
    }

    /**
     * Get HTTP response from your application
     *
     * @param string $url
     * @param array  $headers
     * @param array  $options
     *
     * @return ResponseInterface
     */
    public function getResponse($url, array $headers = [], $options = [])
    {
        // cURL connection re-use causes response headers for different requests
        // to be confused.
        $options['curl'][CURLOPT_FORBID_REUSE] = true;
        
        if (isset($options['cookies'])) {
            $cookies = $options['cookies'];
            $options['cookies'] = CookieJar::fromArray($cookies, $this->getHostName());
        }
        $request = new Request('GET', $url, $headers);
        
        return $this->getHttpClient()->send($request, $options);
    }

    /**
     * Get HTTP client for your application
     *
     * @return Client
     */
    public function getHttpClient()
    {
        if (null === $this->httpClient) {
            $this->httpClient = new Client([
                'base_uri' => 'http://' . $this->getHostName() . ':' . $this->getCachingProxyPort(),
                'defaults' => ['curl' => [CURLOPT_FORBID_REUSE => true]]
//                'handler' => new StreamHandler()
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Start the proxy server and reset any cached content
     */
    protected function setUp()
    {
        $this->getProxy()->clear();
    }

    /**
     * Stop the proxy server
     */
    protected function tearDown()
    {
        $this->getProxy()->stop();
    }

    /**
     * Get the hostname where your application can be reached
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function getHostName()
    {
        if (!defined('WEB_SERVER_HOSTNAME')) {
            throw new \Exception('To use this test, you need to define the WEB_SERVER_HOSTNAME constant in your phpunit.xml');
        }

        return WEB_SERVER_HOSTNAME;
    }

    /**
     * Get proxy server
     *
     * @return \FOS\HttpCache\Test\Proxy\ProxyInterface
     */
    abstract protected function getProxy();

    /**
     * Get proxy client
     *
     * @return \FOS\HttpCache\ProxyClient\ProxyClientInterface
     */
    abstract protected function getProxyClient();

    /**
     * Get port that caching proxy listens on
     *
     * @return int
     */
    abstract protected function getCachingProxyPort();
}
