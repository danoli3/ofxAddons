<?php

declare(strict_types=1);

// First-pass scan of data/addons.json for two kinds of malicious content
// a repo could put in its name/description to attack whatever reads this
// crawl snapshot: markup/script injection aimed at a browser (the
// consuming site's own render path already escapes everything before
// formatting it - see ofx_render_markdown_lite() there - so this is a
// second, independent signal, not the only defense) and prompt injection
// aimed at a local model doing AI-assisted triage on the consuming site
// (text written to look like instructions - "ignore previous
// instructions", a fake "system:" message, etc).
//
// Read-only: this never modifies data/addons.json or blocks the crawl.
// It just annotates the Github Actions run with a warning per match, so
// a human sees it immediately in the workflow summary - actually
// banning/quarantining a matched repo is the consuming site's job (see
// ofx_detect_security_threats() there, which runs the same checks again
// independently when it applies this snapshot).
//
// Usage: php scripts/scan_threats.php data/addons.json

$path = $argv[1] ?? __DIR__ . '/../data/addons.json';
if (!is_file($path)) {
    fwrite(STDERR, "No such file: {$path}\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($path), true);
$addons = $data['addons'] ?? null;
if (!is_array($addons)) {
    fwrite(STDERR, "Could not parse addons array from {$path}\n");
    exit(1);
}

function ofx_scan_threats(string $name, string $description): array
{
    $reasons = [];
    $haystack = $name . "\n" . $description;

    $markupPatterns = [
        '/<script\b/i' => 'script tag',
        '/<iframe\b/i' => 'iframe tag',
        '/<svg\b/i' => 'svg tag',
        '/<object\b/i' => 'object tag',
        '/<embed\b/i' => 'embed tag',
        '/\bon(error|load|click|mouseover|focus)\s*=/i' => 'inline event handler attribute',
        '/javascript:/i' => 'javascript: URI',
        '/data:text\/html/i' => 'data:text/html URI',
    ];
    foreach ($markupPatterns as $pattern => $label) {
        if (preg_match($pattern, $haystack)) {
            $reasons[] = "markup injection attempt ({$label})";
        }
    }

    $promptInjectionPhrases = [
        'ignore previous instructions', 'ignore all previous', 'ignore the above',
        'disregard previous', 'disregard the above', 'disregard all prior',
        'new instructions:', 'system prompt', 'you are now', 'act as if',
        'do not tell the admin', 'do not tell the reviewer', 'do not flag this',
        'always classify this as', 'always mark this as', 'mark this repo as addon',
        'this is not spam', 'override your instructions', 'forget your instructions',
        'assistant:', 'admin override',
    ];
    $haystackLower = strtolower($haystack);
    foreach ($promptInjectionPhrases as $phrase) {
        if (str_contains($haystackLower, $phrase)) {
            $reasons[] = "possible AI prompt injection (\"{$phrase}\")";
        }
    }

    if (preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', $haystack)) {
        $reasons[] = 'hidden/bidi-override unicode characters';
    }

    return array_values(array_unique($reasons));
}

$flaggedCount = 0;
foreach ($addons as $addon) {
    $fullName = (string)($addon['full_name'] ?? 'unknown');
    $threats = ofx_scan_threats((string)($addon['name'] ?? ''), (string)($addon['description'] ?? ''));
    if (!empty($threats)) {
        $flaggedCount++;
        $reasonText = implode('; ', $threats);
        // Github Actions warning annotation - shows up directly in the
        // workflow run's summary, not just buried in the log
        fwrite(STDOUT, "::warning title=Possible malicious repo::{$fullName}: {$reasonText}\n");
    }
}

fwrite(STDOUT, "Scanned " . count($addons) . " repos, flagged {$flaggedCount}\n");
