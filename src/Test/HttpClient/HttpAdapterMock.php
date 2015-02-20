<?php

namespace FOS\HttpCache\Test\HttpClient;

use Ivory\HttpAdapter\AbstractHttpAdapter;
use Ivory\HttpAdapter\Message\InternalRequestInterface;
use Ivory\HttpAdapter\Message\Response;

class HttpAdapterMock extends AbstractHttpAdapter
{
    private $requests = array();
    private $exception;

    public function setException(\Exception $exception)
    {
        $this->exception = $exception;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function sendInternalRequest(
        InternalRequestInterface $internalRequest
    ) {
        $this->requests[] = $internalRequest;
        
        if ($this->exception) {
            throw $this->exception;
        }
        
        return new Response();
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
