<?php

/*
 * This file is part of the FOSHttpCache package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCache\Exception;

use Http\Adapter\Exception\HttpAdapterException;

/**
 * Wrapping an error response from the caching proxy.
 */
class ProxyResponseException extends \RuntimeException implements HttpCacheExceptionInterface
{
    /**
     * @param HttpAdapterException $adapterException HTTP adapter exception.
     *
     * @return ProxyResponseException
     */
    public static function proxyResponse(HttpAdapterException $adapterException)
    {
        $message = sprintf(
            '%s error response "%s" from caching proxy at %s',
            $adapterException->getResponse()->getStatusCode(),
            $adapterException->getResponse()->getReasonPhrase(),
            $adapterException->getRequest()->getHeaderLine('Host')
        );

        return new ProxyResponseException($message, 0, $adapterException);
    }
}
