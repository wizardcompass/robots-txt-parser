# Robots.txt Parser for PHP

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

A comprehensive PHP library for parsing and analyzing robots.txt files. This package provides functionality to fetch, parse, and validate robots.txt files with support for large files and streaming downloads.

## Features

- 🚀 **Fast parsing** of robots.txt content with detailed statistics
- 🌐 **URL fetching** with streaming support and size limits
- ✅ **Validation** with detailed error and warning reporting
- 📊 **Comprehensive analysis** including directive counts by type and user agent
- 🛡️ **Size protection** with Google's 500KB limit enforcement
- 🔧 **Timeout handling** for large file downloads
- 📈 **Performance optimized** for large files (tested up to 5MB+)

## Installation

Install via Composer:

```bash
composer require leopoletto/robots-txt-parser
```

## Requirements

- PHP 8.1 or higher
- No additional dependencies for core functionality

## Quick Start

```php
<?php

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

// Parse from string
$robotsTxt = "User-agent: *\nDisallow: /admin\nAllow: /public";
$result = $parser->parse($robotsTxt);

// Parse from URL
$result = $parser->parseFromUrl('https://example.com/robots.txt');

// Validate syntax
$validation = $parser->validate($robotsTxt);
```

## Usage Examples

### Basic Parsing

```php
<?php

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

$content = <<<'EOT'
# Example robots.txt
User-agent: *
Disallow: /admin
Disallow: /private
Allow: /public
Crawl-delay: 10

User-agent: Googlebot
Allow: /admin/public
Disallow: /admin/private

Sitemap: https://example.com/sitemap.xml
EOT;

$result = $parser->parse($content);

echo "File size: " . $result['size'] . " bytes\n";
echo "Comments: " . $result['comment_count'] . "\n";
echo "User agents: " . $result['record_counts']['by_type']['user_agent'] . "\n";
echo "Disallow rules: " . $result['record_counts']['by_type']['disallow'] . "\n";

// Access user-agent specific data
foreach ($result['record_counts']['by_useragent'] as $userAgent => $counts) {
    echo "User-agent '{$userAgent}' has {$counts['disallow']} disallow rules\n";
}
```

### Fetching from URL

```php
<?php

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

try {
    // Fetch with default 30-second timeout
    $result = $parser->parseFromUrl('https://example.com/robots.txt');
    
    // Fetch with custom timeout (60 seconds)
    $result = $parser->parseFromUrl('https://example.com/robots.txt', 60);
    
    echo "Status: " . $result['status'] . "\n";
    echo "Redirected: " . ($result['redirected'] ? 'Yes' : 'No') . "\n";
    echo "Size: " . number_format($result['size_kib'], 2) . " KB\n";
    
    if (isset($result['size_limit_exceeded'])) {
        echo "Warning: File exceeded 500KB limit and was truncated\n";
    }
    
} catch (InvalidArgumentException $e) {
    echo "Invalid URL: " . $e->getMessage() . "\n";
} catch (RuntimeException $e) {
    echo "Failed to fetch: " . $e->getMessage() . "\n";
}
```

### Validation

```php
<?php

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

$content = <<<'EOT'
User-agent: *
Disallow /admin
Crawl-delay: invalid
Sitemap: not-a-url
Unknown-directive: value
EOT;

$validation = $parser->validate($content);

if (!$validation['is_valid']) {
    echo "Validation failed!\n\n";
    
    foreach ($validation['errors'] as $error) {
        echo "❌ Error: $error\n";
    }
}

if (!empty($validation['warnings'])) {
    echo "\nWarnings:\n";
    foreach ($validation['warnings'] as $warning) {
        echo "⚠️  Warning: $warning\n";
    }
}
```

### Advanced Options

