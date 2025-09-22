<?php

declare(strict_types=1);

namespace WizardCompass\RobotsTxtParser\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WizardCompass\RobotsTxtParser\RobotsTxtParser;

class RobotsTxtParserTest extends TestCase
{
    private RobotsTxtParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RobotsTxtParser();
    }

    public function testParseBasicRobotsTxt(): void
    {
        $content = "# Comment line\nUser-agent: *\nDisallow: /admin\nAllow: /public\nSitemap: https://example.com/sitemap.xml";

        $result = $this->parser->parse($content);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['comment_count']);
        $this->assertEquals(strlen($content), $result['size']);
        $this->assertEquals(strlen($content) / 1024, $result['size_kib']);
        $this->assertFalse($result['over_google_limit']);
        $this->assertEquals(200, $result['status']);
        $this->assertFalse($result['redirected']);

        // Check record counts
        $this->assertEquals(1, $result['record_counts']['by_type']['user_agent']);
        $this->assertEquals(1, $result['record_counts']['by_type']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_type']['allow']);
        $this->assertEquals(1, $result['record_counts']['by_type']['sitemap']);
        $this->assertEquals(0, $result['record_counts']['by_type']['crawl_delay']);

        // Check user agent specific counts
        $this->assertArrayHasKey('*', $result['record_counts']['by_useragent']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['*']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['*']['allow']);

        // Check sitemaps
        $this->assertArrayHasKey('sitemaps', $result);
        $this->assertCount(1, $result['sitemaps']);
        $this->assertEquals('https://example.com/sitemap.xml', $result['sitemaps'][0]);
    }

    public function testParseComplexRobotsTxt(): void
    {
        $content = <<<'EOT'
# www.robotstxt.org/
# www.google.com/support/webmasters/bin/answer.py?hl=en&answer=156449
Sitemap: https://www.example.com/sitemap.xml

User-agent: *
Disallow: /health
Disallow: /review/
Disallow: /arrange-viewings/add/
Disallow: /arrange-viewings/remove/
Disallow: /_*
Disallow: /data
Disallow: /*.json$
Disallow: /*/*-c*/entry-requirement-description?entryRequirementIndex=
Disallow: /*/*-c*/english-requirement-description?englishRequirementIndex=
Disallow: /*/courses/*/courses
Disallow: /*/*-c*/description
Disallow: /*/*-c*/fees

User-agent: ShopWiki
Disallow: /

User-agent: GPTBot
Disallow: /
EOT;

        $result = $this->parser->parse($content);

        $this->assertEquals(2, $result['comment_count']);
        $this->assertEquals(3, $result['record_counts']['by_type']['user_agent']);
        $this->assertEquals(14, $result['record_counts']['by_type']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_type']['sitemap']);

        // Check specific user agents
        $this->assertEquals(12, $result['record_counts']['by_useragent']['*']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['ShopWiki']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['GPTBot']['disallow']);

        // Check sitemaps
        $this->assertCount(1, $result['sitemaps']);
        $this->assertEquals('https://www.example.com/sitemap.xml', $result['sitemaps'][0]);
    }

    public function testParseWithCrawlDelay(): void
    {
        $content = "User-agent: *\nCrawl-delay: 10\nUser-agent: bot\nCrawldelay: 5";

        $result = $this->parser->parse($content);

        $this->assertEquals(2, $result['record_counts']['by_type']['crawl_delay']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['*']['crawl_delay']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['bot']['crawl_delay']);
    }

    public function testParseWithNoindex(): void
    {
        $content = "User-agent: *\nNoindex: /private";

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result['record_counts']['by_type']['noindex']);
        $this->assertEquals(1, $result['record_counts']['by_useragent']['*']['noindex']);
    }

    public function testParseWithOtherDirectives(): void
    {
        $content = "User-agent: *\nRequest-rate: 1/10s\nVisit-time: 0400-0845\nHost: example.com";

        $result = $this->parser->parse($content);

        $this->assertEquals(3, $result['record_counts']['by_type']['other']);
        $this->assertEquals(3, $result['record_counts']['by_useragent']['*']['other']);
    }

    public function testParseWithMultipleSitemaps(): void
    {
        $content = <<<'EOT'
User-agent: *
Disallow: /admin

Sitemap: https://example.com/sitemap.xml
Sitemap: https://example.com/sitemap-news.xml
Sitemap: https://example.com/sitemap-images.xml
EOT;

        $result = $this->parser->parse($content);

        $this->assertEquals(3, $result['record_counts']['by_type']['sitemap']);
        $this->assertCount(3, $result['sitemaps']);
        $this->assertEquals('https://example.com/sitemap.xml', $result['sitemaps'][0]);
        $this->assertEquals('https://example.com/sitemap-news.xml', $result['sitemaps'][1]);
        $this->assertEquals('https://example.com/sitemap-images.xml', $result['sitemaps'][2]);
    }

    public function testParseWithEmptySitemap(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nSitemap:";

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result['record_counts']['by_type']['sitemap']);
        $this->assertCount(0, $result['sitemaps']); // Empty sitemap should not be added
    }

    public function testParseWithOptions(): void
    {
        $content = "User-agent: *\nDisallow: /";

        $result = $this->parser->parse($content, ['status' => 404, 'redirected' => true]);

        $this->assertEquals(404, $result['status']);
        $this->assertTrue($result['redirected']);
    }

    public function testParseLargeFile(): void
    {
        // Create content that exceeds Google's limit
        $largeContent = str_repeat("User-agent: bot\nDisallow: /test\n", 20000); // ~500KB+

        $result = $this->parser->parse($largeContent);

        $this->assertTrue($result['over_google_limit']);
        $this->assertGreaterThan(RobotsTxtParser::GOOGLE_SIZE_LIMIT, $result['size']);
    }

    public function testValidateValidRobotsTxt(): void
    {
        $content = "User-agent: *\nDisallow: /admin\nSitemap: https://example.com/sitemap.xml";

        $result = $this->parser->validate($content);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
    }

    public function testValidateInvalidSyntax(): void
    {
        $content = "User-agent *\nDisallow /admin";

        $result = $this->parser->validate($content);

        $this->assertFalse($result['is_valid']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('missing colon', $result['errors'][0]);
        $this->assertStringContainsString('missing colon', $result['errors'][1]);
    }

    public function testValidateEmptyUserAgent(): void
    {
        $content = "User-agent:\nDisallow: /admin";

        $result = $this->parser->validate($content);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('User-agent cannot be empty', $result['errors'][0]);
    }

    public function testValidateInvalidCrawlDelay(): void
    {
        $content = "User-agent: *\nCrawl-delay: invalid";

        $result = $this->parser->validate($content);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('non-negative number', $result['errors'][0]);
    }

    public function testValidateInvalidSitemapUrl(): void
    {
        $content = "Sitemap: not-a-url";

        $result = $this->parser->validate($content);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('Invalid sitemap URL', $result['errors'][0]);
    }

    public function testValidateUnknownDirective(): void
    {
        $content = "User-agent: *\nUnknown-directive: value";

        $result = $this->parser->validate($content);

        $this->assertTrue($result['is_valid']); // No errors, just warnings
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Unknown directive', $result['warnings'][0]);
    }

    public function testValidateDirectiveWithoutUserAgent(): void
    {
        $content = "Disallow: /admin\nUser-agent: *";

        $result = $this->parser->validate($content);

        $this->assertTrue($result['is_valid']); // No errors, just warnings
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('should come after a User-agent', $result['warnings'][0]);
    }

    public function testParseFromUrlInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL provided');

        $this->parser->parseFromUrl('not-a-url');
    }

    public function testNormalizeRobotsUrl(): void
    {
        $parser = new RobotsTxtParser();
        $class = new \ReflectionClass($parser);
        $method = $class->getMethod('normalizeRobotsUrl');
        $method->setAccessible(true);

        // Test basic URL normalization
        $this->assertEquals(
            'https://example.com/robots.txt',
            $method->invoke($parser, 'https://example.com')
        );

        $this->assertEquals(
            'https://example.com/robots.txt',
            $method->invoke($parser, 'https://example.com/')
        );

        $this->assertEquals(
            'https://example.com/robots.txt',
            $method->invoke($parser, 'https://example.com/some/path')
        );

        // Test when robots.txt is already in URL
        $this->assertEquals(
            'https://example.com/robots.txt',
            $method->invoke($parser, 'https://example.com/robots.txt')
        );

        // Test with port
        $this->assertEquals(
            'https://example.com:8080/robots.txt',
            $method->invoke($parser, 'https://example.com:8080')
        );

        // Test with subdirectory robots.txt
        $this->assertEquals(
            'https://example.com/subdir/robots.txt',
            $method->invoke($parser, 'https://example.com/subdir/robots.txt')
        );
    }

    public function testParseEmptyContent(): void
    {
        $result = $this->parser->parse('');

        $this->assertEquals(0, $result['comment_count']);
        $this->assertEquals(0, $result['size']);
        $this->assertFalse($result['over_google_limit']);
        $this->assertEquals(0, $result['record_counts']['by_type']['user_agent']);
    }

    public function testParseOnlyComments(): void
    {
        $content = "# First comment\n# Second comment\n# Third comment";

        $result = $this->parser->parse($content);

        $this->assertEquals(3, $result['comment_count']);
        $this->assertEquals(0, $result['record_counts']['by_type']['user_agent']);
    }

    public function testParseOnlyWhitespace(): void
    {
        $content = "   \n\t\n   \n";

        $result = $this->parser->parse($content);

        $this->assertEquals(0, $result['comment_count']);
        $this->assertEquals(0, $result['record_counts']['by_type']['user_agent']);
    }

    public function testParseMixedLineEndings(): void
    {
        $content = "User-agent: *\r\nDisallow: /test\rAllow: /public\n";

        $result = $this->parser->parse($content);

        $this->assertEquals(1, $result['record_counts']['by_type']['user_agent']);
        $this->assertEquals(1, $result['record_counts']['by_type']['disallow']);
        $this->assertEquals(1, $result['record_counts']['by_type']['allow']);
    }

    public function testValidateWithComments(): void
    {
        $content = "# This is a comment\nUser-agent: * # inline comment\nDisallow: /admin";

        $result = $this->parser->validate($content);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }
}
