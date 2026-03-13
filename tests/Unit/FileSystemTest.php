<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for listObjectsRecursively() and deleteDirectoryRecursive() — Sections 8 & 9.
 *
 * Each test creates a fresh temporary directory, runs the function under test,
 * then verifies the filesystem state. tearDown always removes any leftover
 * directories so a failed assertion never pollutes subsequent tests.
 */
class FileSystemTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tiny_s3_fs_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup — deleteDirectoryRecursive is the subject under test
        // here, so teardown uses an independent pure-PHP helper instead of relying
        // on the SUT or any shell command (exec/rm do not exist on Windows).
        static::deleteDir($this->tempDir);
    }

    /**
     * Cross-platform recursive directory removal used exclusively for test cleanup.
     * Intentionally separate from deleteDirectoryRecursive() (the SUT).
     */
    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? static::deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // listObjectsRecursively
    // -------------------------------------------------------------------------

    public function testListObjectsRecursivelyReturnsEmptyArrayForEmptyDir(): void
    {
        $keys = listObjectsRecursively($this->tempDir);
        $this->assertSame([], $keys);
    }

    public function testListObjectsRecursivelyReturnsFlatFiles(): void
    {
        file_put_contents($this->tempDir . '/alpha.txt', 'a');
        file_put_contents($this->tempDir . '/beta.txt',  'b');

        $keys = listObjectsRecursively($this->tempDir);
        sort($keys);

        $this->assertSame(['alpha.txt', 'beta.txt'], $keys);
    }

    public function testListObjectsRecursivelyIncludesFilesInSubdirs(): void
    {
        mkdir($this->tempDir . '/2024', 0755);
        file_put_contents($this->tempDir . '/2024/january.csv',  'jan');
        file_put_contents($this->tempDir . '/2024/february.csv', 'feb');

        $keys = listObjectsRecursively($this->tempDir);
        sort($keys);

        $this->assertSame(['2024/february.csv', '2024/january.csv'], $keys);
    }

    public function testListObjectsRecursivelyHandlesDeeplyNestedPaths(): void
    {
        mkdir($this->tempDir . '/a/b/c', 0755, true);
        file_put_contents($this->tempDir . '/a/b/c/deep.txt', 'deep');

        $keys = listObjectsRecursively($this->tempDir);
        $this->assertSame(['a/b/c/deep.txt'], $keys);
    }

    public function testListObjectsRecursivelyMixesFlatAndNestedFiles(): void
    {
        file_put_contents($this->tempDir . '/root.txt', 'root');
        mkdir($this->tempDir . '/sub', 0755);
        file_put_contents($this->tempDir . '/sub/child.txt', 'child');

        $keys = listObjectsRecursively($this->tempDir);
        sort($keys);

        $this->assertSame(['root.txt', 'sub/child.txt'], $keys);
    }

    public function testListObjectsRecursivelyPrefixAddsTrailingSlash(): void
    {
        // When called recursively with a sub-directory prefix, the S3 convention
        // is that virtual directory prefixes end in `/`. Verify the function
        // honours that by inspecting a two-level structure.
        mkdir($this->tempDir . '/photos', 0755);
        file_put_contents($this->tempDir . '/photos/beach.jpg', 'bytes');

        $keys = listObjectsRecursively($this->tempDir);
        $this->assertContains('photos/beach.jpg', $keys);
        // The key must not contain a bare `photos` entry — directory names are not keys
        $this->assertNotContains('photos', $keys);
    }

    // -------------------------------------------------------------------------
    // deleteDirectoryRecursive
    // -------------------------------------------------------------------------

    public function testDeleteDirectoryRecursiveRemovesEmptyDirectory(): void
    {
        $dir = $this->tempDir . '/empty';
        mkdir($dir);

        $result = deleteDirectoryRecursive($dir);

        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryRecursiveRemovesFlatFiles(): void
    {
        $dir = $this->tempDir . '/flat';
        mkdir($dir);
        file_put_contents($dir . '/a.txt', 'a');
        file_put_contents($dir . '/b.txt', 'b');

        $result = deleteDirectoryRecursive($dir);

        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryRecursiveRemovesNestedDirectories(): void
    {
        $dir = $this->tempDir . '/nested';
        mkdir($dir . '/sub/deep', 0755, true);
        file_put_contents($dir . '/sub/deep/file.txt', 'content');
        file_put_contents($dir . '/root.txt', 'root');

        $result = deleteDirectoryRecursive($dir);

        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryRecursiveDoesNotTouchSiblingDirectories(): void
    {
        $target  = $this->tempDir . '/to-delete';
        $sibling = $this->tempDir . '/keep-me';

        mkdir($target);
        mkdir($sibling);
        file_put_contents($sibling . '/precious.txt', 'do not touch');

        deleteDirectoryRecursive($target);

        $this->assertDirectoryDoesNotExist($target);
        $this->assertDirectoryExists($sibling);
        $this->assertFileExists($sibling . '/precious.txt');
    }
}
