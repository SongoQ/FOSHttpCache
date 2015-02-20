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

/**
 * HTTP client that sends invalidation requests to a reverse caching proxy
 */
interface HttpClientInterface
{
    /**
     * Send queued requests
     *
     * @param RequestQueue $requests
     *
     * @return int Number of invalidations
     *
     * @throws ExceptionCollection
     */
    public function sendRequests(RequestQueue $requests);
}
