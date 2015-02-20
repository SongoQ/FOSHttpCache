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
use FOS\HttpCache\ProxyClient\Request\RequestQueue;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class GuzzleAdapter implements HttpClientInterface
{
    private $client;
    
    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
    }

    /*
     * {@inheritdoc}
     */
    public function sendRequests(RequestQueue $requests)
    {
        $exceptions = new ExceptionCollection();
        
        foreach ($requests as $request) {
            $response = $this->client->send(
                $this->client->createRequest(
                    $request->getMethod(),
                    $request->getUrl(),
                    array(
                        'headers' => $request->getHeaders(),
                        'future'  => true,
                    )
                )
            );
            
            $response->then(
                function ($response) {
                    // ignore
                },
                function ($error) use ($exceptions) {
                    $exceptions->add($error);
                }
            );
        }
    }
}
 
