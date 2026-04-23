<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Tests\Strategy;

use SPeter81\DomainInfo\Contract\ShellExecutorInterface;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use SPeter81\DomainInfo\Strategy\WhoisFallbackStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WhoisFallbackStrategyTest extends TestCase
{
    private ShellExecutorInterface&MockObject $shell;
    private WhoisFallbackStrategy $strategy;

    protected function setUp(): void
    {
        $this->shell    = $this->createMock(ShellExecutorInterface::class);
        $this->strategy = new WhoisFallbackStrategy($this->shell);
        $this->shell->method('commandExists')->with('whois')->willReturn(true);
    }

    #[Test]
    public function itSupportsAnyTld(): void
    {
        self::assertTrue($this->strategy->supports('hu'));
        self::assertTrue($this->strategy->supports('com'));
        self::assertTrue($this->strategy->supports('de'));
        self::assertTrue($this->strategy->supports('anything'));
    }

    #[Test]
    public function itParsesComStyleWhoisOutput(): void
    {
        $this->shell->method('execute')->willReturn($this->comWhoisOutput());

        $info = $this->strategy->query('example.com');

        self::assertSame('2024-08-13 04:00:00', $info->expirationDate->format('Y-m-d H:i:s'));
        self::assertSame('1995-08-14 04:00:00', $info->registrationDate->format('Y-m-d H:i:s'));
        self::assertSame('2023-08-14 07:01:31', $info->lastUpdated->format('Y-m-d H:i:s'));
        self::assertSame('RESERVED-Internet Assigned Numbers Authority', $info->registrar);
        self::assertContains('a.iana-servers.net', $info->nameservers);
        self::assertContains('b.iana-servers.net', $info->nameservers);
    }

    #[Test]
    public function itParsesAllRequiredKeysFromToArray(): void
    {
        $this->shell->method('execute')->willReturn($this->comWhoisOutput());

        $result = $this->strategy->query('example.com')->toArray();

        self::assertArrayHasKey('expiration_date',   $result);
        self::assertArrayHasKey('registration_date', $result);
        self::assertArrayHasKey('registrar',         $result);
        self::assertArrayHasKey('nameservers',        $result);
        self::assertArrayHasKey('last_updated',       $result);
    }

    #[Test]
    public function itParsesHuStyleWhoisOutput(): void
    {
        $this->shell->method('execute')->willReturn($this->huWhoisOutput());

        $info = $this->strategy->query('example.hu');

        self::assertNotNull($info->expirationDate);
        self::assertNotNull($info->registrationDate);
        self::assertNotNull($info->registrar);
        self::assertNotEmpty($info->nameservers);
    }

    #[Test]
    public function itDeduplicatesNameservers(): void
    {
        $output = <<<WHOIS
        Domain Name: DUPLICATE.COM
        Name Server: NS1.EXAMPLE.COM
        Name Server: NS1.EXAMPLE.COM
        Name Server: NS2.EXAMPLE.COM
        WHOIS;

        $this->shell->method('execute')->willReturn($output);

        $info = $this->strategy->query('duplicate.com');

        self::assertCount(2, $info->nameservers);
    }

    #[Test]
    public function itLowercasesNameservers(): void
    {
        $output = "Name Server: NS1.EXAMPLE.COM\nName Server: NS2.EXAMPLE.COM\n";
        $this->shell->method('execute')->willReturn($output);

        $info = $this->strategy->query('example.com');

        foreach ($info->nameservers as $ns) {
            self::assertSame(strtolower($ns), $ns);
        }
    }

    #[Test]
    #[DataProvider('expiryLabelProvider')]
    public function itRecognisesVariousExpiryLabels(string $label): void
    {
        $output = "{$label}: 2025-06-01T00:00:00Z\n";
        $this->shell->method('execute')->willReturn($output);

        $info = $this->strategy->query('example.com');

        self::assertNotNull($info->expirationDate, "Failed to parse expiry from label: '{$label}'");
        self::assertSame('2025-06-01', $info->expirationDate->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function expiryLabelProvider(): array
    {
        return [
            'registry expiry date' => ['Registry Expiry Date'],
            'expiry date'          => ['Expiry Date'],
            'expiration date'      => ['Expiration Date'],
            'expires'              => ['Expires'],
            'paid-till'            => ['paid-till'],
        ];
    }

    #[Test]
    #[DataProvider('nameserverLabelProvider')]
    public function itRecognisesVariousNameserverLabels(string $label): void
    {
        $output = "{$label}: ns1.example.com\n";
        $this->shell->method('execute')->willReturn($output);

        $info = $this->strategy->query('example.com');

        self::assertNotEmpty($info->nameservers, "Failed to parse nameserver from label: '{$label}'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nameserverLabelProvider(): array
    {
        return [
            'Name Server'  => ['Name Server'],
            'Nameserver'   => ['Nameserver'],
            'nserver'      => ['nserver'],
        ];
    }

    #[Test]
    public function itThrowsWhenWhoisCommandNotFound(): void
    {
        $shell = $this->createMock(ShellExecutorInterface::class);
        $shell->method('commandExists')->willReturn(false);

        $strategy = new WhoisFallbackStrategy($shell);

        $this->expectException(StrategyFailedException::class);
        $this->expectExceptionMessageMatches('/whois.*not found/i');

        $strategy->query('example.com');
    }

    #[Test]
    public function itThrowsWhenWhoisReturnsEmptyOutput(): void
    {
        $this->shell->method('execute')->willReturn('   ');

        $this->expectException(StrategyFailedException::class);
        $this->expectExceptionMessageMatches('/no output/i');

        $this->strategy->query('example.com');
    }

    #[Test]
    public function itThrowsWhenWhoisReturnsNull(): void
    {
        $this->shell->method('execute')->willReturn(null);

        $this->expectException(StrategyFailedException::class);

        $this->strategy->query('example.com');
    }

    #[Test]
    public function itThrowsWhenDomainNotFoundInWhois(): void
    {
        $this->shell->method('execute')->willReturn("No match for domain \"NOTEXIST.COM\".\n");

        $this->expectException(StrategyFailedException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->strategy->query('notexist.com');
    }

    #[Test]
    public function itReturnsNullFieldsWhenWhoisOutputHasNoStructuredData(): void
    {
        $output = "% This TLD has no public whois information.\n";
        $this->shell->method('execute')->willReturn($output);

        $info = $this->strategy->query('example.com');

        self::assertNull($info->expirationDate);
        self::assertNull($info->registrationDate);
        self::assertNull($info->registrar);
        self::assertEmpty($info->nameservers);
    }

    private function comWhoisOutput(): string
    {
        return <<<WHOIS
        Domain Name: EXAMPLE.COM
        Registry Domain ID: 2336799_DOMAIN_COM-VRSN
        Registrar WHOIS Server: whois.iana.org
        Registrar URL: http://res-dom.iana.org
        Updated Date: 2023-08-14T07:01:31Z
        Creation Date: 1995-08-14T04:00:00Z
        Registry Expiry Date: 2024-08-13T04:00:00Z
        Registrar: RESERVED-Internet Assigned Numbers Authority
        Registrar IANA ID: 376
        Name Server: A.IANA-SERVERS.NET
        Name Server: B.IANA-SERVERS.NET
        DNSSEC: signedDelegation
        WHOIS;
    }

    private function huWhoisOutput(): string
    {
        return <<<WHOIS
        domain:         example.hu
        registrar:      Example Registrar Kft.
        registered:     2020-05-10T08:00:00Z
        expires:        2025-05-10T08:00:00Z
        last-changed:   2023-03-15T14:22:00Z
        nserver:        ns1.example.hu
        nserver:        ns2.example.hu
        WHOIS;
    }
}