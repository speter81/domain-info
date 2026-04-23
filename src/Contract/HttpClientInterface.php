<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Contract;

use SPeter81\DomainInfo\Exception\HttpException;

interface HttpClientInterface
{
    /**
     * Perform an HTTP GET request and return the response body.
     * @throws HttpException on connection failure or non-2xx response
     */
    public function get(string $url): string;
    /**
     * Perform an HTTP POST request and return the response body.
     * @param array $data contains the postfields name/value pairs
     * @throws HttpException on connection failure or non-2xx response
     */
    public function post(string $url, array $data = []): string;
}