<?php

require_once __DIR__ . '/../vendor/autoload.php';

use WizardCompass\RobotsTxtParser\RobotsTxtParser;

// Create parser instance
$parser = new RobotsTxtParser();

echo "🤖 Robots.txt Parser Examples\n";
echo "=============================\n\n";

// Example 1: Parse from string
echo "📄 Example 1: Parsing from string\n";
echo "---------------------------------\n";

$sampleRobotsTxt = <<<'EOT'
# www.robotstxt.org/
# www.google.com/support/webmasters/bin/answer.py?hl=en&answer=156449
Sitemap: https://www.company.com/sitemap.xml

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

$result = $parser->parse($sampleRobotsTxt);

echo "File size: " . number_format($result['size']) . " bytes (" . number_format($result['size_kib'], 2) . " KB)\n";
echo "Comments: " . $result['comment_count'] . "\n";
echo "Over Google limit: " . ($result['over_google_limit'] ? 'Yes' : 'No') . "\n";
echo "Status: " . $result['status'] . "\n";

echo "\nDirective counts by type:\n";
foreach ($result['record_counts']['by_type'] as $type => $count) {
    if ($count > 0) {
        echo "  - " . ucwords(str_replace('_', ' ', $type)) . ": $count\n";
    }
}

echo "\nSitemaps found: " . count($result['sitemaps']) . "\n";
foreach ($result['sitemaps'] as $sitemap) {
    echo "  - $sitemap\n";
}

echo "\nUser agent specific counts:\n";
foreach ($result['record_counts']['by_useragent'] as $userAgent => $counts) {
    echo "  - User-agent '$userAgent':\n";
    foreach ($counts as $directive => $count) {
        if ($count > 0) {
            echo "    • " . ucwords(str_replace('_', ' ', $directive)) . ": $count\n";
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Example 2: Validation
echo "✅ Example 2: Validation\n";
echo "------------------------\n";

$invalidRobotsTxt = <<<'EOT'
User-agent: *
Disallow /admin
Crawl-delay: invalid
Sitemap: not-a-url
Unknown-directive: value

User-agent:
Disallow: /test
EOT;

$validation = $parser->validate($invalidRobotsTxt);

echo "Is valid: " . ($validation['is_valid'] ? 'Yes' : 'No') . "\n";

if (! empty($validation['errors'])) {
    echo "\nErrors found:\n";
    foreach ($validation['errors'] as $error) {
        echo "  ❌ $error\n";
    }
}

if (! empty($validation['warnings'])) {
    echo "\nWarnings:\n";
    foreach ($validation['warnings'] as $warning) {
        echo "  ⚠️  $warning\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Example 3: URL fetching with automatic robots.txt resolution
echo "🌐 Example 3: URL fetching\n";
echo "--------------------------\n";

try {
    // Test with domain only - automatically appends /robots.txt
    echo "Fetching robots.txt from https://www.google.com (auto-appends /robots.txt)...\n";
    $result = $parser->parseFromUrl('https://www.google.com');

    echo "Status: " . $result['status'] . "\n";
    echo "Redirected: " . ($result['redirected'] ? 'Yes' : 'No') . "\n";
    echo "Size: " . number_format($result['size_kib'], 2) . " KB\n";
    echo "User agents found: " . count($result['record_counts']['by_useragent']) . "\n";
    echo "Sitemaps found: " . count($result['sitemaps']) . "\n";

    if (isset($result['size_limit_exceeded'])) {
        echo "⚠️  Warning: File exceeded 500KB limit and was truncated\n";
    }

    if (! empty($result['sitemaps'])) {
        echo "\nSitemaps:\n";
        foreach ($result['sitemaps'] as $sitemap) {
            echo "  - $sitemap\n";
        }
    }

    echo "\nTop 3 user agents by directive count:\n";
    $userAgents = $result['record_counts']['by_useragent'];
    arsort($userAgents);
    $count = 0;
    foreach ($userAgents as $agent => $counts) {
        if ($count >= 3) {
            break;
        }
        $totalDirectives = array_sum($counts);
        echo "  - '{$agent}': {$totalDirectives} directives\n";
        $count++;
    }

} catch (InvalidArgumentException $e) {
    echo "❌ Invalid URL: " . $e->getMessage() . "\n";
} catch (RuntimeException $e) {
    echo "❌ Failed to fetch: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Example 4: Large file simulation
echo "📊 Example 4: Large file simulation\n";
echo "-----------------------------------\n";

echo "Creating a large robots.txt content...\n";

// Create content that's around 600KB (over Google's limit)
$largeContent = "# Large robots.txt file for testing\n";
$baseRule = "User-agent: bot%d\nDisallow: /path%d/\nAllow: /public%d/\nCrawl-delay: %d\n\n";

$iteration = 0;
while (strlen($largeContent) < 600 * 1024) { // 600KB
    $largeContent .= sprintf($baseRule, $iteration, $iteration, $iteration, ($iteration % 10) + 1);
    $iteration++;
}

echo "Generated content size: " . number_format(strlen($largeContent)) . " bytes\n";

$startTime = microtime(true);
$result = $parser->parse($largeContent);
$endTime = microtime(true);

echo "Parsing completed in " . number_format(($endTime - $startTime) * 1000, 2) . " ms\n";
echo "Over Google limit: " . ($result['over_google_limit'] ? 'Yes' : 'No') . "\n";
echo "User agents found: " . count($result['record_counts']['by_useragent']) . "\n";
echo "Total directives: " . array_sum($result['record_counts']['by_type']) . "\n";

echo "\n🎉 All examples completed successfully!\n";
