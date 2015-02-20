<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\HttpClient;

use FOS\HttpCache\Exception\ExceptionCollection;
use FOS\HttpCache\Exception\ProxyResponseException;
use FOS\HttpCache\Exception\ProxyUnreachableException;
use FOS\HttpCache\ProxyClient\Request\RequestQueue;
use Guzzle\Common\Exception\ExceptionCollection as GuzzleExceptionCollection;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Http\Exception\RequestException;

class Guzzle3Adapter implements HttpClientInterface
{
    /**
     * Constructor
     *
     * @param ClientInterface $client Guzzle3 client (optional)
     */
    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequests(RequestQueue $requests)
    {
        $guzzleRequests = array();
        foreach ($requests as $request) {
            $guzzleRequests[] = $this->client->createRequest(
                $request->getMethod(),
                $request->getUrl(),
                $request->getHeaders()
            );
        }
        
        try {
            $this->client->send($guzzleRequests);
        } catch (GuzzleExceptionCollection $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Handle request exception
     *
     * @param GuzzleExceptionCollection $exceptions
     *
     * @throws ExceptionCollection
     */
    private function handleException(GuzzleExceptionCollection $exceptions)
    {
        $collection = new ExceptionCollection();

        foreach ($exceptions as $exception) {
            if ($exception instanceof CurlException) {
                // Caching proxy unreachable
                $e = ProxyUnreachableException::proxyUnreachable(
                    $exception->getRequest()->getHost(),
                    $exception->getMessage(),
                    $exception
                );
            } elseif ($exception instanceof RequestException) {
                // Other error
                $e = ProxyResponseException::proxyResponse(
                    $exception->getRequest()->getHost(),
                    $exception->getCode(),
                    $exception->getMessage(),
                    $exception->getRequest()->getRawHeaders(),
                    $exception
                );
            } else {
                // Unexpected exception type
                $e = $exception;
            }

            $collection->add($e);
        }

        throw $collection;
    }
}
