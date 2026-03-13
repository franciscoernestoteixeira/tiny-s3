<?php
declare(strict_types=1);

namespace TinyS3\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for xmlElement() — Section 3 of index.php.
 */
class XmlTest extends TestCase
{
    public function testXmlElementProducesOpenAndCloseTag(): void
    {
        $this->assertSame('<Key>photo.jpg</Key>', xmlElement('Key', 'photo.jpg'));
    }

    public function testXmlElementProducesCorrectStructureForNestedKey(): void
    {
        $this->assertSame(
            '<Key>backups/2024/db.sql.gz</Key>',
            xmlElement('Key', 'backups/2024/db.sql.gz')
        );
    }

    public function testXmlElementEscapesAmpersand(): void
    {
        $this->assertSame(
            '<Message>bread &amp; butter</Message>',
            xmlElement('Message', 'bread & butter')
        );
    }

    public function testXmlElementEscapesLessThanAndGreaterThan(): void
    {
        $this->assertSame('<Code>&lt;XSS&gt;</Code>', xmlElement('Code', '<XSS>'));
    }

    public function testXmlElementLeavesDoubleQuoteUnescapedInTextContent(): void
    {
        // In XML text content, double quotes do not need to be escaped — only `&`
        // and `<` are strictly required. ENT_XML1 correctly leaves `"` as-is inside
        // text nodes; escaping is only mandatory in attribute values, which
        // xmlElement never emits.
        $this->assertSame('<Name>say "hello"</Name>', xmlElement('Name', 'say "hello"'));
    }

    public function testXmlElementWithEmptyContent(): void
    {
        $this->assertSame('<Location></Location>', xmlElement('Location', ''));
    }

    public function testXmlElementTagNameIsNotEscaped(): void
    {
        // The tag comes from trusted internal code, not user input.
        // This test documents the assumption that the tag name is always safe.
        $result = xmlElement('CreateBucketResult', '/my-bucket');
        $this->assertStringStartsWith('<CreateBucketResult>', $result);
        $this->assertStringEndsWith('</CreateBucketResult>', $result);
    }
}
