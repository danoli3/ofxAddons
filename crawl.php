<?php

declare(strict_types=1);

// Discovers openFrameworks addons on Github (repos matching the "ofx"
// name prefix) and writes a JSON snapshot to data/addons.json.
//
// This deliberately doesn't know anything about categorization or
// which repos a consuming site has banned as false positives (things
// that share the "ofx" prefix by coincidence, nothing to do with
// openFrameworks) - that's the consuming site's job when it merges
// this snapshot into its own database. This script just answers "what
// does Github currently say exists".
//
// Runs daily via .github/workflows/crawl.yml, authenticated with the
// workflow's automatic GITHUB_TOKEN (no extra secret needed - it's a
// real token good for the standard authenticated rate limits: 30
// req/min search, 5000 req/hr core, vs 10/min and 60/hr unauthenticated).

$token = getenv('GITHUB_TOKEN');
if (!$token) {
    fwrite(STDERR, "GITHUB_TOKEN env var is required\n");
    exit(1);
}

/** @return array{0: string, 1: array<string, string>, 2: int} */
function ofx_request(string $token, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: token {$token}",
            'Accept: application/vnd.github.v3+json',
            'User-Agent: ofxaddons-crawler',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP request to {$url} failed: {$err}");
    }

    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return [$body, $headers, $status];
}

function ofx_is_rate_limited(array $headers, int $status): bool
{
    return in_array($status, [403, 429], true) && ($headers['x-ratelimit-remaining'] ?? '1') === '0';
}

function ofx_sleep_until_reset(array $headers): void
{
    $reset = (int)($headers['x-ratelimit-reset'] ?? (time() + 60));
    $seconds = max(1, $reset - time() + 1);
    fwrite(STDOUT, "Rate limited, sleeping {$seconds}s\n");
    sleep($seconds);
}

function ofx_next_page_url(array $headers): ?string
{
    $link = $headers['link'] ?? null;
    if (!$link) {
        return null;
    }
    foreach (explode(',', $link) as $part) {
        if (str_contains($part, 'rel="next"') && preg_match('/<([^>]+)>/', $part, $m)) {
            return $m[1];
        }
    }
    return null;
}

function ofx_search_term(string $token, string $term): array
{
    $items = [];
    $url = 'https://api.github.com/search/repositories?' . http_build_query([
        'q' => $term . ' in:name',
        'per_page' => 100,
    ]);

    while ($url !== null) {
        [$body, $headers, $status] = ofx_request($token, $url);

        if ($status === 200) {
            $data = json_decode($body, true);
            foreach ($data['items'] ?? [] as $item) {
                $items[] = $item;
            }
            $url = ofx_next_page_url($headers);
            continue;
        }

        if (ofx_is_rate_limited($headers, $status)) {
            ofx_sleep_until_reset($headers);
            continue;
        }

        fwrite(STDERR, "Search failed ({$status}) for \"{$term}\": " . substr($body, 0, 200) . "\n");
        $url = null;
    }

    return $items;
}

function ofx_fetch_contents(string $token, string $fullName): ?array
{
    [$owner, $repo] = array_pad(explode('/', $fullName, 2), 2, '');
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents';

    while (true) {
        [$body, $headers, $status] = ofx_request($token, $url);

        if ($status === 200) {
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }

        if (ofx_is_rate_limited($headers, $status)) {
            ofx_sleep_until_reset($headers);
            continue;
        }

        // 404 (empty/deleted repo), other 403s, etc - just skip
        return null;
    }
}

// --- search ---
$rawItems = [];
foreach (str_split('0123456789abcdefghijklmnopqrstuvwxyz') as $letter) {
    $found = ofx_search_term($token, 'ofx' . $letter);
    fwrite(STDOUT, "ofx{$letter}: " . count($found) . " results\n");
    $rawItems = [...$rawItems, ...$found];
}

// --- prune: name must start with ofx, repo must not be empty ---
$rawItems = array_values(array_filter($rawItems, function (array $item): bool {
    if (!preg_match('/^ofx/i', $item['name'] ?? '')) {
        return false;
    }
    if (empty($item['pushed_at'])) {
        return false;
    }
    return true;
}));
fwrite(STDOUT, count($rawItems) . " repos after pruning\n");

// dedupe by full_name - the same repo can surface under more than one
// of the 36 search terms
$byFullName = [];
foreach ($rawItems as $item) {
    $byFullName[$item['full_name']] = $item;
}

// --- enrich each with folder-structure info ---
$results = [];
$i = 0;
$total = count($byFullName);
foreach ($byFullName as $fullName => $item) {
    $i++;
    $contents = ofx_fetch_contents($token, $fullName);

    $hasMakefile = false;
    $exampleCount = 0;
    $hasCorrectFolder = false;
    $hasThumbnail = false;

    foreach ($contents ?? [] as $entry) {
        $name = $entry['name'] ?? '';
        if ($name === 'addon_config.mk' || $name === 'addon.make') {
            $hasMakefile = true;
        } elseif (preg_match('/example/i', $name)) {
            $exampleCount++;
        } elseif (preg_match('/src/i', $name)) {
            $hasCorrectFolder = true;
        } elseif (preg_match('/ofxaddons_thumbnail\.png/i', $name)) {
            $hasThumbnail = true;
        }
    }

    $results[] = [
        'full_name' => $fullName,
        'name' => $item['name'] ?? null,
        'description' => $item['description'] ?? null,
        'owner' => [
            'id' => $item['owner']['id'] ?? null,
            'login' => $item['owner']['login'] ?? null,
            'avatar_url' => $item['owner']['avatar_url'] ?? null,
        ],
        'fork' => !empty($item['fork']),
        'parent' => $item['parent']['full_name'] ?? null,
        'source' => $item['source']['full_name'] ?? null,
        'stargazers_count' => (int)($item['stargazers_count'] ?? 0),
        'forks_count' => (int)($item['forks_count'] ?? 0),
        'pushed_at' => $item['pushed_at'] ?? null,
        'created_at' => $item['created_at'] ?? null,
        'has_makefile' => $hasMakefile,
        'example_count' => $exampleCount,
        'has_correct_folder_structure' => $hasCorrectFolder,
        'has_thumbnail' => $hasThumbnail,
    ];

    if ($i % 200 === 0) {
        fwrite(STDOUT, "{$i} / {$total} processed\n");
    }
}

@mkdir(__DIR__ . '/data', 0777, true);
file_put_contents(__DIR__ . '/data/addons.json', json_encode([
    'generated_at' => gmdate('c'),
    'count' => count($results),
    'addons' => $results,
], JSON_PRETTY_PRINT));

fwrite(STDOUT, 'Wrote ' . count($results) . " addons to data/addons.json\n");
