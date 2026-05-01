<?php
header('Content-Type: application/json');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/news.json';
$cacheTTL = 10800;
$collectorVersion = 'trade-stories-v1';
$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if (!$refresh && file_exists($cacheFile)) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['fetchedAt']) && (time() - strtotime((string)$cached['fetchedAt'])) < $cacheTTL) {
        if (!isset($cached['meta']) || !is_array($cached['meta'])) $cached['meta'] = [];
        $cached['meta']['collectorVersion'] = $collectorVersion;
        $cached['meta']['cacheTtlSeconds'] = $cacheTTL;
        echo json_encode($cached);
        exit;
    }
}

function fetchUrlWithTimeoutNews($url, $timeoutSec) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(1, (int)$timeoutSec),
        CURLOPT_USERAGENT => 'BaymanBanter-NewsCollector/1.0 (+https://baymanbanter.com)',
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode >= 400) {
        return [null, $err ?: ('HTTP ' . $httpCode)];
    }
    return [$body, null];
}

function shortenExcerpt($text, $maxLen = 220) {
    $txt = trim((string)$text);
    if ($txt === '') return '';
    $txt = preg_replace('/\s+/', ' ', $txt);
    if (strlen($txt) <= $maxLen) return $txt;
    return rtrim(substr($txt, 0, $maxLen - 1)) . '…';
}

function parseDateToIso($value) {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('c', $ts);
}

function normalizeNewsItem($item, $defaultCategory, $defaultSource) {
    $title = trim((string)($item['title'] ?? ''));
    $source = trim((string)($item['source'] ?? $defaultSource));
    $url = trim((string)($item['url'] ?? ''));
    $publishedAt = isset($item['publishedAt']) ? trim((string)$item['publishedAt']) : null;
    $excerpt = shortenExcerpt((string)($item['excerpt'] ?? ''));
    $category = trim((string)($item['category'] ?? $defaultCategory));
    $image = isset($item['image']) ? trim((string)$item['image']) : null;

    if ($title === '' || $source === '' || $url === '' || $category === '') return null;

    return [
        'title' => $title,
        'source' => $source,
        'url' => $url,
        'publishedAt' => $publishedAt !== '' ? $publishedAt : null,
        'excerpt' => $excerpt,
        'image' => $image !== '' ? $image : null,
        'category' => $category,
    ];
}

function extractImageFromHtmlSummary($html) {
    $src = '';
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)$html, $m)) {
        $src = trim((string)$m[1]);
    }
    if ($src === '') return null;
    if (strpos($src, '//') === 0) return 'https:' . $src;
    return $src;
}

function buildGoogleNewsRssUrl($query) {
    $q = trim((string)$query);
    if ($q === '') return '';
    return 'https://news.google.com/rss/search?q=' . rawurlencode($q) . '&hl=en-CA&gl=CA&ceid=CA:en';
}

function parseRssEntries($xmlBody, $sourceName, $category, $maxItems = 10) {
    $out = [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody);
    if ($xml === false) return $out;

    $entries = [];
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) $entries[] = ['mode' => 'rss', 'node' => $item];
    } else {
        $atomNs = 'http://www.w3.org/2005/Atom';
        $children = $xml->children($atomNs);
        if (isset($children->entry)) {
            foreach ($children->entry as $entry) $entries[] = ['mode' => 'atom', 'node' => $entry];
        }
    }

    foreach ($entries as $entry) {
        if (count($out) >= $maxItems) break;

        $mode = $entry['mode'];
        $node = $entry['node'];
        $titleRaw = trim((string)($node->title ?? ''));
        if ($titleRaw === '') continue;

        $link = '';
        if ($mode === 'rss') {
            $link = trim((string)($node->link ?? ''));
        } else {
            foreach ($node->link as $lnk) {
                $attrs = $lnk->attributes();
                $href = trim((string)($attrs['href'] ?? ''));
                $rel = strtolower(trim((string)($attrs['rel'] ?? 'alternate')));
                if ($href === '') continue;
                if ($rel === 'alternate') {
                    $link = $href;
                    break;
                }
                if ($link === '') $link = $href;
            }
        }
        if ($link === '') continue;

        if (strpos($link, '//') === 0) {
            $link = 'https:' . $link;
        }

        $summaryHtml = '';
        if (isset($node->description)) $summaryHtml = (string)$node->description;
        if ($summaryHtml === '' && isset($node->summary)) $summaryHtml = (string)$node->summary;
        if ($summaryHtml === '' && isset($node->content)) $summaryHtml = (string)$node->content;

        $source = $sourceName;
        if (isset($node->source)) {
            $s = trim((string)$node->source);
            if ($s !== '') $source = $s;
        }

        $title = $titleRaw;
        if (preg_match('/^(.*?)\s+-\s+[^-]+$/u', $titleRaw, $m)) {
            $title = trim($m[1]);
        }

        $publishedAt = null;
        if (isset($node->pubDate)) $publishedAt = parseDateToIso((string)$node->pubDate);
        if ($publishedAt === null && isset($node->updated)) $publishedAt = parseDateToIso((string)$node->updated);
        if ($publishedAt === null && isset($node->published)) $publishedAt = parseDateToIso((string)$node->published);

        $item = normalizeNewsItem([
            'title' => $title,
            'source' => $source,
            'url' => $link,
            'publishedAt' => $publishedAt,
            'excerpt' => html_entity_decode(strip_tags($summaryHtml), ENT_QUOTES),
            'image' => extractImageFromHtmlSummary($summaryHtml),
            'category' => $category,
        ], $category, $sourceName);

        if ($item) $out[] = $item;
    }

    return $out;
}

