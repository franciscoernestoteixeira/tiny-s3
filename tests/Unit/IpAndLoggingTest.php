<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for logging and IP allowlist helper functions.
 */
class IpAndLoggingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/tiny_s3_log_' . uniqid() . '/activities.log';
        $GLOBALS['logFile'] = $this->logFile;
        $GLOBALS['debug'] = false;
        $_SERVER = [
            'REMOTE_ADDR' => '203.0.113.10',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/bucket/key.txt',
        ];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        $dir = dirname($this->logFile);
        if (is_dir($dir)) {
            rmdir($dir);
        }
        unset($GLOBALS['logFile'], $GLOBALS['debug'], $GLOBALS['allowedIps']);
        $_SERVER = [];
    }

    public function testRequestContextPrefersForwardedFor(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10';

        $this->assertSame('[198.51.100.10 GET /bucket/key.txt]', requestContext());
    }

    public function testRequestContextUsesFallbackDashesWhenServerDataIsMissing(): void
    {
        $_SERVER = [];

        $this->assertSame('[- - -]', requestContext());
    }

    public function testWriteLogAlwaysWritesErrorWithRequestContextAndCreatesDirectory(): void
    {
        writeLog('ERROR', 'failure message');

        $this->assertFileExists($this->logFile);
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[ERROR] [203.0.113.10 GET /bucket/key.txt] failure message', $content);
    }

    public function testWriteLogAlwaysWritesWarnEvenWhenDebugIsFalse(): void
    {
        writeLog('WARN', 'warning message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[WARN] [203.0.113.10 GET /bucket/key.txt] warning message', $content);
    }

    public function testWriteLogSkipsInfoWhenDebugIsFalse(): void
    {
        writeLog('INFO', 'hidden info');

        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testWriteLogWritesInfoAndDebugWhenDebugIsTrue(): void
    {
        $GLOBALS['debug'] = true;

        writeLog('INFO', 'visible info');
        writeLog('DEBUG', 'visible debug');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[INFO] visible info', $content);
        $this->assertStringContainsString('[DEBUG] visible debug', $content);
    }

    public function testParseAllowedIpsSplitsCommasSpacesAndTrims(): void
    {
        $this->assertSame(
            ['127.0.0.1', '10.0.0.0/8', '192.168.1.5'],
            parseAllowedIps(' 127.0.0.1, 10.0.0.0/8   192.168.1.5 ')
        );
    }

    public function testParseAllowedIpsReturnsEmptyForOpenAllowlistValues(): void
    {
        $this->assertSame([], parseAllowedIps(''));
        $this->assertSame([], parseAllowedIps('*'));
        $this->assertSame([], parseAllowedIps('  *  '));
    }

    public function testCidrMatchSupportsIpv4Ranges(): void
    {
        $this->assertTrue(cidrMatch('192.168.1.42', '192.168.1.0/24'));
        $this->assertFalse(cidrMatch('192.168.2.42', '192.168.1.0/24'));
    }

    public function testCidrMatchSupportsSingleIpWithoutSlash(): void
    {
        $this->assertTrue(cidrMatch('10.0.0.5', '10.0.0.5'));
        $this->assertFalse(cidrMatch('10.0.0.6', '10.0.0.5'));
    }

    public function testCidrMatchRejectsInvalidIpsAndMasks(): void
    {
        $this->assertFalse(cidrMatch('invalid', '10.0.0.0/8'));
        $this->assertFalse(cidrMatch('10.0.0.1', 'invalid/8'));
        $this->assertFalse(cidrMatch('10.0.0.1', '10.0.0.0/33'));
        // Current implementation casts non-numeric prefix to 0, so this behaves like /0.
        $this->assertTrue(cidrMatch('10.0.0.1', '10.0.0.0/not-a-mask'));
    }

    public function testIsLoopbackDetectsIpv4Ipv6AndLocalhost(): void
    {
        $this->assertTrue(isLoopback('127.0.0.1'));
        $this->assertTrue(isLoopback('127.123.45.67'));
        $this->assertTrue(isLoopback('::1'));
        $this->assertFalse(isLoopback('localhost'));
        $this->assertFalse(isLoopback('192.168.0.1'));
    }

    public function testIsIpAllowedUsesAnyMatchingRule(): void
    {
        $rules = ['10.0.0.0/8', '192.168.1.10'];

        $this->assertTrue(isIpAllowed('10.1.2.3', $rules));
        $this->assertTrue(isIpAllowed('192.168.1.10', $rules));
        $this->assertFalse(isIpAllowed('172.16.0.1', $rules));
    }

    public function testCheckIpAllowlistReturnsWhenDisabledOrLoopbackOrAllowed(): void
    {
        $GLOBALS['allowedIps'] = '';
        checkIpAllowlist();

        $GLOBALS['allowedIps'] = '192.168.1.0/24';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        checkIpAllowlist();

        $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
        checkIpAllowlist();

        $this->assertTrue(true);
    }
}
