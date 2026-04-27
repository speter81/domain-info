<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Tests\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SPeter81\DomainInfo\Contract\ShellExecutorInterface;
use SPeter81\DomainInfo\Shell\DummyShellExecutor;

final class DummyShellExecutorTest extends TestCase
{
    private DummyShellExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new DummyShellExecutor();
    }

    #[Test]
    public function itImplementsShellExecutorInterface(): void
    {
        self::assertInstanceOf(ShellExecutorInterface::class, $this->executor);
    }

    #[Test]
    public function itReturnsEmptyStringForAnyCommand(): void
    {
        self::assertSame('', $this->executor->execute('whois example.com'));
    }

    #[Test]
    public function itReturnsEmptyStringRegardlessOfCommandContent(): void
    {
        self::assertSame('', $this->executor->execute(''));
        self::assertSame('', $this->executor->execute('dig +short example.com'));
    }

    #[Test]
    public function itAlwaysReportsCommandExists(): void
    {
        self::assertTrue($this->executor->commandExists('whois'));
    }

    #[Test]
    public function itReportsTrueEvenForNonExistentCommands(): void
    {
        self::assertTrue($this->executor->commandExists('nonexistent-command-xyz'));
        self::assertTrue($this->executor->commandExists(''));
    }
}
