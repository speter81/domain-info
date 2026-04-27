<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Shell;

use SPeter81\DomainInfo\Contract\ShellExecutorInterface;

final class SystemShellExecutor implements ShellExecutorInterface
{
    public function execute(string $command): ?string
    {
        return \shell_exec($command);
    }

    public function commandExists(string $command): bool
    {
        $result = \shell_exec('which ' . escapeshellarg($command) . ' 2>/dev/null');
        return $result !== null && trim($result) !== '';
    }
}