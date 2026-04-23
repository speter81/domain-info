<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Http;

use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\Exception\HttpException;

final class CurlHttpClient implements HttpClientInterface
{
    private const DEFAULT_TIMEOUT    = 15;
    private const MAX_REDIRECTS      = 5;
    private const DEFAULT_USER_AGENT = 'DomainInfo-PHP/1.0 (https://github.com/speter/domain-info)';

    public function __construct(
        private readonly int    $timeout   = self::DEFAULT_TIMEOUT,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
    ) {}

    /**
     * @throws HttpException
     */
    public function get(string $url): string
    {
        return $this->call($url);
    }

    public function post(string $url, array $data = []): string
    {
        $options = [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ];
        return $this->call($url, $options);
    }


    private function call(string $url, $options = []): string
    {
        if ( ! extension_loaded('curl')) {
            throw new HttpException('The cURL PHP extension is required but not loaded.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException("Failed to initialise cURL handle for URL: {$url}");
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',   // Accept any encoding, let cURL decode
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/json,*/*',
            ]
        ] + $options;

        curl_setopt_array($ch, $curlOpts);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new HttpException(
                "cURL request failed for URL '{$url}': {$curlError}"
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new HttpException(
                "HTTP {$httpCode} response received for URL: {$url}"
            );
        }

        return (string) $response;
    }


}