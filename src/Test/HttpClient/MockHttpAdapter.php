<?php

namespace FOS\HttpCache\Test\HttpClient;

use Http\Adapter\Common\Exception\MultiHttpAdapterException;
use Http\Adapter\Exception;
use Http\Adapter\PsrHttpAdapter;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class MockHttpAdapter implements PsrHttpAdapter
{
    private $requests = array();
    private $exception;

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request)
    {
        $this->requests[] = $request;
        
        if ($this->exception) {
            throw $this->exception;
        }
        
        return new Response();
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequests(array $requests)
    {
        $responses = [];
        $exceptions = new MultiHttpAdapterException();
        
        foreach ($requests as $request) {
            try {
                $responses[] = $this->sendRequest($request);
            } catch (\Exception $e) {
                $exceptions->addException($e);
            }
        }
        
        if ($exceptions->hasExceptions()) {
            throw $exceptions;
        }
        
        return $responses;
    }

    public function setException(\Exception $exception)
    {
        $this->exception = $exception;
    }

    /**
     * {@inheritdoc}
     *
     * @return string The name.
     */
    public function getName()
    {
        return 'mock';
    }
    
    public function getRequests()
    {
        return $this->requests;
    }
    
    public function clear()
    {
        $this->exception = null;
    }
}
