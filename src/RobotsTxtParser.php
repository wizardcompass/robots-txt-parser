<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser;

use InvalidArgumentException;
use RuntimeException;

/**
 * A PHP library to parse and analyze robots.txt files
 * 
 * This class provides functionality to:
 * - Parse robots.txt content and analyze directives
 * - Fetch and parse robots.txt from URLs with size limits
 * - Validate robots.txt syntax with warnings and errors
 * - Handle streaming downloads with timeout protection
 */
class RobotsTxtParser
{
    /**
     * Google's recommended robots.txt size limit (500KB)
     */
    public const GOOGLE_SIZE_LIMIT = 500 * 1024;

    /**
     * Default timeout for URL fetching (30 seconds)
     */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Parse a robots.txt file content into the specified format
     *
     * @param string $content The robots.txt file content
     * @param array $options Additional options (status, redirected)
     * @return array Parsed robots.txt data
     */
    public function parse(string $content, array $options = []): array
    {
        $status = $options['status'] ?? 200;
        $redirected = $options['redirected'] ?? false;

        // Basic file metrics
        $size = strlen($content);
        $sizeKib = $size / 1024;
        $overGoogleLimit = $size > self::GOOGLE_SIZE_LIMIT;

        // Initialize counters
        $recordCounts = [
            'by_type' => [
                'allow' => 0,
                'crawl_delay' => 0,
                'disallow' => 0,
                'noindex' => 0,
                'other' => 0,
                'sitemap' => 0,
                'user_agent' => 0
            ],
            'by_useragent' => []
        ];

        $commentCount = 0;
        $currentUserAgent = null;

        // Split content into lines and process (handle all line ending types)
        $lines = preg_split('/\r\n|\n|\r/', $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Count comments
            if (str_starts_with($line, '#')) {
                $commentCount++;
                continue;
            }

            // Parse directive
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $directive = strtolower(trim(substr($line, 0, $colonPos)));
            $value = trim(substr($line, $colonPos + 1));

            // Handle different directives
            switch ($directive) {
                case 'user-agent':
                    $currentUserAgent = $value;
                    $recordCounts['by_type']['user_agent']++;

                    // Initialize user agent counters if not exists
                    if (!isset($recordCounts['by_useragent'][$currentUserAgent])) {
                        $recordCounts['by_useragent'][$currentUserAgent] = [
                            'allow' => 0,
                            'crawl_delay' => 0,
                            'disallow' => 0,
                            'noindex' => 0,
                            'other' => 0
                        ];
                    }
                    break;

                case 'allow':
                    $recordCounts['by_type']['allow']++;
                    if ($currentUserAgent !== null) {
                        $recordCounts['by_useragent'][$currentUserAgent]['allow']++;
                    }
                    break;

                case 'disallow':
                    $recordCounts['by_type']['disallow']++;
                    if ($currentUserAgent !== null) {
                        $recordCounts['by_useragent'][$currentUserAgent]['disallow']++;
                    }
                    break;

                case 'crawl-delay':
                case 'crawldelay':
                    $recordCounts['by_type']['crawl_delay']++;
                    if ($currentUserAgent !== null) {
                        $recordCounts['by_useragent'][$currentUserAgent]['crawl_delay']++;
                    }
                    break;

                case 'noindex':
                    $recordCounts['by_type']['noindex']++;
                    if ($currentUserAgent !== null) {
                        $recordCounts['by_useragent'][$currentUserAgent]['noindex']++;
                    }
                    break;

                case 'sitemap':
                    $recordCounts['by_type']['sitemap']++;
                    break;

                default:
                    // Handle other directives like Request-rate, Visit-time, etc.
                    $recordCounts['by_type']['other']++;
                    if ($currentUserAgent !== null) {
                        $recordCounts['by_useragent'][$currentUserAgent]['other']++;
                    }
                    break;
            }
        }

        return [
            'comment_count' => $commentCount,
            'over_google_limit' => $overGoogleLimit,
            'record_counts' => $recordCounts,
            'redirected' => $redirected,
            'size' => $size,
            'size_kib' => $sizeKib,
            'status' => $status
        ];
    }

