<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Resolver;

use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Exception\DomainInfoException;

/**
 * Maintains a registry of TLD-specific strategies and resolves the best one
 * for a given TLD.
 *
 * Strategies are evaluated in registration order; the first one whose
 * supports() returns true is used.  This allows multiple strategies to
 * compete for the same TLD (useful for A/B or priority-based selection).
 *
 * Note: WhoisFallbackStrategy is intentionally NOT registered here.
 * It is injected directly into DomainInfoService and invoked only when
 * every registered strategy fails.
 */
final class StrategyResolver
{
    /** @var list<DomainStrategyInterface> */
    private array $strategies = [];

    public function register(DomainStrategyInterface $strategy): self
    {
        $this->strategies[] = $strategy;
        return $this;
    }

    /**
     * Resolve the appropriate strategy for a TLD.
     * @param string $tld Lowercase TLD without leading dot (e.g. "hu", "com")
     * @throws DomainInfoException when no registered strategy supports the TLD
     */
    public function resolve(string $tld): DomainStrategyInterface
    {
        $tld = strtolower(trim($tld));

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($tld)) {
                return $strategy;
            }
        }

        throw new DomainInfoException(
            "No registered strategy supports TLD '{$tld}'. "
            . "Register an appropriate strategy or rely on the whois fallback."
        );
    }
}