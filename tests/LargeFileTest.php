<?php

declare(strict_types=1);

namespace WizardCompass\RobotsTxtParser\Tests;

use WizardCompass\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class LargeFileTest extends TestCase
{
    private RobotsTxtParser $parser;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->parser = new RobotsTxtParser();
        $this->tempDir = sys_get_temp_dir() . '/robots-txt-parser-test';

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testParseLargeFile5MB(): void
    {
        $targetSize = 5 * 1024 * 1024; // 5MB
        $filePath = $this->createLargeRobotsFile($targetSize);
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, 'Failed to read test file');

        $result = $this->parser->parse($content);

        $this->assertTrue($result['over_google_limit']);
        $this->assertGreaterThan($targetSize * 0.95, $result['size']); // Allow 5% variance
        $this->assertLessThan($targetSize * 1.05, $result['size']); // Allow 5% variance
        $this->assertGreaterThan(5000, $result['size_kib']); // At least 5000 KiB
        $this->assertGreaterThan(0, $result['record_counts']['by_type']['user_agent']);
        $this->assertGreaterThan(0, $result['record_counts']['by_type']['disallow']);
    }

    public function testParseExactlyAtGoogleLimit(): void
    {
        $filePath = $this->createLargeRobotsFile(RobotsTxtParser::GOOGLE_SIZE_LIMIT);
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, 'Failed to read test file');

        $result = $this->parser->parse($content);

        // The file might be slightly larger due to header, so check if it's close to the limit
        if ($result['size'] <= RobotsTxtParser::GOOGLE_SIZE_LIMIT) {
            $this->assertFalse($result['over_google_limit']);
        } else {
            $this->assertTrue($result['over_google_limit']);
        }
        $this->assertGreaterThan(RobotsTxtParser::GOOGLE_SIZE_LIMIT * 0.95, $result['size']);
    }

    public function testParseJustOverGoogleLimit(): void
    {
        $filePath = $this->createLargeRobotsFile(RobotsTxtParser::GOOGLE_SIZE_LIMIT + 1000); // Add more buffer
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, 'Failed to read test file');

        $result = $this->parser->parse($content);

        $this->assertTrue($result['over_google_limit']);
        $this->assertGreaterThan(RobotsTxtParser::GOOGLE_SIZE_LIMIT, $result['size']);
    }

    public function testParseLargeFileWithManyUserAgents(): void
    {
        $filePath = $this->createLargeRobotsFileWithManyUserAgents(1024 * 1024); // 1MB
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, 'Failed to read test file');

        $result = $this->parser->parse($content);

        $this->assertTrue($result['over_google_limit']);
        $this->assertGreaterThan(100, $result['record_counts']['by_type']['user_agent']);
        $this->assertGreaterThan(100, count($result['record_counts']['by_useragent']));
    }

    public function testParseLargeFilePerformance(): void
    {
        $filePath = $this->createLargeRobotsFile(2 * 1024 * 1024); // 2MB
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, 'Failed to read test file');

        $startTime = microtime(true);
        $result = $this->parser->parse($content);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        // Should parse a 2MB file in less than 1 second
        $this->assertLessThan(1.0, $executionTime, 'Parsing should be fast even for large files');
        $this->assertTrue($result['over_google_limit']);
    }

    /**
     * Create a large robots.txt file for testing
     */
    private function createLargeRobotsFile(int $targetSize): string
    {
        $filePath = $this->tempDir . '/large-robots-' . $targetSize . '.txt';

        $baseContent = "User-agent: testbot\nDisallow: /path/to/disallow\nAllow: /path/to/allow\n";
        $baseSize = strlen($baseContent);
        $repetitions = (int) ceil($targetSize / $baseSize);

        $handle = fopen($filePath, 'w');

        // Add header comment
        fwrite($handle, "# Large robots.txt file for testing - {$targetSize} bytes\n");

        $currentSize = ftell($handle);
        $iteration = 0;

        while ($currentSize < $targetSize && $iteration < $repetitions) {
            $content = str_replace('testbot', "testbot{$iteration}", $baseContent);
            $content = str_replace('/path/to/', "/path/to/{$iteration}/", $content);

            fwrite($handle, $content);
            $currentSize = ftell($handle);
            $iteration++;
        }

        fclose($handle);

        return $filePath;
    }

    /**
     * Create a large robots.txt file with many different user agents
     */
    private function createLargeRobotsFileWithManyUserAgents(int $targetSize): string
    {
        $filePath = $this->tempDir . '/large-robots-many-agents-' . $targetSize . '.txt';

        $handle = fopen($filePath, 'w');

        // Add header comment
        fwrite($handle, "# Large robots.txt file with many user agents - {$targetSize} bytes\n");

        $userAgents = [
            'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider',
            'YandexBot', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot',
            'WhatsApp', 'Applebot', 'ia_archiver', 'SemrushBot', 'AhrefsBot',
        ];

        $currentSize = ftell($handle);
        $iteration = 0;

        while ($currentSize < $targetSize) {
            foreach ($userAgents as $agent) {
                if ($currentSize >= $targetSize) {
                    break;
                }

                $content = sprintf(
                    "User-agent: %s%d\nDisallow: /admin/%d/\nDisallow: /private/%d/\nAllow: /public/%d/\nCrawl-delay: %d\n\n",
                    $agent,
                    $iteration,
                    $iteration,
                    $iteration,
                    $iteration,
                    $iteration % 10 + 1
                );

                fwrite($handle, $content);
                $currentSize = ftell($handle);
            }

            $iteration++;

            // Prevent infinite loop
            if ($iteration > 10000) {
                break;
            }
        }

        fclose($handle);

        return $filePath;
    }
}
