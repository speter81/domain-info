<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Tests;

use DateTimeImmutable;
use SPeter81\DomainInfo\Contract\DomainStrategyInterface;
use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\Contract\ShellExecutorInterface;
use SPeter81\DomainInfo\DomainInfoService;
use SPeter81\DomainInfo\DTO\DomainInfo;
use SPeter81\DomainInfo\Exception\DomainInfoException;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use SPeter81\DomainInfo\Resolver\StrategyResolver;
use SPeter81\DomainInfo\Strategy\HuStrategy;
use SPeter81\DomainInfo\Strategy\RdapStrategy;
use SPeter81\DomainInfo\Strategy\WhoisFallbackStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DomainInfoServiceTest extends TestCase
{
    private function makeDomainInfo(
        string $domainName    = 'example.com',
        string $expiry        = '2025-01-01 00:00:00',
        string $created       = '2020-01-01 00:00:00',
        string $registrar     = 'Test Registrar Ltd.',
        array  $nameservers   = ['ns1.test.com', 'ns2.test.com'],
        string $lastUpdated   = '2023-06-15 12:00:00',
    ): DomainInfo {
        return new DomainInfo(
            domainName:       $domainName,
            expirationDate:   new DateTimeImmutable($expiry),
            registrationDate: new DateTimeImmutable($created),
            registrar:        $registrar,
            nameservers:      $nameservers,
            lastUpdated:      new DateTimeImmutable($lastUpdated),
        );
    }

    private function makeStrategy(
        string $tld,
        DomainInfo $returnValue,
    ): DomainStrategyInterface&MockObject {
        $strategy = $this->createMock(DomainStrategyInterface::class);
        $strategy->method('supports')->willReturnCallback(
            fn(string $t) => strtolower($t) === $tld
        );
        $strategy->method('query')->willReturn($returnValue);
        return $strategy;
    }

    private function makeFailingStrategy(string $tld): DomainStrategyInterface|MockObject
    {
        $strategy = $this->createMock(DomainStrategyInterface::class);
        $strategy->method('supports')->willReturnCallback(
            fn(string $t) => strtolower($t) === $tld
        );
        $strategy->method('query')->willThrowException(
            new StrategyFailedException('Primary strategy intentionally failed')
        );
        return $strategy;
    }

    private function makeWhoisFallback(
        DomainInfo $result,
        bool $shouldFail = false
    ): WhoisFallbackStrategy {
        $mockShell    = $this->createMock(ShellExecutorInterface::class);
        $flatArray = self::arrayFlattenRecursive($result->toArray());
        $returnValue = implode("\n", $flatArray);
        $mockShell->method('execute')->willReturn($returnValue);
        $mockShell->method('commandExists')->with('whois')->willReturn(! $shouldFail);
        $mock = new WhoisFallbackStrategy($mockShell);
        return $mock;
    }

    private function buildService(
        StrategyResolver $resolver,
        DomainStrategyInterface $whoisFallback,
    ): DomainInfoService {
        return new DomainInfoService($resolver, $whoisFallback);
    }

    #[Test]
    public function lookupReturnsArrayWithAllExpectedKeys(): void
    {
        $resolver = new StrategyResolver();
        $domainInfo = $this->makeDomainInfo();
        $resolver->register($this->makeStrategy('com', $domainInfo));

        $service = $this->buildService(
            $resolver,
            $this->makeWhoisFallback($domainInfo),
        );

        $result = $service->lookup('example.com');

        self::assertArrayHasKey('expiration_date',   $result);
        self::assertArrayHasKey('registration_date', $result);
        self::assertArrayHasKey('registrar',         $result);
        self::assertArrayHasKey('nameservers',        $result);
        self::assertArrayHasKey('last_updated',       $result);
    }

    #[Test]
    public function lookupReturnsDatesAsFormattedStrings(): void
    {
        $resolver = new StrategyResolver();
        $domainInfo = $this->makeDomainInfo(
            expiry:  '2025-12-31 00:00:00',
            created: '2020-03-01 09:30:00',
        );
        $resolver->register($this->makeStrategy('com', $domainInfo));

        $service = $this->buildService($resolver, $this->makeWhoisFallback($domainInfo));
        $result  = $service->lookup('example.com');

        self::assertSame('2025-12-31 00:00:00', $result['expiration_date']);
        self::assertSame('2020-03-01 09:30:00', $result['registration_date']);
    }

    #[Test]
    public function lookupReturnsNameserversAsList(): void
    {
        $resolver = new StrategyResolver();
        $domainInfo = $this->makeDomainInfo(
            nameservers: ['ns1.example.com', 'ns2.example.com'],
        );
        $resolver->register($this->makeStrategy('com', $domainInfo));

        $service = $this->buildService($resolver, $this->makeWhoisFallback($domainInfo));
        $result  = $service->lookup('example.com');

        self::assertIsArray($result['nameservers']);
        self::assertContains('ns1.example.com', $result['nameservers']);
        self::assertContains('ns2.example.com', $result['nameservers']);
    }

    #[Test]
    public function lookupPreservesNullFieldsFromStrategy(): void
    {
        $info     = new DomainInfo(); // all nulls
        $resolver = new StrategyResolver();
        $resolver->register($this->makeStrategy('com', $info));

        $service = $this->buildService($resolver, $this->makeWhoisFallback($info));
        $result  = $service->lookup('example.com');

        self::assertNull($result['expiration_date']);
        self::assertNull($result['registration_date']);
        self::assertNull($result['registrar']);
        self::assertNull($result['last_updated']);
        self::assertSame([], $result['nameservers']);
    }

    #[Test]
    public function lookupUsesRegisteredStrategyForMatchingTld(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $fixture = $this->loadFixture('hu_response.html');
        $mockHttpClient->method('post')->willReturn($fixture);
        $strategy = new HuStrategy($mockHttpClient);
        $resolver = new StrategyResolver();
        $resolver->register($strategy);

        $service = $this->buildService($resolver, $this->makeWhoisFallback($this->makeDomainInfo()));
        $info = $service->lookup('example.hu');
        self::assertNotNull($info['registrar'], 'registrar info not found in response');
        self::assertSame('Example Registrar Kft.', $info['registrar']);
    }

    #[Test]
    public function lookupNormalisesInputToLowercase(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockHttpClient->method('get')->willReturn(
            json_encode(['domain' => 'EXAMPLE.COM'])
        );
        $strategy = new RdapStrategy($mockHttpClient);

        $resolver = new StrategyResolver();
        $resolver->register($strategy);

        $service = $this->buildService($resolver, $this->makeWhoisFallback($this->makeDomainInfo()));
        $result = $service->lookup('  EXAMPLE.COM  ');
        self::assertIsArray($result);
    }

    #[Test]
    public function lookupFallsBackToWhoisWhenPrimaryStrategyFails(): void
    {
        $resolver = new StrategyResolver();
        $resolver->register($this->makeFailingStrategy('com'));

        $fallbackInfo    = $this->makeDomainInfo(registrar: 'Registrar: Whois Registrar');
        $whoisFallback   = $this->makeWhoisFallback($fallbackInfo);

        $service = $this->buildService($resolver, $whoisFallback);
        $result  = $service->lookup('example.com');
        self::assertSame('Whois Registrar', $result['registrar']);
    }

    #[Test]
    public function lookupFallsBackToWhoisWhenNoStrategyForTld(): void
    {
        $resolver        = new StrategyResolver();
        $fallbackInfo    = $this->makeDomainInfo(registrar: 'Whois Registrar');
        // This fallback will trigger the `whois` command not found exception
        $whoisFallback   = $this->makeWhoisFallback($fallbackInfo,true);

        $service = $this->buildService($resolver, $whoisFallback);
        self::expectExceptionMessageMatches('/command not found/');
        $service->lookup('example.xyz');
    }

    #[Test]
    public function lookupThrowsDomainInfoExceptionWhenAllStrategiesExhausted(): void
    {
        $resolver = new StrategyResolver();
        $failingStrategy = $this->makeFailingStrategy('com');
        $resolver->register($failingStrategy);

        $service = $this->buildService(
            $resolver,
            $failingStrategy
        );

        $this->expectException(DomainInfoException::class);
        $this->expectExceptionMessageMatches('/All strategies exhausted/i');

        $service->lookup('example.com');
    }

    #[Test]
    public function lookupExceptionChainPreservesPrimaryStrategyFailure(): void
    {
        $primaryException = new StrategyFailedException('Primary failed first');

        $strategy = $this->createMock(DomainStrategyInterface::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('query')->willThrowException($primaryException);

        $resolver = new StrategyResolver();
        $resolver->register($strategy);

        $service = $this->buildService(
            $resolver,
            $this->makeWhoisFallback($this->makeDomainInfo(), true)
        );

        try {
            $service->lookup('example.com');
            self::fail('DomainInfoException expected for this lookup');
        } catch (DomainInfoException $e) {
            self::assertSame($primaryException, $e->getPrevious());
        }
    }

    #[Test]
    public function lookupThrowsOnEmptyDomain(): void
    {
        $service = DomainInfoService::create();

        $this->expectException(DomainInfoException::class);

        $service->lookup('');
    }

    #[Test]
    #[DataProvider('invalidDomainProvider')]
    public function lookupThrowsOnInvalidDomain(string $domain): void
    {
        $service = DomainInfoService::create();

        $this->expectException(DomainInfoException::class);

        $service->lookup($domain);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDomainProvider(): array
    {
        return [
            'no TLD'              => ['example'],
            'leading dot'         => ['.example.com'],
            'trailing dot'        => ['example.com.'],
            'spaces inside'       => ['exa mple.com'],
            'double dot'          => ['example..com'],
            'starts with hyphen'  => ['-example.com'],
            'TLD too short'       => ['example.c'],
        ];
    }

    #[Test]
    #[DataProvider('validDomainProvider')]
    public function lookupAcceptsValidDomains(string $domain): void
    {
        $strategy = $this->createMock(DomainStrategyInterface::class);
        $strategy->method('supports')->willReturn(true);
        $domainInfo = $this->makeDomainInfo();
        $strategy->method('query')->willReturn($domainInfo);

        $resolver = new StrategyResolver();
        $resolver->register($strategy);

        $service = $this->buildService($resolver, $this->makeWhoisFallback($domainInfo));

        // Should not throw
        $result = $service->lookup($domain);
        self::assertIsArray($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDomainProvider(): array
    {
        return [
            'simple .com'        => ['example.com'],
            'simple .hu'         => ['example.hu'],
            'subdomain'          => ['sub.example.com'],
            'uppercase input'    => ['EXAMPLE.COM'],
            'numeric label'      => ['123.example.com'],
            'hyphen in label'    => ['my-domain.com'],
            'puny code required' => ['münchen.de'],
            'more idna domains'  => ['fußball.de'],
            'puny-coded input'   => ['xn--mnchen-3ya.de']
        ];
    }

    #[Test]
    public function createFactoryReturnsServiceInstance(): void
    {
        $service = DomainInfoService::create();
        self::assertInstanceOf(DomainInfoService::class, $service);
    }

    #[Test]
    public function createFactoryAcceptsCustomHttpClientAndShellExecutor(): void
    {
        $shell = $this->createMock(ShellExecutorInterface::class);

        // Should not throw
        $service = DomainInfoService::create(shellExecutor: $shell);
        self::assertInstanceOf(DomainInfoService::class, $service);
    }

    private function loadFixture(string $filename): string
    {
        $path = __DIR__ . '/Fixtures/' . $filename;
        self::assertFileExists($path, "Fixture file not found: {$path}");
        return (string) file_get_contents($path);
    }

    private function arrayFlattenRecursive($array): array {
        $result = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $result = array_merge($result,self::arrayFlattenRecursive($item));
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }
}