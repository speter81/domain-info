<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Contract;

interface ShellExecutorInterface
{
    public function execute(string $command): ?string;
    public function commandExists(string $command): bool;
}