<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Strategy;

use DateTimeImmutable;
use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Contract\ShellExecutorInterface;
use SPeter81\DomainInfo\DTO\DomainInfo;
use SPeter81\DomainInfo\Exception\StrategyFailedException;

/**
 * Universal fallback strategy that shells out to the system `whois` command.
 *
 * Supports any TLD (returns true from supports()) so it can always be used as
 * a last resort.  Parses both English (IANA / Verisign / RIPE style) and
 * Hungarian (nic.hu style) output via a list of regex patterns per field.
 */
final class WhoisFallbackStrategy implements DomainStrategyInterface
{
    /** @var list<string> */
    private const EXPIRY_PATTERNS = [
        '/^registry expiry date\s*:\s*(.+)$/im',
        '/^expir(?:y|ation|es)\s+date\s*:\s*(.+)$/im',
        '/^expiration\s*:\s*(.+)$/im',
        '/^expires\s*:\s*(.+)$/im',
        '/^paid-till\s*:\s*(.+)$/im',
        '/^valid\s+until\s*:\s*(.+)$/im',
        '/^lejárati dátum\s*:\s*(.+)$/im',
        '/^lejár\s*:\s*(.+)$/im',
    ];

    /** @var list<string> */
    private const DOMAIN_NAME_PATTERNS = [
        '/^Domain Name:\s*(.+$)/im',
        '/^Domain:\s*(.+$)/im',
    ];

    /** @var list<string> */
    private const CREATION_PATTERNS = [
        '/^creation date\s*:\s*(.+)$/im',
        '/^created(?:\s+on)?\s*:\s*(.+)$/im',
        '/^registered(?:\s+on)?\s*:\s*(.+)$/im',
        '/^domain registered\s*:\s*(.+)$/im',
        '/^bejegyezve\s*:\s*(.+)$/im',
        '/^regisztrálva\s*:\s*(.+)$/im',
    ];

    /** @var list<string> */
    private const REGISTRAR_PATTERNS = [
        '/^registrar\s*:\s*(.+)$/im',
        '/^registrar name\s*:\s*(.+)$/im',
        '/^regisztrátor\s*:\s*(.+)$/im',
    ];

    /** @var list<string> */
    private const UPDATED_PATTERNS = [
        '/^updated date\s*:\s*(.+)$/im',
        '/^last updated?\s*:\s*(.+)$/im',
        '/^last modified\s*:\s*(.+)$/im',
        '/^last changed\s*:\s*(.+)$/im',
        '/^utoljára módosítva\s*:\s*(.+)$/im',
        '/^módosítva\s*:\s*(.+)$/im',
    ];

    /** @var list<string> */
    private const NAMESERVER_PATTERNS = [
        '/^name server\s*:\s*(.+)$/im',
        '/^nameserver\s*:\s*(.+)$/im',
        '/^nserver\s*:\s*(.+)$/im',
        '/^névszerver\s*:\s*(.+)$/im',
    ];

    /** @var list<string> */
    private const STATUS_PATTERNS = [
        '/^status\s*:\s*(.+)$/im',
        '/^allapot\s*:\s*(.+)$/im',
        '/^állapot\s*:\s*(.+)$/im',
    ];


    private const NOT_FOUND_PHRASES = [
        'no match',
        'not found',
        'no entries found',
        'object does not exist',
        'no data found',
        'domain not found',
        'nincs talalat',
    ];

    public function __construct(
        private readonly ShellExecutorInterface $shellExecutor,
    ) {}

    public function supports(string $tld): bool
    {
        return true; // Fallback strategy supports any TLD
    }

    public function query(string $domain): DomainInfo
    {
        $output = $this->runWhois($domain);
        return $this->parseWhoisOutput($output, $domain);
    }

    private function runWhois(string $domain): string
    {
        if (!$this->shellExecutor->commandExists('whois')) {
            throw new StrategyFailedException(
                'WhoisFallbackStrategy: `whois` command not found on this system.'
            );
        }

        $output = $this->shellExecutor->execute('whois -I ' . escapeshellarg($domain) . ' 2>&1');

        if ($output === null || trim($output) === '') {
            throw new StrategyFailedException(
                "WhoisFallbackStrategy: `whois` returned no output for domain '{$domain}'."
            );
        }

        $this->assertDomainFound($output, $domain);

        return $output;
    }

    private function assertDomainFound(string $output, string $domain): void
    {
        $lower = strtolower($output);
        foreach (self::NOT_FOUND_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                throw new StrategyFailedException(
                    "WhoisFallbackStrategy: Domain '{$domain}' not found in whois database."
                );
            }
        }
    }

    private function parseWhoisOutput(string $output, string $domain): DomainInfo
    {
        return new DomainInfo(
            domainName:       $this->extractDomain($output, self::DOMAIN_NAME_PATTERNS, $domain),
            expirationDate:   $this->extractDate($output, self::EXPIRY_PATTERNS),
            registrationDate: $this->extractDate($output, self::CREATION_PATTERNS),
            registrar:        $this->extractFirst($output, self::REGISTRAR_PATTERNS),
            nameservers:      $this->extractAll($output, self::NAMESERVER_PATTERNS),
            lastUpdated:      $this->extractDate($output, self::UPDATED_PATTERNS),
            status:           $this->extractStatus($output, self::STATUS_PATTERNS),
        );
    }

    /**
     * @param list<string> $patterns
     */
    private function extractDate(string $output, array $patterns): ?DateTimeImmutable
    {
        $raw = $this->extractFirst($output, $patterns);
        if ($raw === null) {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $patterns
     */
    private function extractFirst(string $output, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                $value = trim($matches[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * @param  list<string> $patterns
     * @return list<string>
     */
    private function extractAll(string $output, array $patterns): array
    {
        $results = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $output, $matches);
            foreach ($matches[1] as $value) {
                $value = strtolower(trim($value));
                // Strip any trailing junk (e.g. IP address after a space)
                $value = strtok($value, " \t") ?: $value;
                if ($value !== '') {
                    $results[] = $value;
                }
            }
        }

        return array_values(array_unique($results));
    }

    /**
     * @param  list<string> $patterns
     * @return list<string>
     */
    private function extractStatus(string $output, array $patterns): array
    {
        $value = $this->extractFirst($output, $patterns);
        return $value !== null ? [$value] : [];
    }

    /**
     * @param  list<string> $patterns
     */
    private function extractDomain(string $output, array $patterns, string $domain): string
    {
        $results = $this->extractAll($output, $patterns);  // already lowercased
        $domain  = strtolower($domain);

        foreach ($results as $r) {
            if ($r === $domain) {
                return $r;
            }
        }

        // Fallback: longest result is more likely the FQDN than a bare TLD
        usort($results, fn($a, $b) => strlen($b) - strlen($a));
        return $results[0] ?? '';
    }


}