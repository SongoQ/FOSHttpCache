<?php

namespace FOS\HttpCache\Exception;

class HttpClientException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(
            '$client must be an instance of HttpClientInterface,'
            . 'a Guzzle client (deprecated) or null'
        );
    }
}
