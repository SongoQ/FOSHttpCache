<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\ProxyClient;

use FOS\HttpCache\Exception\ExceptionCollection;
use FOS\HttpCache\Exception\ProxyResponseException;
use FOS\HttpCache\Exception\ProxyUnreachableException;
use FOS\HttpCache\ProxyClient\Request\InvalidationRequest;
use FOS\HttpCache\ProxyClient\Request\RequestQueue;
use Http\Adapter\Exception\MultiHttpAdapterException;
use Http\Adapter\Guzzle6HttpAdapter;
use Http\Adapter\PsrHttpAdapter;

/**
 * Abstract caching proxy client
 *
 * @author David de Boer <david@driebit.nl>
 */
abstract class AbstractProxyClient implements ProxyClientInterface
{
    /**
     * HTTP client
     *
     * @var PsrHttpAdapter
     */
    private $httpAdapter;

    /**
     * Request queue
     *
     * @var RequestQueue
     */
    protected $queue;

    /**
     * Constructor
     *
     * @param array           $servers Caching proxy server hostnames or IP addresses,
     *                                 including port if not port 80.
     *                                 E.g. array('127.0.0.1:6081')
     * @param string          $baseUrl Default application hostname, optionally
     *                                 including base URL, for purge and refresh
     *                                 requests (optional). This is required if
     *                                 you purge and refresh paths instead of
     *                                 absolute URLs.
     * @param PsrHttpAdapter $httpAdapter If no HTTP adapter is supplied, a
     *                                 default one will be created.
     */
    public function __construct(
        array $servers,
        $baseUrl = null,
        PsrHttpAdapter $httpAdapter = null
    ) {
        $this->httpAdapter = $httpAdapter ?: new Guzzle6HttpAdapter();
        $this->initQueue($servers, $baseUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        if (0 === $this->queue->count()) {
            return 0;
        }
        
        $queue = clone $this->queue;
        $this->queue->clear();

        try {
            $responses = $this->httpAdapter->sendRequests($queue->all());
        } catch (MultiHttpAdapterException $e) {
            $collection = new ExceptionCollection();
            foreach ($e->getExceptions() as $exception) {
                // A workaround for php-http currently lacking differentiation
                // between client, server and networking errors.
                if (!$exception->getResponse()) {
                    // Assume networking error if no response was returned.
                    $collection->add(
                        ProxyUnreachableException::proxyUnreachable($exception)
                    );
                } else {
                    $collection->add(
                        ProxyResponseException::proxyResponse($exception)
                    );
                }
            }

            throw $collection;
        }
        
        return count($queue);
    }
    
    protected function queueRequest($method, $url, array $headers = array())
    {
        $this->queue->add(new InvalidationRequest($method, $url, $headers));
    }
    
    protected function initQueue(array $servers, $baseUrl)
    {
        $this->queue = new RequestQueue($servers, $baseUrl);
    }

    /**
     * Get schemes allowed by caching proxy
     *
     * @return string[] Array of schemes allowed by caching proxy, e.g. 'http'
     *                  or 'https'
     */
    abstract protected function getAllowedSchemes();
}
