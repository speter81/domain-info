<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Strategy;

use DateTimeImmutable;
use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\DTO\DomainInfo;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Queries domain information for .hu domains from the ISOC-HU web WHOIS.
 *
 * Endpoint: https://info.domain.hu/webwhois/hu/domain/{domain}
 * Returns an HTML page which is parsed with DOMDocument + DOMXPath.
 */
final class HuStrategy implements DomainStrategyInterface
{
    private const BASE_URL = 'https://info.domain.hu/webwhois/hu/domain/';

    /** @var list<string> */
    private const SUPPORTED_TLDS = ['hu'];

    private const DOMAIN_NAME_LABELS = [
        'Domain név','Domain',
    ];

    private const EXPIRY_LABELS = [
        'lejárati dátum', 'lejár', 'lejárt', 'érvényes', 'érvényes ig',
        'expiry date', 'expiration date', 'expires',
    ];

    private const CREATION_LABELS = [
        'bejegyezve', 'regisztrálva', 'létrehozva', 'regisztráció dátuma', 'regisztrálás dátuma',
        'created', 'creation date', 'registered',
    ];

    private const REGISTRAR_LABELS = [
        'regisztrátor', 'regisztrátori szervezet',
        'registrar', 'registrar name',
    ];

    private const UPDATED_LABELS = [
        'utoljára módosítva', 'módosítva', 'frissítve',
        'updated', 'last updated', 'last changed', 'last modified',
    ];

    private const NAMESERVER_LABELS = [
        'névszerver', 'névszerverek',
        'nameserver', 'name server', 'nserver',
    ];

    private const STATUS_LABELS = [
        'Állapot','allapot', 'status',
    ];


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
            $html = $this->httpClient->post($url);
        } catch (\Throwable $e) {
            throw new StrategyFailedException(
                "HuStrategy: HTTP request failed for domain '{$domain}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if (trim($html) === '') {
            throw new StrategyFailedException(
                "HuStrategy: Empty response for domain '{$domain}'"
            );
        }

        return $this->parseHtml($html);
    }

    private function parseHtml(string $html): DomainInfo
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        $pairs = $this->extractAllLabelValuePairs($xpath);

        return new DomainInfo(
            domainName:       $this->findFirstString($pairs, self::DOMAIN_NAME_LABELS),
            expirationDate:   $this->findDate($pairs, self::EXPIRY_LABELS),
            registrationDate: $this->findDate($pairs, self::CREATION_LABELS),
            registrar:        $this->findFirstString($pairs, self::REGISTRAR_LABELS),
            nameservers:      $this->collectNameservers($pairs),
            lastUpdated:      $this->findDate($pairs, self::UPDATED_LABELS),
            status:           $this->collectStatus($pairs)
        );
    }

    /**
     * Walk the DOM and collect all visible label → value pairs.
     * Because multiple rows may share the same label (e.g. multiple nameservers),
     * each key maps to an array of values.
     **
     * @return array<string, list<string>>
     */
    private function extractAllLabelValuePairs(DOMXPath $xpath): array
    {
        $pairs = [];
        $rows = $xpath->query('//tr');
        if ($rows !== false) {
            foreach ($rows as $row) {
                [$label, $value] = $this->extractRowPair($xpath, $row);
                if ($label !== null && $value !== null) {
                    $pairs[$label][] = $value;
                }
            }
        }

        $dts = $xpath->query('//dt');
        if ($dts !== false) {
            foreach ($dts as $dt) {
                /** @var DOMElement $dt */
                $dd = $dt->nextElementSibling;
                if ($dd !== null && strtolower($dd->nodeName) === 'dd') {
                    $label = $this->normaliseLabel($dt->textContent);
                    $value = trim($dd->textContent);
                    if ($label !== '' && $value !== '') {
                        $pairs[$label][] = $value;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function extractRowPair(DOMXPath $xpath, \DOMNode $row): array
    {
        // <th>/<td> pair
        $ths = $xpath->query('.//th', $row);
        $tds = $xpath->query('.//td', $row);

        if ($ths !== false && $ths->length > 0 && $tds !== false && $tds->length > 0) {
            $label = $this->normaliseLabel($ths->item(0)->textContent);
            $value = trim($tds->item(0)->textContent);
            if ($label !== '' && $value !== '') {
                return [$label, $value];
            }
        }

        // Two-column <td>/<td> pair
        if ($tds !== false && $tds->length === 2) {
            $label = $this->normaliseLabel($tds->item(0)->textContent);
            $value = trim($tds->item(1)->textContent);
            if ($label !== '' && $value !== '') {
                return [$label, $value];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, list<string>> $pairs
     * @param list<string>                $labels
     */
    private function findDate(array $pairs, array $labels): ?DateTimeImmutable
    {
        $raw = $this->findFirstString($pairs, $labels);
        return $raw !== null ? $this->parseDate($raw) : null;
    }

    /**
     * @param array<string, list<string>> $pairs
     * @param list<string>                $labels
     */
    private function findFirstString(array $pairs, array $labels): ?string
    {
        foreach ($labels as $label) {
            $needle = $this->normaliseLabel($label);

            if (isset($pairs[$needle][0])) {
                return $pairs[$needle][0];
            }

            foreach ($pairs as $key => $values) {
                if (str_contains($key, $needle) && isset($values[0])) {
                    return $values[0];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $pairs
     * @return list<string>
     */
    private function collectNameservers(array $pairs, $separator = ' '): array
    {
        $result = [];

        foreach ($pairs as $label => $values) {
            foreach (self::NAMESERVER_LABELS as $nsLabel) {
                if (str_contains($label, $this->normaliseLabel($nsLabel))) {
                    $parsedValues = $values;
                    if(count($values) == 1 && str_contains($values[0], $separator)) {
                        $parsedValues = explode($separator, $values[0]);
                    }
                    foreach ($parsedValues as $v) {
                        $ns = strtolower(trim($v));
                        if ($ns !== '' && str_contains($ns, '.')) {
                            $result[] = $ns;
                        }
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($result));
    }
    /**
     * @param array<string, list<string>> $pairs
     * @return list<string>
     */
    private function collectStatus(array $pairs): array
    {
        $results = [];
        $results[] = $this->findFirstString($pairs, self::STATUS_LABELS);
        return $results;
    }

    private function parseDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);

        if (preg_match(
            '/^(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\.?\s*(?:(\d{2}):(\d{2})(?::(\d{2}))?)?/',
            $raw,
            $m,
        )) {
            $normalised = sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                isset($m[4]) ? (int) $m[4] : 0,
                isset($m[5]) ? (int) $m[5] : 0,
                isset($m[6]) ? (int) $m[6] : 0,
            );
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalised);
            return $date !== false ? $date : null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseLabel(string $label): string
    {
        return strtolower(trim($label));
    }
}