```php
<?php

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

// Parse with additional metadata
$result = $parser->parse($content, [
    'status' => 404,      // HTTP status code
    'redirected' => true  // Whether the request was redirected
]);

// Check if file exceeds Google's size limit
if ($result['over_google_limit']) {
    echo "Warning: File exceeds Google's 500KB recommendation\n";
}

// Analyze directive distribution
$recordCounts = $result['record_counts']['by_type'];
echo "Directive breakdown:\n";
echo "- User-agent: {$recordCounts['user_agent']}\n";
echo "- Disallow: {$recordCounts['disallow']}\n";
echo "- Allow: {$recordCounts['allow']}\n";
echo "- Sitemap: {$recordCounts['sitemap']}\n";
echo "- Crawl-delay: {$recordCounts['crawl_delay']}\n";
echo "- Other: {$recordCounts['other']}\n";
```

## Response Format

### Parse Results

```php
[
    'comment_count' => 2,              // Number of comment lines
    'over_google_limit' => false,      // Whether file exceeds 500KB
    'record_counts' => [
        'by_type' => [
            'allow' => 1,
            'crawl_delay' => 1,
            'disallow' => 3,
            'noindex' => 0,
            'other' => 0,
            'sitemap' => 1,
            'user_agent' => 2
        ],
        'by_useragent' => [
            '*' => [
                'allow' => 1,
                'crawl_delay' => 1,
                'disallow' => 2,
                'noindex' => 0,
                'other' => 0
            ],
            'Googlebot' => [
                'allow' => 0,
                'crawl_delay' => 0,
                'disallow' => 1,
                'noindex' => 0,
                'other' => 0
            ]
        ]
    ],
    'redirected' => false,             // Whether URL was redirected
    'size' => 150,                     // File size in bytes
    'size_kib' => 0.146484375,        // File size in KiB
    'status' => 200                    // HTTP status code
]
```

### Validation Results

```php
[
    'is_valid' => false,              // Overall validation status
    'warnings' => [                   // Non-critical issues
        'Line 5: Unknown directive "unknown-directive"'
    ],
    'errors' => [                     // Critical syntax errors
        'Line 2: Invalid syntax - missing colon: "Disallow /admin"',
        'Line 3: Crawl-delay value must be a non-negative number'
    ]
]
```

## Size Limits and Performance

This library implements Google's recommended 500KB size limit for robots.txt files:

- **Parsing**: Files of any size can be parsed, with a warning flag for files exceeding 500KB
- **URL Fetching**: Downloads are automatically terminated at 500KB to prevent memory issues
- **Performance**: Optimized for large files with efficient string processing
- **Memory**: Uses streaming for URL downloads to minimize memory usage

## Supported Directives

- `User-agent`: Specifies which web crawler the rules apply to
- `Disallow`: Specifies paths that should not be crawled
- `Allow`: Explicitly allows crawling of specific paths
- `Crawl-delay` / `Crawldelay`: Specifies delay between requests
- `Sitemap`: Specifies the location of sitemap files
- `Noindex`: Prevents indexing of specific paths
- `Request-rate`: Controls request rate (marked as "other")
- `Visit-time`: Specifies preferred visit times (marked as "other")
- `Host`: Specifies preferred host (marked as "other")

## Error Handling

The library uses proper PHP exceptions:

- `InvalidArgumentException`: For invalid URLs or parameters
- `RuntimeException`: For network errors or file access issues

Always wrap URL operations in try-catch blocks for robust error handling.

## Testing

Run the test suite:

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run tests with coverage
composer test-coverage
```

The test suite includes:
- Unit tests for all parsing functionality
- Large file tests (up to 5MB)
- Performance benchmarks
- Edge case validation
- URL fetching simulation

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Inspiration

This project was inspired by HTTP Archive's robots.txt analysis capabilities and aims to provide the same level of detailed parsing and validation for PHP applications.

## Changelog

### v1.0.0
- Initial release
- Basic parsing functionality
- URL fetching with size limits
- Comprehensive validation
- Large file support
