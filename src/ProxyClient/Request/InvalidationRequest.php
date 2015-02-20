<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\ProxyClient\Request;

/**
 * A request to the HTTP caching server
 */
class InvalidationRequest
{
    private $method;
    private $url;
    private $headers;
    
    public function __construct($method, $url, array $headers = array())
    {
        $this->method = $method;
        $this->url = $url;
        
        $parts = parse_url($url);
        if (isset($parts['host'])) {
            $host = $parts['host'];
            if (isset($parts['port']) && 80 != $parts['port']) {
                $host .= ':' . $parts['port'];
            }
            $headers = array_merge(array('Host' => $host), $headers);
        }
        $this->headers = $headers;
    }
    
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return array
     */
    public function getUrl()
    {
        return $this->url;
    }
    
    public function getSignature()
    {
        ksort($this->headers);
        
        return md5($this->method . "\n" . $this->url. "\n" . var_export($this->headers, true));
    }
}
