<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Shell;

use SPeter81\DomainInfo\Contract\ShellExecutorInterface;

final class DummyShellExecutor implements ShellExecutorInterface
{
    public function execute(string $command): ?string
    {
        return '';
    }

    public function commandExists(string $command): bool
    {
        return true;
    }
}