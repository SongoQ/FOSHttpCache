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

use FOS\HttpCache\Exception\InvalidUrlException;
use Ivory\HttpAdapter\Message\Request;
use Ivory\HttpAdapter\Message\RequestInterface;

/**
 * A queue of requests to be sent to the HTTP caching server
 */
class RequestQueue implements \Countable
{
    private $servers = array();
    private $baseUrl;
    private $defaultHost;
    private $basePath;

    /**
     * @var InvalidationRequest[]|array
     */
    private $queue = array();
    
    public function __construct(array $servers, $baseUrl = null)
    {
        $this->setServers($servers);
        $this->setBaseUrl($baseUrl);
    }
    
    public function add(InvalidationRequest $request)
    {
        $signature = $request->getSignature();
        
        if (!isset($this->queue[$signature])) {
            $this->queue[$signature] = $request;
        }
    }
    
    public function clear()
    {
        $this->queue = array();
    }
    
    public function count()
    {
        return count($this->queue);
    }

    /**
     * @return RequestInterface[]
     */
    public function all()
    {
        $requests = array();
        foreach ($this->queue as $queuedRequest) {
            $headers = $queuedRequest->getHeaders();
            if (empty($headers['Host'])) {
                if ($this->defaultHost) {
                    $headers['Host'] = $this->defaultHost;
                } else {
                    unset($headers['Host']);
                }
            }

            foreach ($this->servers as $server) {
                $request = new Request(
                    $this->combineUrls($server, $queuedRequest->getUrl()),
                    $queuedRequest->getMethod(),
                    null,
                    $headers
                );
                
                $requests[] = $request;
            }
        }
        
        return $requests;
    }
    
    
    /**
     * Set caching proxy servers
     *
     * @param array $servers Caching proxy proxy server hostnames or IP
     *                       addresses, including port if not port 80.
     *                       E.g. array('127.0.0.1:6081')
     *
     * @throws InvalidUrlException If server is invalid or contains URL
     *                             parts other than scheme, host, port
     */
    public function setServers(array $servers)
    {
        $this->servers = array();
        foreach ($servers as $server) {
            $this->servers[] = $this->filterUrl(
                $server,
                array('scheme', 'host', 'port')
            );
        }
    }
    
    /**
     * Set application hostname, optionally including a base URL, for purge and
     * refresh requests
     *
     * @param string $url Your application’s base URL or hostname
     */
    public function setBaseUrl($url = null)
    {
        if (null === $url) {
            $this->baseUrl = null;

            return;
        }
            
        $this->baseUrl = $this->filterUrl($url);
        $parts = parse_url($this->baseUrl);
        $this->defaultHost = $parts['host'];
        if (isset($parts['port'])) {
            $this->defaultHost .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $this->basePath = $parts['path'];
        }
    }
    
    public function getAllowedSchemes()
    {
        return array('http');
    }
    
    /**
     * Filter a URL
     *
     * Prefix the URL with "http://" if it has no scheme, then check the URL
     * for validity. You can specify what parts of the URL are allowed.
     *
     * @param string   $url
     * @param string[] $allowedParts Array of allowed URL parts (optional)
     *
     * @throws InvalidUrlException If URL is invalid, the scheme is not http or
     *                             contains parts that are not expected.
     *
     * @return string The URL (with default scheme if there was no scheme)
     */
    private function filterUrl($url, array $allowedParts = array())
    {
        // parse_url doesn’t work properly when no scheme is supplied, so
        // prefix URL with HTTP scheme if necessary.
        if (false === strpos($url, '://')) {
            $url = sprintf('%s://%s', $this->getDefaultScheme(), $url);
        }

        if (!$parts = parse_url($url)) {
            throw InvalidUrlException::invalidUrl($url);
        }
        if (empty($parts['scheme'])) {
            throw InvalidUrlException::invalidUrl($url, 'empty scheme');
        }

        if (!in_array(strtolower($parts['scheme']), $this->getAllowedSchemes())) {
            throw InvalidUrlException::invalidUrlScheme($url, $parts['scheme'], $this->getAllowedSchemes());
        }

        if (count($allowedParts) > 0) {
            $diff = array_diff(array_keys($parts), $allowedParts);
            if (count($diff) > 0) {
                throw InvalidUrlException::invalidUrlParts($url, $allowedParts);
            }
        }
        
        // Filter out trailing slash if present
        return rtrim($url, '/');
    }
    
    protected function getDefaultScheme()
    {
        return 'http';
    }
    
    private function combineUrls($server, $url)
    {
        $parts = parse_url($url);
        if (isset($parts['scheme'])) {
            // Absolute URL
            $url = '';
            foreach (array('path', 'query', 'fragment') as $item) {
                if (isset($parts[$item])) {
                    $url .= $parts[$item];
                }
            }
        } else {
            // Path; final slash was dropped from baseUrl in filterUrl
            $url = $this->basePath . '/' . ltrim($url, '/');
        }
        
        return $server . $url;
    }
}