function parseManualOrStaticSource($source, $defaultCategory) {
    $name = trim((string)($source['name'] ?? ''));
    $category = trim((string)($source['category'] ?? $defaultCategory));
    $url = trim((string)($source['url'] ?? ''));
    $title = trim((string)($source['title'] ?? $name));
    $excerpt = trim((string)($source['excerpt'] ?? ''));
    $publishedAt = isset($source['publishedAt']) ? trim((string)$source['publishedAt']) : null;

    $item = normalizeNewsItem([
        'title' => $title,
        'source' => $name,
        'url' => $url,
        'publishedAt' => $publishedAt,
        'excerpt' => $excerpt,
        'category' => $category,
    ], $category, $name);

    return $item ? [$item] : [];
}

function dedupeNews($items) {
    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $titleKey = strtolower(trim((string)$item['title']));
        $titleKey = preg_replace('/\s+/', ' ', $titleKey);
        $titleKey = preg_replace('/[^a-z0-9\s]/', '', $titleKey);
        $urlKey = strtolower(trim((string)$item['url']));
        $urlKey = preg_replace('/[#?].*$/', '', $urlKey);
        $key = $urlKey . '|' . $titleKey;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $item;
    }
    return $out;
}

$sourcesFile = __DIR__ . '/news_sources.json';
$sourcesData = [];
if (is_file($sourcesFile)) {
    $sourcesData = json_decode((string)file_get_contents($sourcesFile), true);
}
if (!is_array($sourcesData)) $sourcesData = [];

$errors = [];
$sourceCounts = [];
$unionNews = [];
$tradeResources = [];
$tradeNews = [];
$tradeTopicSuccess = false;

$unionSources = isset($sourcesData['union']) && is_array($sourcesData['union']) ? $sourcesData['union'] : [];
foreach ($unionSources as $source) {
    if (!is_array($source)) continue;
    $type = strtolower(trim((string)($source['type'] ?? 'manual')));
    $name = trim((string)($source['name'] ?? 'unknown'));

    $items = [];
    if ($type === 'rss') {
        $url = trim((string)($source['url'] ?? ''));
        if ($url !== '') {
            [$body, $err] = fetchUrlWithTimeoutNews($url, 10);
            if ($err) {
                $errors[] = $name . ' (rss): ' . $err;
            } else {
                $items = parseRssEntries($body, $name, 'union', 4);
            }
        }
    } else {
        $items = parseManualOrStaticSource($source, 'union');
    }

    if (!isset($sourceCounts[$name])) $sourceCounts[$name] = 0;
    $sourceCounts[$name] += count($items);
    $unionNews = array_merge($unionNews, $items);
}
$unionNews = array_values(array_slice(dedupeNews($unionNews), 0, 4));

$tradeSources = isset($sourcesData['trade']) && is_array($sourcesData['trade']) ? $sourcesData['trade'] : [];
foreach ($tradeSources as $source) {
    if (!is_array($source)) continue;
    $type = strtolower(trim((string)($source['type'] ?? 'manual')));
    $name = trim((string)($source['name'] ?? 'unknown'));

    $items = [];
    if ($type === 'topic_rss') {
        $url = trim((string)($source['url'] ?? ''));
        if ($url === '') $url = buildGoogleNewsRssUrl((string)($source['query'] ?? ''));
        $maxItems = (int)($source['maxItems'] ?? 2);
        if ($maxItems < 1) $maxItems = 1;
        if ($maxItems > 6) $maxItems = 6;

        if ($url !== '') {
            [$body, $err] = fetchUrlWithTimeoutNews($url, 10);
            if ($err) {
                $errors[] = $name . ' (topic_rss): ' . $err;
            } else {
                $items = parseRssEntries($body, $name, 'trade', $maxItems);
            }
        }
        if (!empty($items)) $tradeTopicSuccess = true;
        $tradeNews = array_merge($tradeNews, $items);
    } else {
        $items = parseManualOrStaticSource($source, 'trade');
        $tradeResources = array_merge($tradeResources, $items);
    }

    if (!isset($sourceCounts[$name])) $sourceCounts[$name] = 0;
    $sourceCounts[$name] += count($items);
}

$tradeResources = array_values(array_slice(dedupeNews($tradeResources), 0, 4));
$tradeNews = array_values(array_slice(dedupeNews($tradeNews), 0, 6));

if (!$tradeTopicSuccess && empty($tradeNews)) {
    $errors[] = 'trade_stories_unavailable';
}

$result = [
    'ok' => true,
    'fetchedAt' => gmdate('c'),
    'unionNews' => $unionNews,
    'tradeResources' => $tradeResources,
    'tradeNews' => $tradeNews,
    'errors' => $errors,
    'meta' => [
        'collectorVersion' => $collectorVersion,
        'cacheTtlSeconds' => $cacheTTL,
        'sourceCounts' => $sourceCounts,
    ],
];

file_put_contents($cacheFile, json_encode($result));
echo json_encode($result);
