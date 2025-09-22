<?php

declare(strict_types=1);

namespace WizardCompass\RobotsTxtParser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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
     * @param array{status?: int, redirected?: bool} $options Additional options (status, redirected)
     * @return array{comment_count: int, over_google_limit: bool, record_counts: array, sitemaps: array<string>, redirected: bool, size: int, size_kib: float, status: int} Parsed robots.txt data
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
                'user_agent' => 0,
            ],
            'by_useragent' => [],
        ];

        $commentCount = 0;
        $currentUserAgent = null;
        $sitemaps = [];

        // Split content into lines and process (handle all line ending types)
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            $lines = [$content]; // Fallback to treating as single line
        }

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
                    if (! isset($recordCounts['by_useragent'][$currentUserAgent])) {
                        $recordCounts['by_useragent'][$currentUserAgent] = [
                            'allow' => 0,
                            'crawl_delay' => 0,
                            'disallow' => 0,
                            'noindex' => 0,
                            'other' => 0,
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
                    // Collect sitemap URLs
                    if (! empty($value)) {
                        $sitemaps[] = $value;
                    }

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
            'sitemaps' => $sitemaps,
            'redirected' => $redirected,
            'size' => $size,
            'size_kib' => $sizeKib,
            'status' => $status,
        ];
    }

    /**
     * Parse robots.txt from a URL with automatic robots.txt path resolution
     *
     * @param string $url URL to fetch robots.txt from (automatically appends /robots.txt if not present)
     * @param int $timeout Request timeout in seconds
     * @return array{comment_count: int, over_google_limit: bool, record_counts: array, sitemaps: array<string>, redirected: bool, size: int, size_kib: float, status: int, size_limit_exceeded?: bool, partial_content?: bool} Parsed robots.txt data
     * @throws InvalidArgumentException If URL is invalid
     * @throws RuntimeException If fetch fails or exceeds size limit
     */
    public function parseFromUrl(string $url, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        // Validate and normalize URL
        $robotsUrl = $this->normalizeRobotsUrl($url);

        try {
            $client = new Client([
                'timeout' => $timeout,
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
                'headers' => [
                    'User-Agent' => 'WizardCompass-RobotsTxtParser/1.0 (+https://github.com/wizardcompass/robots-txt-parser)',
                ],
            ]);

            $response = $client->get($robotsUrl, [
                'stream' => true, // Enable streaming for large files
            ]);

            $content = '';
            $totalBytes = 0;
            $exceedsLimit = false;
            $body = $response->getBody();

            // Read in chunks to monitor size
            while (! $body->eof()) {
                $chunk = $body->read(8192); // 8KB chunks

                $totalBytes += strlen($chunk);

                // Check if we're exceeding the Google limit
                if ($totalBytes > self::GOOGLE_SIZE_LIMIT) {
                    $exceedsLimit = true;

                    break;
                }

                $content .= $chunk;
            }

            // Get response information
            $status = $response->getStatusCode();
            $redirected = $this->hasRedirects($response);

            $result = $this->parse($content, [
                'status' => $status,
                'redirected' => $redirected,
            ]);

            // Add information about size limit exceeded
            if ($exceedsLimit) {
                $result['size_limit_exceeded'] = true;
                $result['partial_content'] = true;
            }

            return $result;

        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            throw new RuntimeException("Failed to fetch robots.txt from {$robotsUrl}: {$e->getMessage()} (HTTP {$status})");
        } catch (GuzzleException $e) {
            throw new RuntimeException("Failed to fetch robots.txt from {$robotsUrl}: {$e->getMessage()}");
        }
    }

    /**
     * Normalize URL to point to robots.txt
     *
     * @param string $url Input URL
     * @return string Normalized robots.txt URL
     * @throws InvalidArgumentException If URL is invalid
     */
    private function normalizeRobotsUrl(string $url): string
    {
        // Validate URL
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}");
        }

        $parsedUrl = parse_url($url);
        if (! $parsedUrl || ! isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            throw new InvalidArgumentException("Invalid URL format: {$url}");
        }

        // Build base URL
        $robotsUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        // Add port if specified
        if (isset($parsedUrl['port'])) {
            $robotsUrl .= ':' . $parsedUrl['port'];
        }

        // Check if URL already points to robots.txt
        $path = $parsedUrl['path'] ?? '';
        if (! str_ends_with(strtolower($path), 'robots.txt')) {
            $robotsUrl .= '/robots.txt';
        } else {
            $robotsUrl .= $path;
        }

        return $robotsUrl;
    }

    /**
     * Check if the response was redirected
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return bool
     */
    private function hasRedirects($response): bool
    {
        return $response->hasHeader('X-Guzzle-Redirect-History') ||
               $response->hasHeader('X-Guzzle-Redirect-Status-History');
    }

    /**
     * Validate robots.txt syntax and provide warnings and errors
     *
     * @param string $content The robots.txt file content
     * @return array{is_valid: bool, warnings: array<string>, errors: array<string>} Validation results with warnings and errors
     */
    public function validate(string $content): array
    {
        $warnings = [];
        $errors = [];

        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            $lines = [$content]; // Fallback to treating as single line
        }

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
                'sitemap', 'noindex', 'request-rate', 'visit-time', 'host',
            ];

            if (! in_array($directive, $validDirectives, true)) {
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
                if (! filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = "Line {$lineNumber}: Invalid sitemap URL: \"{$value}\"";
                }
            }
        }

        return [
            'is_valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
