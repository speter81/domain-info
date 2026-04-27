<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo;

use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\Contract\ShellExecutorInterface;
use SPeter81\DomainInfo\Exception\DomainInfoException;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use SPeter81\DomainInfo\Http\CurlHttpClient;
use SPeter81\DomainInfo\Resolver\StrategyResolver;
use SPeter81\DomainInfo\Shell\SystemShellExecutor;
use SPeter81\DomainInfo\Strategy\HuStrategy;
use SPeter81\DomainInfo\Strategy\RdapStrategy;
use SPeter81\DomainInfo\Strategy\WhoisFallbackStrategy;

/**
 * Main entry point for the domain-info library.
 *
 * Usage:
 *
 *   $service = DomainInfoService::create();
 *   $info    = $service->lookup('example.com');
 *
 * Lookup flow:
 *   1. Validate and normalise the domain name.
 *   2. Extract the TLD and ask the StrategyResolver for the best strategy.
 *   3. Execute the strategy.
 *   4. On failure (or when no strategy exists for the TLD), fall back to
 *      WhoisFallbackStrategy.
 *   5. If whois also fails, throw DomainInfoException.
 */
final class DomainInfoService
{
    public function __construct(
        private readonly StrategyResolver    $resolver,
        private readonly DomainStrategyInterface $whoisFallback,
    ) {}

    public static function create(
        ?HttpClientInterface  $httpClient    = null,
        ?ShellExecutorInterface $shellExecutor = null,
    ): self {
        $http  = $httpClient    ?? new CurlHttpClient();
        $shell = $shellExecutor ?? new SystemShellExecutor();

        $resolver = new StrategyResolver();
        $resolver->register(new HuStrategy($http));
        $resolver->register(new RdapStrategy($http));

        return new self($resolver, new WhoisFallbackStrategy($shell));
    }

    /**
     * Look up registration information for any domain name.
     *
     * @param  string $domain Fully qualified domain name, e.g. "example.hu"
     *
     * @return array{
     *     domain_name:       string|null,
     *     expiration_date:   string|null,
     *     registration_date: string|null,
     *     registrar:         string|null,
     *     nameservers:       list<string>,
     *     last_updated:      string|null,
     * }
     *
     * @throws DomainInfoException when all strategies fail or the domain is invalid
     */
    public function lookup(string $rawDomain): array
    {
        $domain = strtolower(trim($rawDomain));
        $domain = $this->toResolvableDomain($domain);
        $this->validateDomain($domain);

        $tld = $this->extractTld($domain);

        $primaryException = null;
        try {
            $strategy = $this->resolver->resolve($tld);
            $info     = $strategy->query($domain);
            return $info->toArray();
        } catch (StrategyFailedException $e) {
            $primaryException = $e;
            // Fall through to whois fallback
        } catch (DomainInfoException) {
            // No strategy registered for this TLD
        }

        try {
            $info = $this->whoisFallback->query($domain);
            return $info->toArray();
        } catch (StrategyFailedException $e) {
            $previous = $primaryException ?? $e;
            throw new DomainInfoException(
                "All strategies exhausted for domain '{$domain}'. "
                . "Whois error: {$e->getMessage()}",
                previous: $previous,
            );
        }
    }

    /**
     * @throws DomainInfoException on an invalid domain name
     */
    private function validateDomain(string $domain): void
    {
        if (
            $domain === ''
            || !preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                $domain,
            )
        ) {
            throw new DomainInfoException(
                "'{$domain}' is not a valid domain name."
            );
        }
    }

    private function extractTld(string $domain): string
    {
        $parts = explode('.', $domain);
        return end($parts);
    }

    private function toResolvableDomain(string $domain): string
    {
        if ( ! $this->needsPunycode($domain)) {
            return $domain;
        }

        $result = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($result === false) {
            throw new \InvalidArgumentException("Invalid domain: {$domain}");
        }

        return $result;
    }

    private function needsPunycode(string $domain): bool
    {
        if (str_contains($domain, 'xn--')) {
            return false;
        }

        return (bool) preg_match('/[^\x00-\x7F]/', $domain);
    }

}