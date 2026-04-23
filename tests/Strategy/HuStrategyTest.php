<?php

declare(strict_types=1);

namespace SPeter81\DomainInfo\Tests\Strategy;

use SPeter81\DomainInfo\Contract\HttpClientInterface;
use SPeter81\DomainInfo\Exception\HttpException;
use SPeter81\DomainInfo\Exception\StrategyFailedException;
use SPeter81\DomainInfo\Strategy\HuStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HuStrategyTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private HuStrategy $strategy;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->strategy   = new HuStrategy($this->httpClient);
    }

    #[Test]
    public function itSupportsHuTld(): void
    {
        self::assertTrue($this->strategy->supports('hu'));
        self::assertTrue($this->strategy->supports('HU'));
    }

    #[Test]
    public function itDoesNotSupportOtherTlds(): void
    {
        self::assertFalse($this->strategy->supports('com'));
        self::assertFalse($this->strategy->supports('de'));
        self::assertFalse($this->strategy->supports('co'));
    }

    #[Test]
    public function itParsesExpirationDateFromHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $info = $this->strategy->query('example.hu');

        self::assertNotNull($info->expirationDate);
        self::assertSame('2028-03-14 00:00:00', $info->expirationDate->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itParsesRegistrationDateFromHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $info = $this->strategy->query('example.hu');

        self::assertNotNull($info->registrationDate);
        self::assertSame('2016-01-08 12:17:56', $info->registrationDate->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itParsesRegistrarFromHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $info = $this->strategy->query('example.hu');

        self::assertSame('Example Registrar Kft.', $info->registrar);
    }

    #[Test]
    public function itParsesNameserversFromHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $info = $this->strategy->query('example.hu');

        self::assertCount(3, $info->nameservers);
        self::assertContains('ns1.versanus.hu', $info->nameservers);
        self::assertContains('ns2.versanus.hu', $info->nameservers);
        self::assertContains('ns3.versanus.hu', $info->nameservers);
    }

    /*
     * Jelenleg nincs last updated domain info :(
    #[Test]
    public function itParsesLastUpdatedFromHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $info = $this->strategy->query('example.hu');
        print_r($info);

        self::assertNotNull($info->lastUpdated);
        self::assertSame('2023-03-15 14:22:00', $info->lastUpdated->format('Y-m-d H:i:s'));
    }
    */

    #[Test]
    public function itReturnsAllFieldsInToArray(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));

        $result = $this->strategy->query('example.hu')->toArray();

        self::assertArrayHasKey('expiration_date',   $result);
        self::assertArrayHasKey('registration_date', $result);
        self::assertArrayHasKey('registrar',         $result);
        self::assertArrayHasKey('nameservers',        $result);
        self::assertArrayHasKey('last_updated',       $result);
    }

    // -------------------------------------------------------------------------
    // query() — error paths
    // -------------------------------------------------------------------------

    #[Test]
    public function itThrowsStrategyFailedExceptionOnHttpError(): void
    {
        $this->httpClient
            ->method('post')
            ->willThrowException(new HttpException('Connection timed out'));

        $this->expectException(StrategyFailedException::class);
        $this->expectExceptionMessageMatches('/Connection timed out/');

        $this->strategy->query('example.hu');
    }

    #[Test]
    public function itThrowsStrategyFailedExceptionOnEmptyResponse(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn('');

        $this->expectException(StrategyFailedException::class);

        $this->strategy->query('example.hu');
    }

    #[Test]
    public function itReturnsNullDatesOnUnparsableHtml(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn('<html><body><p>No data here</p></body></html>');

        $info = $this->strategy->query('example.hu');

        self::assertNull($info->expirationDate);
        self::assertNull($info->registrationDate);
        self::assertNull($info->registrar);
        self::assertEmpty($info->nameservers);
    }

    #[Test]
    public function itParsesHungarianDateWithoutTime(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn($this->loadFixture('hu_response.html'));
        $info = $this->strategy->query('example.hu');

        self::assertNotNull($info->expirationDate);
        self::assertSame('2028-03-14', $info->expirationDate->format('Y-m-d'));
    }

    #[Test]
    public function itConstructsCorrectFetchUrl(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with('https://info.domain.hu/webwhois/hu/domain/example.hu')
            ->willReturn($this->loadFixture('hu_response.html'));

        $this->strategy->query('example.hu');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loadFixture(string $filename): string
    {
        $path = __DIR__ . '/../Fixtures/' . $filename;
        self::assertFileExists($path, "Fixture file not found: {$path}");
        return (string) file_get_contents($path);
    }

    private function buildHtmlRow(string $label, string $value): string
    {
        return <<<HTML
        <!DOCTYPE html><html><body>
        <table>
          <tr><th>{$label}</th><td>{$value}</td></tr>
        </table>
        </body></html>
        HTML;
    }
}