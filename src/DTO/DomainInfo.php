<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\DTO;

use DateTimeImmutable;

final class DomainInfo
{
    public function __construct(
        public readonly ?string $domainName = null,
        public readonly ?DateTimeImmutable $expirationDate = null,
        public readonly ?DateTimeImmutable $registrationDate = null,
        public readonly ?string $registrar = null,
        public readonly array $nameservers = [],
        public readonly ?DateTimeImmutable $lastUpdated = null,
        public readonly array $status = []
    ) {}

    /**
     * Serialise to a plain associative array.
     * Dates are formatted as ISO-8601 strings; null fields remain null.
     *
     * @return array{
     *     domain_name:       string|null,
     *     expiration_date:   string|null,
     *     registration_date: string|null,
     *     registrar:         string|null,
     *     nameservers:       list<string>,
     *     last_updated:      string|null,
     *     status:            list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'domain_name'       => $this->domainName,
            'expiration_date'   => $this->expirationDate?->format('Y-m-d H:i:s'),
            'registration_date' => $this->registrationDate?->format('Y-m-d H:i:s'),
            'registrar'         => $this->registrar,
            'nameservers'       => array_values($this->nameservers),
            'last_updated'      => $this->lastUpdated?->format('Y-m-d H:i:s'),
            'status'            => $this->status,
        ];
    }
}