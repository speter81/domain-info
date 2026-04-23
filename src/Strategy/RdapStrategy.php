<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Strategy;

use DateTimeImmutable;
use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\DTO\DomainInfo;
use SPeter81\DomainInfo\Exception\StrategyFailedException;

/**
 * Queries domain information via the RDAP protocol (rdap.org bootstrap).
 *
 * Endpoint: https://rdap.org/domain/{domain}
 *
 * rdap.org acts as a bootstrap proxy — it redirects to the authoritative RDAP
 * server for the TLD, so this strategy works for any RDAP-enabled TLD, not just .com.
 * We still declare explicit TLD support to keep the resolver deterministic.
 *
 * @see https://www.iana.org/assignments/rdap-json-values/rdap-json-values.xhtml
 */
final class RdapStrategy implements DomainStrategyInterface
{
    private const BASE_URL = 'https://rdap.org/domain/';

    /** @var list<string> */
    private const SUPPORTED_TLDS = ['com', 'net', 'org', 'io', 'co'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function supports(string $tld): bool
    {
        return in_array(strtolower($tld), self::SUPPORTED_TLDS, true);
    }

    public function query(string $domain): DomainInfo
    {
        $url = self::BASE_URL . urlencode($domain);

        try {
            $json = $this->httpClient->get($url);
        } catch (\Throwable $e) {
            throw new StrategyFailedException(
                "RdapStrategy: HTTP request failed for domain '{$domain}': {$e->getMessage()}",
                previous: $e,
            );
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new StrategyFailedException(
                    "RdapStrategy: Unexpected JSON root type for domain '{$domain}'"
                );
            }
        } catch (\JsonException $e) {
            throw new StrategyFailedException(
                "RdapStrategy: Unexpected JSON root type for domain '{$domain}'"
            ,$e->getCode(), $e);
        }


        return $this->buildDomainInfo($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildDomainInfo(array $data): DomainInfo
    {
        $events      = $this->parseEvents($data['events'] ?? []);
        $nameservers = $this->parseNameservers($data['nameservers'] ?? []);
        $registrar   = $this->parseRegistrar($data['entities'] ?? []);

        return new DomainInfo(
            domainName:       $data['ldhName'] ?? null,
            expirationDate:   $events['expiration']   ?? null,
            registrationDate: $events['registration'] ?? null,
            registrar:        $registrar,
            nameservers:      $nameservers,
            lastUpdated:      $events['last changed'] ?? $events['last update of rdap database'] ?? null,
        );
    }

    /**
     * Parse the RDAP events array into an action -> DateTimeImmutable map.
     *
     * @param  array<int, mixed> $events
     * @return array<string, DateTimeImmutable>
     */
    private function parseEvents(array $events): array
    {
        $result = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $action = strtolower(trim((string) ($event['eventAction'] ?? '')));
            $date   = trim((string) ($event['eventDate'] ?? ''));

            if ($action === '' || $date === '') {
                continue;
            }

            try {
                $result[$action] = new DateTimeImmutable($date);
            } catch (\Throwable) {
                // Malformed date — skip rather than crash
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed> $nameservers
     * @return list<string>
     */
    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        foreach ($nameservers as $ns) {
            if (!is_array($ns)) {
                continue;
            }

            // Prefer the unicode name; fall back to the LDH (ASCII-compatible) name
            $name = trim((string) ($ns['unicodeName'] ?? $ns['ldhName'] ?? ''));
            if ($name !== '') {
                $result[] = strtolower($name);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, mixed> $entities
     */
    private function parseRegistrar(array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $roles = (array) ($entity['roles'] ?? []);
            if (!in_array('registrar', $roles, true)) {
                continue;
            }

            $name = $this->extractVcardFn((array) ($entity['vcardArray'] ?? []));
            if ($name !== null) {
                return $name;
            }

            foreach ((array) ($entity['publicIds'] ?? []) as $pubId) {
                if (is_array($pubId) && isset($pubId['identifier'])) {
                    return (string) $pubId['identifier'];
                }
            }

            if (isset($entity['handle'])) {
                return (string) $entity['handle'];
            }
        }

        return null;
    }

    /**
     * Extract the "fn" (full name) property from an RDAP vcardArray structure.
     * @param  array<int, mixed> $vcardArray
     */
    private function extractVcardFn(array $vcardArray): ?string
    {
        if (count($vcardArray) < 2 || !is_array($vcardArray[1])) {
            return null;
        }

        foreach ($vcardArray[1] as $property) {
            if (
                is_array($property)
                && isset($property[0], $property[3])
                && strtolower((string) $property[0]) === 'fn'
                && (string) $property[3] !== ''
            ) {
                return (string) $property[3];
            }
        }

        return null;
    }
}