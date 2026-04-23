<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Tests\Strategy;

use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\Exception\HttpException;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use SPeter81\DomainInfo\Strategy\RdapStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RdapStrategyTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private RdapStrategy $strategy;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->strategy   = new RdapStrategy($this->httpClient);
    }

    #[Test]
    public function itSupportsComTld(): void
    {
        self::assertTrue($this->strategy->supports('com'));
        self::assertTrue($this->strategy->supports('COM'));
    }

    #[Test]
    public function itSupportsNetAndOrgTlds(): void
    {
        self::assertTrue($this->strategy->supports('net'));
        self::assertTrue($this->strategy->supports('org'));
    }

    #[Test]
    public function itDoesNotSupportHuTld(): void
    {
        self::assertFalse($this->strategy->supports('hu'));
        self::assertFalse($this->strategy->supports('de'));
    }

    #[Test]
    public function itParsesExpirationDateFromRdapJson(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('example.com');

        self::assertNotNull($info->expirationDate);
        self::assertSame('2028-09-14', $info->expirationDate->format('Y-m-d'));
    }

    #[Test]
    public function itParsesRegistrationDateFromRdapJson(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('example.com');

        self::assertNotNull($info->registrationDate);
        self::assertSame('1997-09-15', $info->registrationDate->format('Y-m-d'));
    }

    #[Test]
    public function itParsesLastUpdatedFromRdapJson(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('example.com');

        self::assertNotNull($info->lastUpdated);
        self::assertSame('2019-09-09', $info->lastUpdated->format('Y-m-d'));
    }

    #[Test]
    public function itParsesRegistrarNameFromVcardArray(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('example.com');

        self::assertSame('MarkMonitor Inc.', $info->registrar);
    }

    #[Test]
    public function itParsesNameserversFromRdapJson(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('google.com');

        self::assertCount(4, $info->nameservers);
        self::assertContains('ns1.google.com', $info->nameservers);
        self::assertContains('ns2.google.com', $info->nameservers);
        self::assertContains('ns3.google.com', $info->nameservers);
        self::assertContains('ns4.google.com', $info->nameservers);
    }

    #[Test]
    public function nameserversAreLowercased(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $info = $this->strategy->query('example.com');

        foreach ($info->nameservers as $ns) {
            self::assertSame(strtolower($ns), $ns, "Nameserver '{$ns}' should be lowercase");
        }
    }

    #[Test]
    public function itReturnsAllExpectedKeysInToArray(): void
    {
        $this->httpClient->method('get')->willReturn($this->loadFixture('rdap_response.json'));

        $result = $this->strategy->query('example.com')->toArray();

        self::assertArrayHasKey('expiration_date',   $result);
        self::assertArrayHasKey('registration_date', $result);
        self::assertArrayHasKey('registrar',         $result);
        self::assertArrayHasKey('nameservers',        $result);
        self::assertArrayHasKey('last_updated',       $result);
    }

    #[Test]
    public function itFallsBackToPublicIdWhenVcardFnIsMissing(): void
    {
        $json = json_encode([
            'events'      => [],
            'nameservers' => [],
            'entities'    => [
                [
                    'roles'     => ['registrar'],
                    'publicIds' => [['type' => 'IANA Registrar ID', 'identifier' => '9999']],
                    'vcardArray' => ['vcard', [['version', [], 'text', '4.0']]],
                ],
            ],
        ]);

        $this->httpClient->method('get')->willReturn($json);

        $info = $this->strategy->query('example.com');

        self::assertSame('9999', $info->registrar);
    }

    #[Test]
    public function itFallsBackToHandleWhenNoVcardOrPublicId(): void
    {
        $json = json_encode([
            'events'      => [],
            'nameservers' => [],
            'entities'    => [
                [
                    'roles'      => ['registrar'],
                    'handle'     => 'REGISTRAR-HANDLE',
                    'vcardArray' => [],
                ],
            ],
        ]);

        $this->httpClient->method('get')->willReturn($json);

        $info = $this->strategy->query('example.com');

        self::assertSame('REGISTRAR-HANDLE', $info->registrar);
    }

    #[Test]
    public function itReturnsNullRegistrarWhenNoRegistrarEntity(): void
    {
        $json = json_encode([
            'events'      => [],
            'nameservers' => [],
            'entities'    => [
                ['roles' => ['administrative'], 'vcardArray' => []],
            ],
        ]);

        $this->httpClient->method('get')->willReturn($json);

        $info = $this->strategy->query('example.com');

        self::assertNull($info->registrar);
    }

    #[Test]
    public function itThrowsStrategyFailedExceptionOnHttpError(): void
    {
        $this->httpClient
            ->method('get')
            ->willThrowException(new HttpException('DNS resolution failed'));

        $this->expectException(StrategyFailedException::class);
        $this->expectExceptionMessageMatches('/DNS resolution failed/');

        $this->strategy->query('example.com');
    }

    #[Test]
    public function itThrowsStrategyFailedExceptionOnInvalidJson(): void
    {
        $this->httpClient->method('get')->willReturn('this is not json {{{{');

        $this->expectException(StrategyFailedException::class);

        $this->strategy->query('example.com');
    }

    #[Test]
    public function itHandlesEmptyEventsGracefully(): void
    {
        $json = json_encode(['events' => [], 'nameservers' => [], 'entities' => []]);
        $this->httpClient->method('get')->willReturn($json);

        $info = $this->strategy->query('example.com');

        self::assertNull($info->expirationDate);
        self::assertNull($info->registrationDate);
        self::assertNull($info->lastUpdated);
        self::assertNull($info->registrar);
        self::assertEmpty($info->nameservers);
    }

    #[Test]
    public function itSkipsMalformedEventDates(): void
    {
        $json = json_encode([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => 'NOT-A-DATE'],
                ['eventAction' => 'expiration',   'eventDate' => '2025-01-01T00:00:00Z'],
            ],
            'nameservers' => [],
            'entities'    => [],
        ]);
        $this->httpClient->method('get')->willReturn($json);

        $info = $this->strategy->query('example.com');

        self::assertNull($info->registrationDate);
        self::assertNotNull($info->expirationDate);
    }

    #[Test]
    public function itConstructsCorrectFetchUrl(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('get')
            ->with('https://rdap.org/domain/example.com')
            ->willReturn($this->loadFixture('rdap_response.json'));

        $this->strategy->query('example.com');
    }

    private function loadFixture(string $filename): string
    {
        $path = __DIR__ . '/../Fixtures/' . $filename;
        self::assertFileExists($path, "Fixture not found: {$path}");
        return (string) file_get_contents($path);
    }
}