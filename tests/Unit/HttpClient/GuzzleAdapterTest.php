<?php

namespace Unit\HttpClient;

use FOS\HttpCache\HttpClient\GuzzleAdapter;
use FOS\HttpCache\ProxyClient\Request\InvalidationRequest;
use FOS\HttpCache\ProxyClient\Request\RequestQueue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Mock;

class GuzzleAdapterTest extends \PHPUnit_Framework_TestCase
{
    protected $client;

    /**
     * @var Mock
     */
    protected $mock;
    protected $adapter;
    
    public function testSendRequests()
    {
        return;
        $queue = new RequestQueue(array('127.0.0.1'));
        $queue->add(new InvalidationRequest('PURGE', '/invalidate'));
        $this->adapter->sendRequests($queue);
        var_dump($this->mock->getEvents());die;
    }
    
    protected function setUp()
    {
        $this->mock = new Mock();
        $this->mock->addResponse(new Response(200));
        
        $this->client = new Client();
        $this->client->getEmitter()->attach($this->mock);
        
        $this->adapter = new GuzzleAdapter($this->client);
    }
}
