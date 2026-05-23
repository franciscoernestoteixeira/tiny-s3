<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for bucket listing helper functions.
 */
class ListingHelpersTest extends TestCase
{
    public function testFilterListedObjectsSortsWhenPrefixIsEmpty(): void
    {
        $this->assertSame(['a.txt', 'b.txt', 'dir/c.txt'], filterListedObjects(['b.txt', 'dir/c.txt', 'a.txt'], ''));
    }

    public function testFilterListedObjectsAppliesPrefixAfterSorting(): void
    {
        $this->assertSame(['photos/a.jpg', 'photos/b.jpg'], filterListedObjects(['z.txt', 'photos/b.jpg', 'photos/a.jpg'], 'photos/'));
    }

    public function testBuildDelimitedListingWithoutDelimiterReturnsContentsOnly(): void
    {
        $result = buildDelimitedListing(['root.txt', 'photos/a.jpg', 'photos/nested/b.jpg'], '', '');

        $this->assertSame(['photos/a.jpg', 'photos/nested/b.jpg', 'root.txt'], $result['contents']);
        $this->assertSame([], $result['commonPrefixes']);
    }

    public function testBuildDelimitedListingGroupsCommonPrefixes(): void
    {
        $result = buildDelimitedListing(['root.txt', 'photos/a.jpg', 'photos/nested/b.jpg', 'docs/readme.txt'], '', '/');

        $this->assertSame(['root.txt'], $result['contents']);
        $this->assertSame(['docs/', 'photos/'], $result['commonPrefixes']);
    }

    public function testBuildDelimitedListingHonorsPrefixBeforeDelimiterGrouping(): void
    {
        $result = buildDelimitedListing(['photos/a.jpg', 'photos/nested/b.jpg', 'videos/a.mp4'], 'photos/', '/');

        $this->assertSame(['photos/a.jpg'], $result['contents']);
        $this->assertSame(['photos/nested/'], $result['commonPrefixes']);
    }

    public function testHasS3SubresourceIsCaseInsensitiveAndRequiresExistingKey(): void
    {
        $this->assertTrue(hasS3Subresource(['Location' => ''], 'location'));
        $this->assertTrue(hasS3Subresource(['uploads' => null], 'UPLOADS'));
        $this->assertFalse(hasS3Subresource(['prefix' => 'photos/'], 'location'));
    }
}
