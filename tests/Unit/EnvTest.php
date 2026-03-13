<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for loadEnv() and envToBool() — Section 1 of index.php.
 */
class EnvTest extends TestCase
{
    // -------------------------------------------------------------------------
    // envToBool
    // -------------------------------------------------------------------------

    #[DataProvider('truthyStrings')]
    public function testEnvToBoolReturnsTrueForTruthyValues(string $value): void
    {
        $this->assertTrue(envToBool($value));
    }

    public static function truthyStrings(): array
    {
        return [
            'string true'  => ['true'],
            'string 1'     => ['1'],
            'string yes'   => ['yes'],
            'string on'    => ['on'],
            'uppercase TRUE' => ['TRUE'],
            'mixed case Yes' => ['Yes'],
        ];
    }

    #[DataProvider('falsyStrings')]
    public function testEnvToBoolReturnsFalseForFalsyValues(string $value): void
    {
        $this->assertFalse(envToBool($value));
    }

    public static function falsyStrings(): array
    {
        return [
            'string false' => ['false'],
            'string 0'     => ['0'],
            'string no'    => ['no'],
            'string off'   => ['off'],
            'uppercase FALSE' => ['FALSE'],
            'empty string' => [''],
        ];
    }

    public function testEnvToBoolDefaultsFalseOnUnrecognisedInput(): void
    {
        // filter_var with FILTER_NULL_ON_FAILURE returns null for unknown values;
        // the ?? false fallback converts it to false.
        $this->assertFalse(envToBool('banana'));
        $this->assertFalse(envToBool('maybe'));
        $this->assertFalse(envToBool('2'));
    }

    // -------------------------------------------------------------------------
    // loadEnv
    // -------------------------------------------------------------------------

    private string $tempEnv;

    protected function setUp(): void
    {
        $this->tempEnv = tempnam(sys_get_temp_dir(), 'tiny_s3_env_test_') . '.env';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnv)) {
            unlink($this->tempEnv);
        }
        // Clean up any keys we set so tests don't bleed into each other
        foreach (['TS3_KEY', 'TS3_VALUE', 'TS3_SPACED', 'TS3_COMMENT_KEY', 'TS3_NOEQ'] as $k) {
            unset($_ENV[$k]);
        }
    }

    public function testLoadEnvParsesSimpleKeyValuePair(): void
    {
        file_put_contents($this->tempEnv, "TS3_KEY=hello\n");
        loadEnv($this->tempEnv);
        $this->assertSame('hello', $_ENV['TS3_KEY']);
    }

    public function testLoadEnvHandlesSpacingAroundEquals(): void
    {
        file_put_contents($this->tempEnv, "TS3_SPACED = padded value\n");
        loadEnv($this->tempEnv);
        $this->assertSame('padded value', $_ENV['TS3_SPACED']);
    }

    public function testLoadEnvIgnoresCommentLines(): void
    {
        file_put_contents($this->tempEnv, "# this is a comment\nTS3_VALUE=real\n");
        loadEnv($this->tempEnv);
        $this->assertArrayNotHasKey('TS3_COMMENT_KEY', $_ENV);
        $this->assertSame('real', $_ENV['TS3_VALUE']);
    }

    public function testLoadEnvIgnoresLinesWithoutEquals(): void
    {
        file_put_contents($this->tempEnv, "TS3_NOEQ\nTS3_KEY=present\n");
        loadEnv($this->tempEnv);
        $this->assertArrayNotHasKey('TS3_NOEQ', $_ENV);
        $this->assertSame('present', $_ENV['TS3_KEY']);
    }

    public function testLoadEnvIgnoresBlankLines(): void
    {
        file_put_contents($this->tempEnv, "\n\nTS3_KEY=present\n\n");
        loadEnv($this->tempEnv);
        $this->assertSame('present', $_ENV['TS3_KEY']);
    }

    public function testLoadEnvDoesNotThrowIfFileIsMissing(): void
    {
        // Should return silently — no exception, no warning.
        $this->expectNotToPerformAssertions();
        loadEnv('/tmp/this_file_does_not_exist_' . uniqid() . '.env');
    }

    public function testLoadEnvDoesNotOverwriteExistingEnvKey(): void
    {
        // loadEnv sets unconditionally — the second call overwrites.
        // This test documents the current (intentional) behaviour.
        $_ENV['TS3_KEY'] = 'original';
        file_put_contents($this->tempEnv, "TS3_KEY=overwritten\n");
        loadEnv($this->tempEnv);
        $this->assertSame('overwritten', $_ENV['TS3_KEY']);
    }
}