    /**
     * Parse robots.txt from a URL with streaming support and size limits
     *
     * @param string $url URL to fetch robots.txt from
     * @param int $timeout Request timeout in seconds
     * @return array Parsed robots.txt data
     * @throws InvalidArgumentException If URL is invalid
     * @throws RuntimeException If fetch fails or exceeds size limit
     */
    public function parseFromUrl(string $url, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}");
        }

        // Create stream context with timeout
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'user_agent' => 'Leopoletto-RobotsTxtParser/1.0 (+https://github.com/leopoletto/robots-txt-parser)',
                'follow_location' => true,
                'max_redirects' => 5
            ]
        ]);

        $handle = fopen($url, 'r', false, $context);
        
        if (!$handle) {
            throw new RuntimeException("Failed to open URL: {$url}");
        }

        $content = '';
        $totalBytes = 0;
        $exceedsLimit = false;

        try {
            // Read in chunks to monitor size
            while (!feof($handle)) {
                $chunk = fread($handle, 8192); // 8KB chunks
                
                if ($chunk === false) {
                    break;
                }

                $totalBytes += strlen($chunk);
                
                // Check if we're exceeding the Google limit
                if ($totalBytes > self::GOOGLE_SIZE_LIMIT) {
                    $exceedsLimit = true;
                    break;
                }
                
                $content .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        // Get response metadata
        $metadata = stream_get_meta_data($handle);
        $status = 200; // Default status
        $redirected = false;

        // Parse HTTP response headers if available
        if (isset($metadata['wrapper_data'])) {
            foreach ($metadata['wrapper_data'] as $header) {
                if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                    $status = (int) $matches[1];
                }
                if (stripos($header, 'Location:') === 0) {
                    $redirected = true;
                }
            }
        }

        $result = $this->parse($content, [
            'status' => $status,
            'redirected' => $redirected
        ]);

        // Add information about size limit exceeded
        if ($exceedsLimit) {
            $result['size_limit_exceeded'] = true;
            $result['partial_content'] = true;
        }

        return $result;
    }

    /**
     * Validate robots.txt syntax and provide warnings and errors
     *
     * @param string $content The robots.txt file content
     * @return array Validation results with warnings and errors
     */
    public function validate(string $content): array
    {
        $warnings = [];
        $errors = [];

        $lines = preg_split('/\r\n|\n|\r/', $content);
        $currentUserAgent = null;
        $lineNumber = 0;

        foreach ($lines as $originalLine) {
            $lineNumber++;
            $line = trim($originalLine);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                $errors[] = "Line {$lineNumber}: Invalid syntax - missing colon: \"{$originalLine}\"";
                continue;
            }

            $directive = strtolower(trim(substr($line, 0, $colonPos)));
            $value = trim(substr($line, $colonPos + 1));

            // Validate directives
            $validDirectives = [
                'user-agent', 'allow', 'disallow', 'crawl-delay', 'crawldelay',
                'sitemap', 'noindex', 'request-rate', 'visit-time', 'host'
            ];

            if (!in_array($directive, $validDirectives, true)) {
                $warnings[] = "Line {$lineNumber}: Unknown directive \"{$directive}\"";
            }

            // Validate user-agent
            if ($directive === 'user-agent') {
                $currentUserAgent = $value;
                if (empty($value)) {
                    $errors[] = "Line {$lineNumber}: User-agent cannot be empty";
                }
            }

            // Validate that directives come after user-agent
            if (in_array($directive, ['allow', 'disallow', 'crawl-delay', 'crawldelay', 'noindex'], true)) {
                if ($currentUserAgent === null) {
                    $warnings[] = "Line {$lineNumber}: \"{$directive}\" directive should come after a User-agent directive";
                }
            }

            // Validate crawl-delay value
            if (in_array($directive, ['crawl-delay', 'crawldelay'], true)) {
                $delayValue = filter_var($value, FILTER_VALIDATE_FLOAT);
                if ($delayValue === false || $delayValue < 0) {
                    $errors[] = "Line {$lineNumber}: Crawl-delay value must be a non-negative number";
                }
            }

            // Validate sitemap URL
            if ($directive === 'sitemap') {
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = "Line {$lineNumber}: Invalid sitemap URL: \"{$value}\"";
                }
            }
        }

        return [
            'is_valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors
        ];
    }
}
