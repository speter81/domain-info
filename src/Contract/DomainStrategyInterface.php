<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Contract;

use SPeter81\DomainInfo\DTO\DomainInfo;
use SPeter81\DomainInfo\Exception\StrategyFailedException;

interface DomainStrategyInterface
{
    public function supports(string $tld): bool;

    /**
     * @param string $domain
     * @return DomainInfo
     * @throws StrategyFailedException
     */
    public function query(string $domain): DomainInfo;
}