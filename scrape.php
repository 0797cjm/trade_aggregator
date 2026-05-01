<?php
header('Content-Type: application/json');

function absolutize_url($base, $relative) {
    if (preg_match('#^https?://#i', $relative)) return $relative;
    if (str_starts_with($relative, '//')) return 'https:' . $relative;
    $baseParts = parse_url($base);
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) return $relative;
    $root = $baseParts['scheme'] . '://' . $baseParts['host'];
    if (str_starts_with($relative, '/')) return $root . $relative;
    $path = $baseParts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $root . $dir . $relative;
}

$localsData = json_decode(file_get_contents(__DIR__ . '/locals.json'), true);
$locals = [];
foreach ($localsData['locals'] as $l) {
    $locals[$l['id']] = $l;
}

// Whitelist: only scrape locals with dispatchMode = scrape
$allowed = array_filter($locals, fn($l) => $l['dispatchMode'] === 'scrape' && !empty($l['dispatchUrl']));

$id = $_GET['local'] ?? '';
if (!isset($allowed[$id])) {
    echo json_encode(['ok' => false, 'error' => 'Local not available for dispatch']);
    exit;
}

$local = $allowed[$id];
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheFile = $cacheDir . '/' . $id . '.json';
$cacheTTL = 3600; // 1 hour
$forceRefresh = ($_GET['refresh'] ?? '') === '1';

// Check cache
if (!$forceRefresh && file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    $cachedHtml = trim((string)($cached['html'] ?? ''));
    // Reject stale/empty cached blobs so we can self-heal after parser improvements.
    $plain = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($cachedHtml))));
    $cacheLooksEmpty = $cachedHtml === '' || strlen($plain) < 80;
    $hasDispatchSignal = preg_match('/call\s*#|contractor|personnel\s*required|date\s*required|dispatch/', $plain);
    if ($cached && !$cacheLooksEmpty && $hasDispatchSignal && (time() - strtotime($cached['fetchedAt'])) < $cacheTTL) {
        echo json_encode($cached);
        exit;
    }
}

// Fetch the dispatch page
$ch = curl_init($local['dispatchUrl']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_USERAGENT => 'BaymanBanter-DispatchAggregator/1.0 (+https://baymanbanter.com)',
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
$sourceUrl = $local['dispatchUrl'];

if ($html === false || $httpCode >= 400) {
    echo json_encode(['ok' => false, 'error' => $err ?: "HTTP $httpCode"]);
    exit;
}

// Some dispatch pages embed the real board inside an iframe.
if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
    $iframeUrl = absolutize_url($local['dispatchUrl'], html_entity_decode($m[1], ENT_QUOTES));
    $ch2 = curl_init($iframeUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'BaymanBanter-DispatchAggregator/1.0 (+https://baymanbanter.com)',
    ]);
    $iframeHtml = curl_exec($ch2);
    $iframeCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($iframeHtml !== false && $iframeCode < 400 && strlen(trim($iframeHtml)) > 100) {
        $html = $iframeHtml;
        $sourceUrl = $iframeUrl;
    }
}

// Parse DOM and extract the richest content container that likely holds dispatch calls.
$cleaned = '';
$dom = new DOMDocument();
libxml_use_internal_errors(true);
if ($dom->loadHTML($html)) {
    $xpath = new DOMXPath($dom);
    $dispatchTables = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' dispatch_dm ')]");
    if ($dispatchTables && $dispatchTables->length > 0) {
        $chunks = [];
        foreach ($dispatchTables as $t) {
            $frag = trim((string)$dom->saveHTML($t));
            if ($frag !== '') $chunks[] = $frag;
        }
        if (count($chunks) > 0) {
            $cleaned = implode("\n", $chunks);
        }
    }

    if ($cleaned === '') {
    $nodes = $xpath->query('//*[self::main or self::article or self::section or self::div]');
    $bestNode = null;
    $bestScore = -1;

    foreach ($nodes as $node) {
        $text = trim($node->textContent ?? '');
        if ($text === '') continue;
        $len = strlen(preg_replace('/\s+/', ' ', $text));

        $classId = strtolower(
            (($node->attributes->getNamedItem('class')?->nodeValue) ?? '') . ' ' .
            (($node->attributes->getNamedItem('id')?->nodeValue) ?? '')
        );
        $hasDispatchHint = preg_match('/dispatch|call\s*#|personnel|required|date\s*required|contractor/', strtolower($text . ' ' . $classId));
        if (!$hasDispatchHint) continue;

        $score = $len;
        if (preg_match('/call\s*#\s*\d+/i', $text)) $score += 1000;
        if (preg_match('/personnel\s*required/i', $text)) $score += 400;
        if (preg_match('/date\s*required/i', $text)) $score += 400;
        if (stripos($classId, 'entry-content') !== false) $score += 150;
        if (stripos($classId, 'content') !== false) $score += 80;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestNode = $node;
        }
    }

    if ($bestNode) {
        $frag = $dom->saveHTML($bestNode);
        $cleaned = trim((string)$frag);
    }
    }
}

if ($cleaned === '') {
    // Fallback: keep a safe subset if DOM extraction fails.
    $tmp = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $tmp = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $tmp);
    $tmp = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $tmp);
    $tmp = preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $tmp);
    $tmp = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $tmp);
    $cleaned = trim(strip_tags($tmp, '<table><thead><tbody><tr><th><td><a><b><strong><em><p><br><h1><h2><h3><h4><ul><ol><li><span><div>'));
}

$result = [
    'ok' => true,
    'html' => $cleaned,
    'parsedCallCount' => preg_match_all('/CALL\s*#\s*\d+/i', strip_tags($cleaned)),
    'parserVersion' => 'dispatch-parser-v2.2',
    'fetchedAt' => date('c'),
    'source' => $sourceUrl
];

file_put_contents($cacheFile, json_encode($result));
echo json_encode($result);
