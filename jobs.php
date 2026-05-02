<?php
header('Content-Type: application/json');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/jobs.json';
$cacheTTL = 1800; // 30 min

$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$selectedProvince = isset($_GET['province']) ? trim((string)$_GET['province']) : '';
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';

if (!$refresh && file_exists($cacheFile)) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if ($cached && isset($cached['fetchedAt']) && (time() - strtotime((string)$cached['fetchedAt'])) < $cacheTTL) {
        if ($selectedProvince !== '' && isset($cached['jobs']) && is_array($cached['jobs'])) {
            $cached['jobs'] = sortJobsForProvince($cached['jobs'], $selectedProvince);
        }
        if (isset($cached['jobs']) && is_array($cached['jobs'])) {
            $cached['jobs'] = applyDebugScoreReasons($cached['jobs'], $debugMode);
        }
        if (!isset($cached['meta']) || !is_array($cached['meta'])) {
            $cached['meta'] = [];
        }

        $cached['meta']['refreshable'] = true;
        $cached['meta']['selectedProvince'] = $selectedProvince;
        $cached['meta']['aggregatorVersion'] = 'jobs-normalized-v1.5';
        $cached['meta']['sourceCounts'] = $cached['meta']['sourceCounts'] ?? sourceCounts($cached['jobs'] ?? []);
        $cached['meta']['sourceErrors'] = $cached['meta']['sourceErrors'] ?? [];
        $cached['meta']['sourceTimingsMs'] = $cached['meta']['sourceTimingsMs'] ?? [];
        $cached['meta']['sourceStatus'] = $cached['meta']['sourceStatus'] ?? buildSourceStatusMeta(loadPrivateJobsConfig(), $cached['meta']['sourceErrors'], $cached['meta']['sourceCounts']);
        $cached['meta']['rejectedCountsBySource'] = $cached['meta']['rejectedCountsBySource'] ?? [];
        $cached['meta']['rejectedSamples'] = $cached['meta']['rejectedSamples'] ?? [];
        $cached['meta']['dedupeRemovedCount'] = $cached['meta']['dedupeRemovedCount'] ?? 0;
        $cached['meta']['dedupeSamples'] = $cached['meta']['dedupeSamples'] ?? [];
        $cached['meta']['highlightCounts'] = $cached['meta']['highlightCounts'] ?? [];
        $cached['meta']['highlightSamples'] = $cached['meta']['highlightSamples'] ?? [];
        $cached['meta']['jobBankDebug'] = $cached['meta']['jobBankDebug'] ?? null;
        $cached['meta']['fallbackCount'] = $cached['meta']['fallbackCount'] ?? countFallbackJobs($cached['jobs'] ?? []);
        $cached['meta']['totalFetchedBeforeDedupe'] = $cached['meta']['totalFetchedBeforeDedupe'] ?? count($cached['jobs'] ?? []);
        $cached['meta']['totalAfterDedupe'] = $cached['meta']['totalAfterDedupe'] ?? count($cached['jobs'] ?? []);
        $cached['meta']['totalReturned'] = count($cached['jobs'] ?? []);
        if (!$debugMode) {
            unset($cached['meta']['jobBankDebug']['attempts']);
        }

        echo json_encode($cached);
        exit;
    }
}

function fetchUrlWithTimeout($url, $timeoutSec) {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(1, (int)$timeoutSec),
        CURLOPT_USERAGENT => 'BaymanBanter-JobsAggregator/2.0 (+https://baymanbanter.com)',
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $timingMs = (int)round((microtime(true) - $start) * 1000);
    if ($body === false || $httpCode >= 400) {
        return [null, $err ?: "HTTP $httpCode", $timingMs];
    }
    return [$body, null, $timingMs];
}

function fetchUrl($url) {
    return fetchUrlWithTimeout($url, 12);
}

function provinceMap() {
    return [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];
}

function normalizeProvince($location) {
    $location = trim((string)$location);
    if ($location === '') return '';

    if (preg_match('/\b(AB|BC|MB|NB|NL|NS|NT|NU|ON|PE|QC|SK|YT)\b/i', $location, $m)) {
        $abbr = strtoupper($m[1]);
        $map = provinceMap();
        return $map[$abbr] ?? '';
    }

    foreach (provinceMap() as $name) {
        if (stripos($location, $name) !== false) {
            return $name;
        }
    }
    return '';
}

function extractPostedAtFromText($text) {
    $text = trim((string)$text);
    if ($text === '') return null;

    if (preg_match('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/i', $text, $m)) {
        $ts = strtotime($m[0]);
        if ($ts !== false) return date('c', $ts);
    }

    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) return date('c', $ts);
    }

    return null;
}

function scoreJobDetails($title, $company, $location, $source) {
    $hay = strtolower(trim($title . ' ' . $company . ' ' . $location . ' ' . $source));
    $rules = [
        ['pattern' => '/\binsulator(s)?\b/', 'points' => 16, 'reason' => 'insulator keyword'],
        ['pattern' => '/heat\s*(and|&)\s*frost/', 'points' => 15, 'reason' => 'heat and frost signal'],
        ['pattern' => '/mechanical\s+insulation/', 'points' => 14, 'reason' => 'mechanical insulation signal'],
        ['pattern' => '/\bindustrial\b/', 'points' => 10, 'reason' => 'industrial signal'],
        ['pattern' => '/\bpipe\b|\bpiping\b/', 'points' => 8, 'reason' => 'pipe/piping signal'],
        ['pattern' => '/fire\s*stopp?ing|firestop/', 'points' => 7, 'reason' => 'firestopping signal'],
        ['pattern' => '/\basbestos\b/', 'points' => 9, 'reason' => 'asbestos signal'],
        ['pattern' => '/\bred\s*seal\b/', 'points' => 7, 'reason' => 'red seal signal'],
        ['pattern' => '/\bjourneyperson\b|\bjourneyman\b/', 'points' => 6, 'reason' => 'journeyperson signal'],
        ['pattern' => '/\bapprentice\b/', 'points' => 6, 'reason' => 'apprentice signal'],
        ['pattern' => '/rope\s+access/', 'points' => 5, 'reason' => 'rope access signal'],
        ['pattern' => '/\bshutdown\b/', 'points' => 4, 'reason' => 'shutdown signal'],
        ['pattern' => '/\bcamp\b/', 'points' => 4, 'reason' => 'camp signal'],
        ['pattern' => '/\bresidential\b/', 'points' => -8, 'reason' => 'residential de-priority'],
        ['pattern' => '/\bdrywall\b/', 'points' => -9, 'reason' => 'drywall de-priority'],
        ['pattern' => '/\bfoam\b/', 'points' => -8, 'reason' => 'foam de-priority'],
    ];

    $score = 0;
    $reasons = [];
    foreach ($rules as $rule) {
        if (preg_match($rule['pattern'], $hay)) {
            $score += (int)$rule['points'];
            $reasons[] = $rule['reason'] . ' (' . ($rule['points'] >= 0 ? '+' : '') . $rule['points'] . ')';
        }
    }
    if (stripos($source, 'Union Feed') !== false) {
        $score += 3;
        $reasons[] = 'union feed bonus (+3)';
    }
    return ['score' => $score, 'reasons' => $reasons];
}

function scoreJob($title, $company, $location, $source) {
    $details = scoreJobDetails($title, $company, $location, $source);
    return (int)$details['score'];
}

function tagsForJob($title, $company, $location, $source) {
    $tags = [];
    $hay = strtolower(trim($title . ' ' . $company . ' ' . $location));

    if (preg_match('/\bmechanical\b/', $hay)) $tags[] = 'Mechanical';
    if (preg_match('/\bpipe\b|\bpiping\b/', $hay)) $tags[] = 'Pipe';
    if (preg_match('/heat\s*(and|&)\s*frost/', $hay)) $tags[] = 'Heat & Frost';
    if (preg_match('/\bindustrial\b/', $hay)) $tags[] = 'Industrial';
    if (preg_match('/fire\s*stopp?ing|firestop/', $hay)) $tags[] = 'Firestopping';
    if (preg_match('/\basbestos\b/', $hay)) $tags[] = 'Asbestos';
    if (preg_match('/\bapprentice\b/', $hay)) $tags[] = 'Apprentice';
    if (preg_match('/\bjourneyperson\b|\bjourneyman\b/', $hay)) $tags[] = 'Journeyperson';
    if (preg_match('/\bred\s*seal\b/', $hay)) $tags[] = 'Red Seal';
    if (preg_match('/\bcamp\b/', $hay)) $tags[] = 'Camp';
    if (preg_match('/\bshutdown\b/', $hay)) $tags[] = 'Shutdown';
    if (preg_match('/\bresidential\b/', $hay)) $tags[] = 'Residential';
    if (preg_match('/\bdrywall\b/', $hay)) $tags[] = 'Drywall';
    if (preg_match('/\bfoam\b/', $hay)) $tags[] = 'Foam';
    if (stripos($source, 'Union Feed') !== false) $tags[] = 'Union Feed';

    return array_values(array_unique($tags));
}

function appendUniqueValue(&$arr, $value) {
    $v = trim((string)$value);
    if ($v === '') return;
    foreach ($arr as $existing) {
        if (strcasecmp((string)$existing, $v) === 0) return;
    }
    $arr[] = $v;
}

function detectHighlightsFromText($text) {
    $highlights = [];
    $t = strtolower((string)$text);
    if ($t === '') return $highlights;

    if (preg_match('/\bcamp\b|accommodations?|lodging/i', $t)) $highlights[] = 'Camp';
    if (preg_match('/\bfifo\b|fly[\s-]*in[\s-]*fly[\s-]*out/i', $t)) $highlights[] = 'FIFO';
    if (preg_match('/\bred\s*seal\b/i', $t)) $highlights[] = 'Red Seal';
    if (preg_match('/\bshutdown\b/i', $t)) $highlights[] = 'Shutdown';
    if (preg_match('/\bloa\b|living[\s-]*out[\s-]*allowance/i', $t)) $highlights[] = 'LOA';
    if (preg_match('/\btravel\b|traveling|travelling/i', $t)) $highlights[] = 'Travel';
    if (preg_match('/\b(remote\s+(work|position|job|role|site|location)|work\s+remotely|fully\s+remote|100%\s+remote|remote-friendly)\b/i', $t)) $highlights[] = 'Remote';
    if (preg_match('/\bapprentice\b/i', $t)) $highlights[] = 'Apprentice';
    if (preg_match('/\bjourneyperson\b|\bjourneyman\b/i', $t)) $highlights[] = 'Journeyperson';
    return array_values(array_unique($highlights));
}

function enrichJobData($job, $extraText = '', $rawRow = null) {
    $title = (string)($job['title'] ?? '');
    $company = (string)($job['company'] ?? '');
    $location = (string)($job['location'] ?? '');
    $baseText = trim($title . ' ' . $company . ' ' . $location . ' ' . (string)$extraText);
    $highlights = detectHighlightsFromText($baseText);

    $perks = [];
    foreach ($highlights as $h) {
        if (in_array($h, ['Camp', 'FIFO', 'LOA', 'Travel', 'Remote'], true)) {
            appendUniqueValue($perks, $h);
        }
    }
    $requirements = [];
    foreach ($highlights as $h) {
        if (in_array($h, ['Red Seal', 'Apprentice', 'Journeyperson'], true)) {
            appendUniqueValue($requirements, $h);
        }
    }

    $employmentType = null;
    $rotation = null;
    $salary = isset($job['salary']) ? trim((string)$job['salary']) : '';
    if ($salary === '' && is_array($rawRow)) {
        $salaryKeys = ['salary', 'salary_text', 'wage', 'pay', 'salary_description'];
        foreach ($salaryKeys as $k) {
            if (isset($rawRow[$k]) && trim((string)$rawRow[$k]) !== '') {
                $salary = trim((string)$rawRow[$k]);
                break;
            }
        }
        if ($salary === '') {
            $min = isset($rawRow['salary_min']) ? trim((string)$rawRow['salary_min']) : '';
            $max = isset($rawRow['salary_max']) ? trim((string)$rawRow['salary_max']) : '';
            $currency = isset($rawRow['salary_currency']) ? trim((string)$rawRow['salary_currency']) : '';
            if ($min !== '' || $max !== '') {
                $salary = trim($currency . ' ' . ($min !== '' ? $min : '') . ($max !== '' ? (' - ' . $max) : ''));
            }
        }

        $employmentKeys = ['contract_type', 'employment_type', 'job_type', 'type', 'contracttype'];
        foreach ($employmentKeys as $k) {
            if (isset($rawRow[$k]) && trim((string)$rawRow[$k]) !== '') {
                $employmentType = trim((string)$rawRow[$k]);
                break;
            }
        }
        $rotationKeys = ['rotation', 'rotation_schedule', 'schedule'];
        foreach ($rotationKeys as $k) {
            if (isset($rawRow[$k]) && trim((string)$rawRow[$k]) !== '') {
                $cand = trim((string)$rawRow[$k]);
                if (preg_match('/\d+\s*(?:\/|-)\s*\d+|rotation|days?\s+on|days?\s+off/i', $cand)) {
                    $rotation = $cand;
                }
                break;
            }
        }
    }

    if ($salary === '' && preg_match('/(\$[\d\.,]+(?:\s*(?:to|-)\s*\$?[\d\.,]+)?(?:\s*\/\s*(?:h|hr|hour|day|week|month|year))?)/i', $baseText, $m)) {
        $salary = trim((string)$m[1]);
    }

    $job['salary'] = $salary !== '' ? $salary : null;
    $job['employmentType'] = $employmentType !== null && $employmentType !== '' ? $employmentType : null;
    $job['rotation'] = $rotation !== null && $rotation !== '' ? $rotation : null;
    $job['perks'] = array_values($perks);
    $job['requirements'] = array_values($requirements);
    $job['jobHighlights'] = array_values(array_unique($highlights));
    return $job;
}

function buildHighlightDiagnostics($jobs, &$counts, &$samples) {
    $counts = [];
    $samples = [];
    foreach ($jobs as $job) {
        $highlights = isset($job['jobHighlights']) && is_array($job['jobHighlights']) ? $job['jobHighlights'] : [];
        foreach ($highlights as $h) {
            $k = trim((string)$h);
            if ($k === '') continue;
            if (!isset($counts[$k])) $counts[$k] = 0;
            $counts[$k]++;
        }
        if (!empty($highlights) && count($samples) < 10) {
            $samples[] = [
                'title' => (string)($job['title'] ?? ''),
                'source' => (string)($job['source'] ?? ''),
                'highlights' => array_values($highlights),
            ];
        }
    }
    ksort($counts);
}

function normalizeJob($title, $company, $location, $url, $source, $postedAt = null) {
    $title = trim((string)$title);
    $company = trim((string)$company);
    $location = trim((string)$location);
    $url = trim((string)$url);
    $source = trim((string)$source);

    $province = normalizeProvince($location);
    $score = scoreJob($title, $company, $location, $source);
    $tags = tagsForJob($title, $company, $location, $source);
    $isFallback = (stripos($source, 'Indeed') !== false && stripos($title, 'Search Insulator Jobs') !== false)
        || (stripos($source, 'Careerjet') !== false && stripos($company, 'Careerjet Canada') !== false && stripos($title, 'Search Insulator Jobs') !== false)
        || (stripos($source, 'Job Bank') !== false && stripos($company, 'Job Bank Canada') !== false && stripos($title, 'Search Insulator Jobs') !== false);

    return [
        'title' => $title,
        'company' => $company,
        'location' => $location,
        'province' => $province,
        'url' => $url,
        'source' => $source,
        'postedAt' => $postedAt,
        'score' => $score,
        'tags' => $tags,
        'isFallback' => $isFallback,
        'salary' => null,
        'employmentType' => null,
        'rotation' => null,
        'perks' => [],
        'requirements' => [],
        'jobHighlights' => [],
        'ai' => [
            'requirementsSummary' => null,
            'requirementsBullets' => [],
        ],
    ];
}

function parseRssItems($xmlString, $sourceName) {
    $jobs = [];
    if (!$xmlString) return $jobs;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false || !isset($xml->channel->item)) {
        return $jobs;
    }

    foreach ($xml->channel->item as $item) {
        $title = trim((string)($item->title ?? ''));
        $link = trim((string)($item->link ?? ''));
        if ($title === '' || $link === '') continue;

        $descRaw = (string)($item->description ?? '');
        $desc = trim(strip_tags($descRaw));
        $company = '';
        $location = '';

        if (preg_match('/Company:\s*([^\n|]+?)(?:\s*\||$)/i', $desc, $m)) {
            $company = trim($m[1]);
        }
        if (preg_match('/Location:\s*([^\n|]+?)(?:\s*\||$)/i', $desc, $m)) {
            $location = trim($m[1]);
        }

        if ($company === '' || $location === '') {
            $parts = array_map('trim', explode(' - ', $title));
            if ($company === '' && count($parts) >= 2) {
                $company = $parts[count($parts) - 1];
            }
            if ($location === '' && count($parts) >= 3) {
                $location = $parts[count($parts) - 2];
            }
        }

        $postedAt = null;
        if (isset($item->pubDate)) {
            $pub = strtotime((string)$item->pubDate);
            if ($pub !== false) $postedAt = date('c', $pub);
        }

        $job = normalizeJob($title, $company, $location, $link, $sourceName, $postedAt);
        $jobs[] = enrichJobData($job, $descRaw);
    }

    return $jobs;
}

function parseJobBankRssItems($xmlString, &$stats = null) {
    $jobs = [];
    $stats = [
        'rssRowsFound' => 0,
        'rssRowsAccepted' => 0,
        'rssDirectUrlSamples' => [],
    ];
    if (!$xmlString) return $jobs;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) return $jobs;

    $entries = [];
    $mode = '';
    if (isset($xml->channel->item)) {
        $mode = 'rss';
        foreach ($xml->channel->item as $item) $entries[] = $item;
    } else {
        $atomNs = 'http://www.w3.org/2005/Atom';
        $atomChildren = $xml->children($atomNs);
        if (isset($atomChildren->entry)) {
            $mode = 'atom';
            foreach ($atomChildren->entry as $entry) $entries[] = $entry;
        }
    }
    if (empty($entries)) return $jobs;
    $stats['rssRowsFound'] = count($entries);

    foreach ($entries as $item) {
        $titleRaw = trim((string)($item->title ?? ''));
        $link = '';
        if ($mode === 'rss') {
            $link = trim((string)($item->link ?? ''));
        } else {
            $atomLinks = $item->link;
            foreach ($atomLinks as $al) {
                $attrs = $al->attributes();
                $rel = strtolower(trim((string)($attrs['rel'] ?? 'alternate')));
                $href = trim((string)($attrs['href'] ?? ''));
                if ($href === '') continue;
                if ($rel === 'alternate') {
                    $link = $href;
                    break;
                }
                if ($link === '') $link = $href;
            }
        }
        if ($titleRaw === '' || $link === '') continue;

        if (strpos($link, '//') === 0) {
            $link = 'https:' . $link;
        } elseif (strpos($link, 'http://') !== 0 && strpos($link, 'https://') !== 0) {
            $link = 'https://www.jobbank.gc.ca' . (strpos($link, '/') === 0 ? '' : '/') . ltrim($link, '/');
        }
        if (preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $link) !== 1) continue;

        $title = $titleRaw;
        if (preg_match('/^(.*?)\s*\((?:Verified|Expired)\)/i', $titleRaw, $tm)) {
            $title = trim($tm[1]);
        }

        $descRaw = (string)($item->description ?? '');
        if ($descRaw === '' && $mode === 'atom') {
            $descRaw = (string)($item->summary ?? '');
        }
        $desc = trim(html_entity_decode(strip_tags($descRaw), ENT_QUOTES));
        $company = 'Job Bank listing';
        $location = '';
        $jobNumber = '';
        $salary = '';

        if (preg_match('/\bEmployer:\s*(.+?)(?=\s*(?:<br|Location:|Salary:|Job number:|$))/i', $descRaw, $m)) {
            $company = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
        } elseif (preg_match('/\bEmployer:\s*(.+?)(?=\s*(?:Location:|Salary:|Job number:|$))/i', $desc, $m)) {
            $company = trim($m[1]);
        }

        if (preg_match('/\bLocation:\s*(.+?)(?=\s*(?:<br|Employer:|Salary:|Job number:|$))/i', $descRaw, $m)) {
            $location = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
        } elseif (preg_match('/\bLocation:\s*(.+?)(?=\s*(?:Employer:|Salary:|Job number:|$))/i', $desc, $m)) {
            $location = trim($m[1]);
        }

        if (preg_match('/\bSalary:\s*(.+?)(?=\s*(?:<br|Employer:|Location:|Job number:|$))/i', $descRaw, $m)) {
            $salary = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
        } elseif (preg_match('/\bSalary:\s*(.+?)(?=\s*(?:Employer:|Location:|Job number:|$))/i', $desc, $m)) {
            $salary = trim($m[1]);
        }

        if (preg_match('/Job\s*number[^0-9]*(\d{5,12})/i', $descRaw, $m) || preg_match('/Job\s*number[^0-9]*(\d{5,12})/i', $desc, $m)) {
            $jobNumber = (string)$m[1];
        } elseif (preg_match('~/jobsearch/jobposting/(\d+)~i', $link, $m)) {
            $jobNumber = (string)$m[1];
        }

        $postedAt = null;
        $dateRaw = '';
        if (isset($item->pubDate)) $dateRaw = (string)$item->pubDate;
        if ($dateRaw === '' && isset($item->updated)) $dateRaw = (string)$item->updated;
        if ($dateRaw === '' && isset($item->published)) $dateRaw = (string)$item->published;
        if ($dateRaw !== '') {
            $pub = strtotime($dateRaw);
            if ($pub !== false) $postedAt = date('c', $pub);
        }

        $job = normalizeJob($title, $company, $location, $link, 'Job Bank', $postedAt);
        if ($jobNumber !== '') $job['jobNumber'] = $jobNumber;
        if ($salary !== '') $job['salary'] = $salary;
        $job = enrichJobData($job, $descRaw);
        $job['isFallback'] = false;
        $jobs[] = $job;
        $stats['rssRowsAccepted']++;

        if (count($stats['rssDirectUrlSamples']) < 6) {
            $stats['rssDirectUrlSamples'][] = [
                'jobNumber' => $jobNumber,
                'title' => $title,
                'url' => $link,
            ];
        }
    }

    return $jobs;
}

function parseJobBankHtml($html, &$stats = null) {
    $jobs = [];
    $stats = [
        'rowsFound' => 0,
        'rowsRejected' => 0,
        'rowsAccepted' => 0,
        'rowsSuppressedNoDirectUrl' => 0,
        'resolvedUrlSamples' => [],
    ];

    if (!$html) return $jobs;

    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if ($plain === '') return $jobs;

    $anchorMatches = [];
    $officialAnchors = [];
    $postingUrlByJobNumber = [];
    if (preg_match_all('/<article[^>]*>(.*?)<\/article>/is', (string)$html, $articles, PREG_OFFSET_CAPTURE)) {
        foreach ($articles[1] as $articleHit) {
            $articleHtml = isset($articleHit[0]) ? (string)$articleHit[0] : '';
            if ($articleHtml === '') continue;

            if (!preg_match('/Job number:\s*(\d{5,12})/i', strip_tags($articleHtml), $jnm)) continue;
            $jobNumInArticle = (string)$jnm[1];

            if (!preg_match('/<a[^>]+href="([^"]+)"[^>]*>/i', $articleHtml, $linkm)) continue;
            $resolved = html_entity_decode((string)$linkm[1], ENT_QUOTES);
            if (strpos($resolved, '//') === 0) {
                $resolved = 'https:' . $resolved;
            } elseif (strpos($resolved, 'http://') !== 0 && strpos($resolved, 'https://') !== 0) {
                $resolved = 'https://www.jobbank.gc.ca' . (strpos($resolved, '/') === 0 ? '' : '/') . ltrim($resolved, '/');
            }

            if (preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $resolved) !== 1) continue;
            if (!isset($postingUrlByJobNumber[$jobNumInArticle])) {
                $postingUrlByJobNumber[$jobNumInArticle] = $resolved;
            }
        }
    }
    if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>/i', (string)$html, $anchors, PREG_OFFSET_CAPTURE)) {
        foreach ($anchors[1] as $hit) {
            $hrefRaw = isset($hit[0]) ? (string)$hit[0] : '';
            $offset = isset($hit[1]) ? (int)$hit[1] : -1;
            if ($hrefRaw === '' || $offset < 0) continue;
            $href = html_entity_decode($hrefRaw, ENT_QUOTES);
            if (!preg_match('/\bjn=(\d{5,12})\b/i', $href, $m)) continue;
            $jobNum = (string)$m[1];
            $resolved = $href;
            if (strpos($resolved, '//') === 0) {
                $resolved = 'https:' . $resolved;
            } elseif (strpos($resolved, 'http://') !== 0 && strpos($resolved, 'https://') !== 0) {
                $resolved = 'https://www.jobbank.gc.ca' . (strpos($resolved, '/') === 0 ? '' : '/') . ltrim($resolved, '/');
            }
            if (preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $resolved) === 1) {
                $officialAnchors[] = [
                    'href' => $resolved,
                    'offset' => $offset,
                ];
            }
            if (!isset($anchorMatches[$jobNum])) $anchorMatches[$jobNum] = [];
            $anchorMatches[$jobNum][] = [
                'href' => $resolved,
                'offset' => $offset,
            ];
        }
    }

    if (!preg_match_all('/Job number:\s*(\d{5,12})/i', $plain, $jobNums, PREG_OFFSET_CAPTURE)) {
        return $jobs;
    }

    $stats['rowsFound'] = count($jobNums[0]);

    for ($i = 0; $i < $stats['rowsFound']; $i++) {
        $jobNum = (string)$jobNums[1][$i][0];
        $fullOffset = (int)$jobNums[0][$i][1];
        $nextOffset = isset($jobNums[0][$i + 1]) ? (int)$jobNums[0][$i + 1][1] : strlen($plain);
        $start = max(0, $fullOffset - 380);
        $len = max(0, min($nextOffset + 220, strlen($plain)) - $start);
        $segment = trim(substr($plain, $start, $len));
        if ($segment === '') {
            $stats['rowsRejected']++;
            continue;
        }

        $title = '';
        if (preg_match('/Job number:\s*' . preg_quote($jobNum, '/') . '\s*(.*?)\s*-\s*Save to favourites/i', $segment, $m)) {
            $title = trim($m[1]);
        } elseif (preg_match('/Job number:\s*' . preg_quote($jobNum, '/') . '\s*(.*)$/i', $segment, $m)) {
            $title = trim(preg_replace('/\s*(Your favourites|Sign in|Sign up).*/i', '', $m[1]));
        }
        if ($title === '') {
            $title = 'Insulator';
        }

        $location = '';
        if (preg_match('/Location\s+(.+?)(?=\s+Salary\b|\s+Job number:|\s+-\s+Save to favourites|$)/i', $segment, $lm)) {
            $location = trim($lm[1]);
        }

        $company = 'Job Bank listing';
        if (preg_match('/(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\s+(.+?)\s+Location\b/i', $segment, $cm)) {
            $company = trim($cm[1]);
        } elseif (preg_match('/Job Bank\s+(.+?)\s+Location\b/i', $segment, $cm)) {
            $company = trim($cm[1]);
        }

        $postedAt = extractPostedAtFromText($segment);
        $hay = strtolower($title . ' ' . $company . ' ' . $location . ' ' . $segment);
        if (!preg_match('/insulator|insulation|heat\s*(and|&)\s*frost|asbestos|mechanical\s+insulation|journeyperson|journeyman|apprentice|rope\s+access|shutdown|camp/i', $hay)) {
            $stats['rowsRejected']++;
            continue;
        }

        $postingOffset = stripos((string)$html, 'Job number:' . $jobNum);
        if ($postingOffset === false) {
            $postingOffset = stripos((string)$html, 'Job number: ' . $jobNum);
        }

        $extractedHref = '';
        if (isset($postingUrlByJobNumber[$jobNum])) {
            $extractedHref = (string)$postingUrlByJobNumber[$jobNum];
        }
        if ($extractedHref === '' && isset($anchorMatches[$jobNum]) && is_array($anchorMatches[$jobNum]) && count($anchorMatches[$jobNum]) > 0) {
            $best = null;
            foreach ($anchorMatches[$jobNum] as $candidate) {
                $href = (string)($candidate['href'] ?? '');
                $off = (int)($candidate['offset'] ?? -1);
                if ($href === '' || $off < 0) continue;
                $isOfficialPosting = preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $href) === 1;
                $distance = ($postingOffset === false) ? PHP_INT_MAX : abs($off - (int)$postingOffset);
                $score = ($isOfficialPosting ? 0 : 1) * 1000000000 + $distance;
                if ($best === null || $score < $best['score']) {
                    $best = ['href' => $href, 'score' => $score];
                }
            }
            if ($best !== null) {
                $extractedHref = (string)$best['href'];
            }
        }
        if ($extractedHref === '' && $postingOffset !== false && count($officialAnchors) > 0) {
            $best = null;
            foreach ($officialAnchors as $candidate) {
                $href = (string)($candidate['href'] ?? '');
                $off = (int)($candidate['offset'] ?? -1);
                if ($href === '' || $off < 0) continue;
                $distance = abs($off - (int)$postingOffset);
                if ($distance > 5000) continue;
                if ($best === null || $distance < $best['distance']) {
                    $best = ['href' => $href, 'distance' => $distance];
                }
            }
            if ($best !== null) {
                $extractedHref = (string)$best['href'];
            }
        }

        if ($extractedHref === '' || preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $extractedHref) !== 1) {
            $stats['rowsSuppressedNoDirectUrl']++;
            $stats['rowsRejected']++;
            continue;
        }
        $usedFallbackUrl = false;
        $url = $extractedHref;
        $job = normalizeJob($title, $company, $location, $url, 'Job Bank', $postedAt);
        $job['jobNumber'] = $jobNum;
        $job = enrichJobData($job, $segment);
        $jobs[] = $job;
        $stats['rowsAccepted']++;

        if (count($stats['resolvedUrlSamples']) < 6) {
            $stats['resolvedUrlSamples'][] = [
                'jobNumber' => $jobNum,
                'title' => $title,
                'extractedHref' => $extractedHref,
                'finalUrl' => $url,
                'usedFallbackUrl' => $usedFallbackUrl,
            ];
        }
    }

    return $jobs;
}

function extractHtmlTitle($html) {
    if (!is_string($html) || $html === '') return '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
    }
    return '';
}

function analyzeJobBankHtml($html, $includeSnippets = false) {
    $plain = strtolower((string)preg_replace('/\s+/', ' ', strip_tags((string)$html)));
    $analysis = [
        'htmlLength' => strlen((string)$html),
        'pageTitle' => extractHtmlTitle($html),
        'markers' => [
            'job_number' => strpos($plain, 'job number') !== false,
            'insulator' => strpos($plain, 'insulator') !== false,
            'location' => strpos($plain, 'location') !== false,
            'employer' => strpos($plain, 'employer') !== false,
        ],
    ];
    if ($includeSnippets) {
        $clean = trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$html)));
        $snippets = [];
        if (preg_match_all('/.{0,90}Job number:\s*\d{5,12}.{0,140}/i', $clean, $m)) {
            $snippets = array_slice(array_map('trim', $m[0]), 0, 4);
        }
        $analysis['snippets'] = $snippets;
    }
    return $analysis;
}

function parseUnionFeedItems($xmlString, $sourceName) {
    $jobs = [];
    if (!$xmlString) return $jobs;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false || !isset($xml->channel->item)) return $jobs;

    foreach ($xml->channel->item as $item) {
        $title = trim((string)($item->title ?? ''));
        $link = trim((string)($item->link ?? ''));
        $desc = trim(strip_tags((string)($item->description ?? '')));
        if ($title === '' || $link === '') continue;

        $hay = strtolower($title . ' ' . $desc);
        if (!preg_match('/dispatch|call\s*#|job|hiring|apprentice|employment|insulator/', $hay)) continue;

        $postedAt = null;
        if (isset($item->pubDate)) {
            $pub = strtotime((string)$item->pubDate);
            if ($pub !== false) $postedAt = date('c', $pub);
        }

        $job = normalizeJob($title, $sourceName, 'Union update', $link, 'Union Feed', $postedAt);
        $jobs[] = enrichJobData($job, $desc);
    }
    return $jobs;
}

function loadPrivateJobsConfig() {
    $default = [
        'careerjet' => [
            'enabled' => false,
            'endpoint' => 'https://search.api.careerjet.net/v4/query',
            'apiKeyEnv' => 'CAREERJET_API_KEY',
            'apiKey' => null,
            'locale' => 'en_CA',
            'keywords' => 'insulator',
            'location' => 'Canada',
            'userIp' => '',
            'userAgent' => 'BaymanBanter-JobsAggregator/2.0 (+https://baymanbanter.com)',
            'referer' => 'https://baymanbanter.com/',
            'pages' => 1,
            'pageSize' => 20,
        ],
        'jooble' => [
            'enabled' => false,
            'apiKeyEnv' => 'JOOBLE_API_KEY',
            'apiKey' => null,
            'keywords' => 'insulator',
            'location' => 'Canada',
            'results' => 20,
        ],
        'adzuna' => [
            'enabled' => false,
            'appIdEnv' => 'ADZUNA_APP_ID',
            'appKeyEnv' => 'ADZUNA_APP_KEY',
            'appId' => null,
            'appKey' => null,
            'country' => 'ca',
            'what' => 'insulator',
            'where' => 'Canada',
            'resultsPerPage' => 20,
        ],
        'contractorSources' => [
            // Example item:
            // [
            //   'enabled' => false,
            //   'name' => 'ABC Industrial',
            //   'source' => 'Contractor Careers',
            //   'url' => 'https://example.com/careers',
            // ],
        ],
    ];

    $privateFile = dirname(__DIR__) . '/private/jobs_sources.php';
    if (is_file($privateFile)) {
        $loaded = include $privateFile;
        if (is_array($loaded)) {
            $default = array_replace_recursive($default, $loaded);
        }
    }

    return $default;
}

function resolveApiSecret($value, $envName) {
    if (is_string($value) && trim($value) !== '') return trim($value);
    if (!is_string($envName) || trim($envName) === '') return '';
    $v = getenv(trim($envName));
    return is_string($v) ? trim($v) : '';
}

function parseAdzunaDate($dateStr) {
    $ts = strtotime((string)$dateStr);
    return $ts !== false ? date('c', $ts) : null;
}

function parseCareerjetDate($dateStr) {
    $ts = strtotime((string)$dateStr);
    return $ts !== false ? date('c', $ts) : null;
}

function extractCareerjetLocation($row) {
    $candidates = [];
    if (isset($row['locations']) && is_string($row['locations'])) $candidates[] = $row['locations'];
    if (isset($row['location']) && is_string($row['location'])) $candidates[] = $row['location'];
    if (isset($row['loc']) && is_string($row['loc'])) $candidates[] = $row['loc'];
    if (isset($row['site']) && is_string($row['site'])) $candidates[] = $row['site'];
    if (isset($row['places']) && is_array($row['places'])) {
        $parts = [];
        foreach ($row['places'] as $v) {
            if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
        }
        if (!empty($parts)) $candidates[] = implode(', ', $parts);
    }
    foreach ($candidates as $c) {
        $v = trim((string)$c);
        if ($v !== '') return $v;
    }
    return '';
}

function fetchCareerjetAccessApiPage($endpoint, $apiKey, $query, $userAgent, $referer, &$err, &$httpCode, &$apiErrorInfo) {
    $start = microtime(true);
    $err = null;
    $httpCode = 0;
    $apiErrorInfo = null;

    $auth = base64_encode($apiKey . ':');
    $headers = [
        'Authorization: Basic ' . $auth,
    ];
    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }

    $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => $userAgent !== '' ? $userAgent : 'BaymanBanter-JobsAggregator/2.0 (+https://baymanbanter.com)',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $timingMs = (int)round((microtime(true) - $start) * 1000);
    if ($body === false || $httpCode >= 400) {
        $decoded = json_decode((string)$body, true);
        if (is_array($decoded)) {
            $apiErrorInfo = [
                'type' => trim((string)($decoded['type'] ?? $decoded['error_type'] ?? $decoded['code'] ?? '')),
                'message' => trim((string)($decoded['message'] ?? $decoded['error'] ?? $decoded['error_message'] ?? '')),
            ];
        }
        $err = $curlErr ?: ('HTTP ' . $httpCode);
        return [null, $timingMs];
    }
    $decoded = json_decode((string)$body, true);
    if (is_array($decoded) && (isset($decoded['error']) || isset($decoded['error_message']) || isset($decoded['message']) || isset($decoded['type']))) {
        $msg = trim((string)($decoded['error_message'] ?? $decoded['error'] ?? $decoded['message'] ?? ''));
        $type = trim((string)($decoded['type'] ?? $decoded['error_type'] ?? ''));
        if ($msg !== '' || $type !== '') {
            $apiErrorInfo = ['type' => $type, 'message' => $msg];
        }
    }
    return [$body, $timingMs];
}

function parseContractorPageJobs($html, $baseUrl, $sourceName) {
    $jobs = [];
    if (!is_string($html) || trim($html) === '') return $jobs;

    $plain = preg_replace('/\s+/', ' ', strip_tags($html));
    if (!preg_match_all('/.{0,220}(insulator|insulation|heat\s*(and|&)\s*frost|asbestos|mechanical\s+insulation|journeyperson|journeyman|apprentice|rope\s+access|shutdown|camp).{0,260}/i', (string)$plain, $hits)) {
        return $jobs;
    }

    $uniq = [];
    $i = 0;
    foreach ($hits[0] as $snippet) {
        $clean = trim($snippet);
        if ($clean === '') continue;
        $key = strtolower($clean);
        if (isset($uniq[$key])) continue;
        $uniq[$key] = true;
        $title = 'Insulation Opportunity';
        if (preg_match('/([A-Z][A-Za-z0-9 ,&\-\/]{4,90})\s+-\s+Save to favourites/i', $clean, $m)) {
            $title = trim($m[1]);
        } elseif (preg_match('/(insulator|insulation[^\.,;]{0,70}|asbestos[^\.,;]{0,70}|rope access[^\.,;]{0,70})/i', $clean, $m)) {
            $title = ucwords(strtolower(trim($m[1])));
        }

        $job = normalizeJob($title, $sourceName, '', $baseUrl, $sourceName, null);
        $jobs[] = enrichJobData($job, $clean);
        $i++;
        if ($i >= 8) break;
    }

    return $jobs;
}

function fetchOptionalApiJobs($apiConfig, $selectedProvince, &$errors, &$sourceErrors, &$sourceTimingsMs) {
    $jobs = [];

    if (!empty($apiConfig['careerjet']['enabled'])) {
        $apiKey = resolveApiSecret($apiConfig['careerjet']['apiKey'] ?? null, $apiConfig['careerjet']['apiKeyEnv'] ?? 'CAREERJET_API_KEY');
        if ($apiKey === '') {
            $errors[] = 'Careerjet API enabled but API key is missing';
            $sourceErrors['Careerjet'] = 'enabled_but_missing_api_key';
        } else {
            $endpoint = trim((string)($apiConfig['careerjet']['endpoint'] ?? 'https://search.api.careerjet.net/v4/query'));
            $locale = trim((string)($apiConfig['careerjet']['locale'] ?? 'en_CA'));
            $keywords = trim((string)($apiConfig['careerjet']['keywords'] ?? 'insulator'));
            $location = trim((string)($apiConfig['careerjet']['location'] ?? 'Canada'));
            $userIp = trim((string)($apiConfig['careerjet']['userIp'] ?? ''));
            if ($userIp === '' && isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
                $userIp = trim((string)$_SERVER['REMOTE_ADDR']);
            }
            if ($userIp === '') {
                $userIp = '127.0.0.1';
            }
            $userAgent = trim((string)($apiConfig['careerjet']['userAgent'] ?? 'BaymanBanter-JobsAggregator/2.0 (+https://baymanbanter.com)'));
            $referer = trim((string)($apiConfig['careerjet']['referer'] ?? 'https://baymanbanter.com/'));
            $pages = max(1, min((int)($apiConfig['careerjet']['pages'] ?? 1), 3));
            $pageSize = max(5, min((int)($apiConfig['careerjet']['pageSize'] ?? 20), 50));
            $allRows = [];
            $careerjetErr = null;
            $totalTimingMs = 0;
            $careerjetDebug = [];

            for ($page = 1; $page <= $pages; $page++) {
                $query = [
                    'locale_code' => $locale,
                    'keywords' => $keywords,
                    'location' => $location,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'user_ip' => $userIp,
                    'user_agent' => $userAgent,
                ];
                $httpCode = 0;
                $apiErrorInfo = null;
                [$body, $timingMs] = fetchCareerjetAccessApiPage($endpoint, $apiKey, $query, $userAgent, $referer, $err, $httpCode, $apiErrorInfo);
                $totalTimingMs += (int)$timingMs;
                $careerjetDebug[] = [
                    'page' => $page,
                    'httpCode' => $httpCode,
                    'errorType' => is_array($apiErrorInfo) ? (string)($apiErrorInfo['type'] ?? '') : '',
                    'errorMessage' => is_array($apiErrorInfo) ? (string)($apiErrorInfo['message'] ?? '') : '',
                ];
                if ($err) {
                    $msg = '';
                    if (is_array($apiErrorInfo)) {
                        $t = trim((string)($apiErrorInfo['type'] ?? ''));
                        $m = trim((string)($apiErrorInfo['message'] ?? ''));
                        if ($t !== '' || $m !== '') {
                            $msg = trim($t . ': ' . $m, ': ');
                        }
                    }
                    $careerjetErr = $err . ($msg !== '' ? (' | ' . $msg) : '');
                    break;
                }
                $decoded = json_decode((string)$body, true);
                if (!is_array($decoded)) {
                    $careerjetErr = 'invalid_json_response';
                    break;
                }
                $rows = [];
                if (isset($decoded['jobs']) && is_array($decoded['jobs'])) $rows = $decoded['jobs'];
                elseif (isset($decoded['results']) && is_array($decoded['results'])) $rows = $decoded['results'];
                elseif (isset($decoded['offers']) && is_array($decoded['offers'])) $rows = $decoded['offers'];
                $allRows = array_merge($allRows, $rows);
                if (count($rows) < $pageSize) break;
            }

            $sourceTimingsMs['Careerjet'] = (int)$totalTimingMs;
            $sourceTimingsMs['CareerjetDebug'] = $careerjetDebug;
            if ($careerjetErr !== null) {
                $errors[] = 'Careerjet API: ' . $careerjetErr;
                $sourceErrors['Careerjet'] = $careerjetErr;
            } else {
                foreach ($allRows as $row) {
                    if (!is_array($row)) continue;
                    $title = trim((string)($row['title'] ?? $row['job_title'] ?? ''));
                    $url = trim((string)($row['url'] ?? $row['redirect_url'] ?? $row['link'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    $job = normalizeJob(
                        $title,
                        trim((string)($row['company'] ?? $row['company_name'] ?? 'Careerjet listing')),
                        extractCareerjetLocation($row),
                        $url,
                        'Careerjet',
                        parseCareerjetDate((string)($row['date'] ?? $row['created'] ?? $row['posted_at'] ?? ''))
                    );
                    $parts = [];
                    $descKeys = ['description', 'snippet', 'summary', 'content', 'salary', 'salary_text', 'contract_type', 'employment_type', 'job_type', 'rotation', 'benefits'];
                    foreach ($descKeys as $k) {
                        if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') $parts[] = trim($row[$k]);
                    }
                    $jobs[] = enrichJobData($job, implode(' ', $parts), $row);
                }
            }
        }
    }

    if (!empty($apiConfig['jooble']['enabled'])) {
        $key = resolveApiSecret($apiConfig['jooble']['apiKey'] ?? null, $apiConfig['jooble']['apiKeyEnv'] ?? 'JOOBLE_API_KEY');
        if ($key === '') {
            $errors[] = 'Jooble enabled but no API key configured';
            $sourceErrors['Jooble'] = 'enabled_but_missing_api_key';
        } else {
            $keywords = trim((string)($apiConfig['jooble']['keywords'] ?? 'insulator'));
            $location = trim((string)($apiConfig['jooble']['location'] ?? 'Canada'));
            $results = (int)($apiConfig['jooble']['results'] ?? 20);
            $results = max(5, min($results, 50));
            $url = 'https://ca.jooble.org/api/' . rawurlencode($key);
            $payload = json_encode([
                'keywords' => $keywords,
                'location' => $location,
                'page' => 1,
            ]);
            $start = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'BaymanBanter-JobsAggregator/2.0 (+https://baymanbanter.com)',
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $sourceTimingsMs['Jooble'] = (int)round((microtime(true) - $start) * 1000);

            if ($body === false || $http >= 400) {
                $msg = $err ?: ('HTTP ' . $http);
                $errors[] = 'Jooble: ' . $msg;
                $sourceErrors['Jooble'] = $msg;
            } else {
                $decoded = json_decode((string)$body, true);
                $rows = is_array($decoded['jobs'] ?? null) ? $decoded['jobs'] : [];
                $rows = array_slice($rows, 0, $results);
                foreach ($rows as $row) {
                    $job = normalizeJob(
                        (string)($row['title'] ?? 'Insulator'),
                        (string)($row['company'] ?? 'Jooble listing'),
                        (string)($row['location'] ?? ''),
                        (string)($row['link'] ?? ''),
                        'Jooble',
                        null
                    );
                    $parts = [];
                    if (isset($row['snippet']) && is_string($row['snippet'])) $parts[] = $row['snippet'];
                    if (isset($row['salary']) && is_string($row['salary'])) $parts[] = $row['salary'];
                    $jobs[] = enrichJobData($job, implode(' ', $parts), $row);
                }
            }
        }
    }

    if (!empty($apiConfig['adzuna']['enabled'])) {
        $appId = resolveApiSecret($apiConfig['adzuna']['appId'] ?? null, $apiConfig['adzuna']['appIdEnv'] ?? 'ADZUNA_APP_ID');
        $appKey = resolveApiSecret($apiConfig['adzuna']['appKey'] ?? null, $apiConfig['adzuna']['appKeyEnv'] ?? 'ADZUNA_APP_KEY');
        if ($appId === '' || $appKey === '') {
            $errors[] = 'Adzuna enabled but app credentials are missing';
            $sourceErrors['Adzuna'] = 'enabled_but_missing_credentials';
        } else {
            $country = trim((string)($apiConfig['adzuna']['country'] ?? 'ca'));
            $what = trim((string)($apiConfig['adzuna']['what'] ?? 'insulator'));
            $where = trim((string)($apiConfig['adzuna']['where'] ?? 'Canada'));
            $resultsPerPage = (int)($apiConfig['adzuna']['resultsPerPage'] ?? 20);
            $resultsPerPage = max(5, min($resultsPerPage, 50));
            $url = 'https://api.adzuna.com/v1/api/jobs/' . rawurlencode($country) . '/search/1?app_id='
                . rawurlencode($appId) . '&app_key=' . rawurlencode($appKey)
                . '&what=' . rawurlencode($what) . '&where=' . rawurlencode($where)
                . '&results_per_page=' . $resultsPerPage . '&sort_by=date';
            [$body, $err, $timingMs] = fetchUrlWithTimeout($url, 10);
            $sourceTimingsMs['Adzuna'] = $timingMs;
            if ($err) {
                $errors[] = 'Adzuna: ' . $err;
                $sourceErrors['Adzuna'] = $err;
            } else {
                $decoded = json_decode((string)$body, true);
                $rows = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
                foreach ($rows as $row) {
                    $job = normalizeJob(
                        (string)($row['title'] ?? 'Insulator'),
                        (string)($row['company']['display_name'] ?? 'Adzuna listing'),
                        (string)($row['location']['display_name'] ?? ''),
                        (string)($row['redirect_url'] ?? ''),
                        'Adzuna',
                        parseAdzunaDate((string)($row['created'] ?? ''))
                    );
                    $parts = [];
                    if (isset($row['description']) && is_string($row['description'])) $parts[] = $row['description'];
                    if (isset($row['salary_min']) || isset($row['salary_max'])) {
                        $parts[] = trim((string)($row['salary_min'] ?? '')) . ' ' . trim((string)($row['salary_max'] ?? ''));
                    }
                    $jobs[] = enrichJobData($job, implode(' ', $parts), $row);
                }
            }
        }
    }

    if (!empty($apiConfig['contractorSources']) && is_array($apiConfig['contractorSources'])) {
        foreach ($apiConfig['contractorSources'] as $cfg) {
            if (empty($cfg['enabled'])) continue;
            $name = trim((string)($cfg['name'] ?? 'Contractor'));
            $sourceName = trim((string)($cfg['source'] ?? 'Contractor Careers'));
            $url = trim((string)($cfg['url'] ?? ''));
            if ($url === '') continue;
            [$body, $err, $timingMs] = fetchUrlWithTimeout($url, 10);
            $sourceKey = $sourceName . ': ' . $name;
            $sourceTimingsMs[$sourceKey] = $timingMs;
            if ($err) {
                $errors[] = $sourceKey . ': ' . $err;
                $sourceErrors[$sourceKey] = $err;
                continue;
            }
            $parsed = parseContractorPageJobs($body, $url, $sourceKey);
            $jobs = array_merge($jobs, $parsed);
        }
    }

    return $jobs;
}

function sortJobsForProvince($jobs, $selectedProvince) {
    $selectedProvince = trim((string)$selectedProvince);

    usort($jobs, function ($a, $b) use ($selectedProvince) {
        $aFallback = !empty($a['isFallback']) ? 1 : 0;
        $bFallback = !empty($b['isFallback']) ? 1 : 0;
        if ($aFallback !== $bFallback) return $aFallback <=> $bFallback;

        $aProvinceBoost = ($selectedProvince !== '' && strcasecmp((string)($a['province'] ?? ''), $selectedProvince) === 0) ? 1 : 0;
        $bProvinceBoost = ($selectedProvince !== '' && strcasecmp((string)($b['province'] ?? ''), $selectedProvince) === 0) ? 1 : 0;
        if ($aProvinceBoost !== $bProvinceBoost) return $bProvinceBoost <=> $aProvinceBoost;

        $aScore = (int)($a['score'] ?? 0);
        $bScore = (int)($b['score'] ?? 0);
        if ($aScore !== $bScore) return $bScore <=> $aScore;

        $aPosted = strtotime((string)($a['postedAt'] ?? '')) ?: 0;
        $bPosted = strtotime((string)($b['postedAt'] ?? '')) ?: 0;
        if ($aPosted !== $bPosted) return $bPosted <=> $aPosted;

        return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });

    return $jobs;
}

function sourceCounts($jobs) {
    $counts = [];
    foreach ($jobs as $job) {
        $source = (string)($job['source'] ?? 'Unknown');
        if (!isset($counts[$source])) $counts[$source] = 0;
        $counts[$source]++;
    }
    ksort($counts);
    return $counts;
}

function countFallbackJobs($jobs) {
    $count = 0;
    foreach ($jobs as $job) {
        $isFallback = !empty($job['isFallback'])
            || (stripos((string)($job['source'] ?? ''), 'Indeed') !== false && stripos((string)($job['title'] ?? ''), 'Search Insulator Jobs') !== false)
            || (stripos((string)($job['source'] ?? ''), 'Careerjet') !== false && stripos((string)($job['company'] ?? ''), 'Careerjet Canada') !== false && stripos((string)($job['title'] ?? ''), 'Search Insulator Jobs') !== false)
            || (stripos((string)($job['source'] ?? ''), 'Job Bank') !== false && stripos((string)($job['company'] ?? ''), 'Job Bank Canada') !== false && stripos((string)($job['title'] ?? ''), 'Search Insulator Jobs') !== false);
        if ($isFallback) $count++;
    }
    return $count;
}

function sourceHasRealJob($jobs, $sourceNeedle) {
    $needle = strtolower(trim((string)$sourceNeedle));
    if ($needle === '') return false;
    foreach ($jobs as $job) {
        $source = strtolower((string)($job['source'] ?? ''));
        if (strpos($source, $needle) === false) continue;
        if (empty($job['isFallback'])) return true;
    }
    return false;
}

function buildSourceStatusMeta($apiConfig, $sourceErrors, $sourceCounts) {
    $status = [];

    $status['Job Bank RSS'] = [
        'mode' => 'primary',
        'status' => isset($sourceErrors['Job Bank RSS']) ? 'error' : ((int)($sourceCounts['Job Bank'] ?? 0) > 0 ? 'fetched' : 'missing'),
    ];
    $status['Job Bank'] = [
        'mode' => 'enabled',
        'status' => isset($sourceErrors['Job Bank']) ? 'error' : ((int)($sourceCounts['Job Bank'] ?? 0) > 0 ? 'fetched' : 'missing'),
    ];
    $careerjetEnabled = !empty($apiConfig['careerjet']['enabled']);
    $careerjetApiKey = resolveApiSecret($apiConfig['careerjet']['apiKey'] ?? null, $apiConfig['careerjet']['apiKeyEnv'] ?? 'CAREERJET_API_KEY');
    if (!$careerjetEnabled) {
        $status['Careerjet'] = ['mode' => 'fallback_only', 'status' => 'config_missing'];
    } elseif ($careerjetApiKey === '') {
        $status['Careerjet'] = ['mode' => 'api_enabled', 'status' => 'missing_key'];
    } elseif (isset($sourceErrors['Careerjet'])) {
        $status['Careerjet'] = ['mode' => 'api_enabled', 'status' => 'error'];
    } else {
        $status['Careerjet'] = ['mode' => 'api_enabled', 'status' => ((int)($sourceCounts['Careerjet'] ?? 0) > 0 ? 'fetched' : 'error')];
    }
    $status['Indeed'] = [
        'mode' => 'fallback_only',
        'status' => 'fetched',
    ];

    $joobleEnabled = !empty($apiConfig['jooble']['enabled']);
    $joobleKey = resolveApiSecret($apiConfig['jooble']['apiKey'] ?? null, $apiConfig['jooble']['apiKeyEnv'] ?? 'JOOBLE_API_KEY');
    if (!$joobleEnabled) {
        $status['Jooble'] = ['mode' => 'disabled', 'status' => 'disabled'];
    } elseif ($joobleKey === '') {
        $status['Jooble'] = ['mode' => 'enabled', 'status' => 'missing_key'];
    } elseif (isset($sourceErrors['Jooble'])) {
        $status['Jooble'] = ['mode' => 'enabled', 'status' => 'error'];
    } else {
        $status['Jooble'] = ['mode' => 'enabled', 'status' => ((int)($sourceCounts['Jooble'] ?? 0) > 0 ? 'fetched' : 'missing')];
    }

    $adzunaEnabled = !empty($apiConfig['adzuna']['enabled']);
    $adzunaId = resolveApiSecret($apiConfig['adzuna']['appId'] ?? null, $apiConfig['adzuna']['appIdEnv'] ?? 'ADZUNA_APP_ID');
    $adzunaKey = resolveApiSecret($apiConfig['adzuna']['appKey'] ?? null, $apiConfig['adzuna']['appKeyEnv'] ?? 'ADZUNA_APP_KEY');
    if (!$adzunaEnabled) {
        $status['Adzuna'] = ['mode' => 'disabled', 'status' => 'disabled'];
    } elseif ($adzunaId === '' || $adzunaKey === '') {
        $status['Adzuna'] = ['mode' => 'enabled', 'status' => 'missing_key'];
    } elseif (isset($sourceErrors['Adzuna'])) {
        $status['Adzuna'] = ['mode' => 'enabled', 'status' => 'error'];
    } else {
        $status['Adzuna'] = ['mode' => 'enabled', 'status' => ((int)($sourceCounts['Adzuna'] ?? 0) > 0 ? 'fetched' : 'missing')];
    }

    return $status;
}

function applyDebugScoreReasons($jobs, $debugMode) {
    if (!$debugMode) {
        foreach ($jobs as &$job) {
            if (isset($job['scoreReason'])) unset($job['scoreReason']);
        }
        unset($job);
        return $jobs;
    }

    foreach ($jobs as &$job) {
        $details = scoreJobDetails(
            (string)($job['title'] ?? ''),
            (string)($job['company'] ?? ''),
            (string)($job['location'] ?? ''),
            (string)($job['source'] ?? '')
        );
        $job['scoreReason'] = $details['reasons'];
    }
    unset($job);
    return $jobs;
}

function normalizeTextForKey($value) {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/[^a-z0-9 ]+/', '', (string)$v);
    return trim((string)$v);
}

function isPublicApiSource($source) {
    $s = strtolower(trim((string)$source));
    return strpos($s, 'careerjet') !== false
        || strpos($s, 'jooble') !== false
        || strpos($s, 'adzuna') !== false;
}

function shouldRejectPublicJob($job, &$reason) {
    $reason = '';
    $source = (string)($job['source'] ?? '');
    $isJobBank = stripos($source, 'Job Bank') !== false;
    if (!isPublicApiSource($source) && !$isJobBank) return false;

    $title = (string)($job['title'] ?? '');
    $company = (string)($job['company'] ?? '');
    $location = (string)($job['location'] ?? '');
    $url = trim((string)($job['url'] ?? ''));
    $tags = isset($job['tags']) && is_array($job['tags']) ? implode(' ', $job['tags']) : '';
    $hay = strtolower(trim($title . ' ' . $company . ' ' . $location . ' ' . $tags));

    $strongRe = '/\binsulator(s)?\b|\binsulation\b|heat\s*(and|&)\s*frost|mechanical\s+insulation|fire\s*stopp?ing|asbestos|refractory|lagging|cladding|pipe\s+insulation|industrial\s+insulation|rope\s+access\s+insulator/i';
    $unrelatedRe = '/\belectrician(s)?\b|\bengineer(s)?\b|project\s+engineer|transmission\s+engineer|\bsoftware\b|\bsales\b|\bmanager(s)?\b|\bdriver(s)?\b|\bwarehouse\b|\bcleaner(s)?\b|general\s+labou?r(?!.*\binsulat)/i';
    $hasStrong = preg_match($strongRe, $hay) === 1;
    $hasUnrelated = preg_match($unrelatedRe, $hay) === 1;
    $hasApprentice = preg_match('/\bapprentice\b/i', $hay) === 1;
    $hasRopeAccess = preg_match('/rope\s+access/i', $hay) === 1;
    if ($isJobBank && preg_match('/\b(?:residential\s+drywall|general\s+drywall|drywall)\b/i', strtolower($title . ' ' . $company)) === 1) {
        $reason = 'jobbank_drywall_filtered';
        return true;
    }
    if ($isJobBank && preg_match('/^https?:\/\/(?:www\.)?jobbank\.gc\.ca\/jobsearch\/jobposting\//i', $url) !== 1) {
        $reason = 'jobbank_missing_direct_posting_url';
        return true;
    }

    if ($hasApprentice && !$hasStrong) {
        $reason = 'apprentice_without_insulation_terms';
        return true;
    }
    if ($hasRopeAccess && !$hasStrong) {
        $reason = 'rope_access_without_insulation_terms';
        return true;
    }
    if (!$hasStrong && $hasUnrelated) {
        $reason = 'unrelated_role_without_insulation_terms';
        return true;
    }
    if (!$hasStrong) {
        $reason = 'missing_strong_insulation_terms';
        return true;
    }
    return false;
}

function sourcePriorityForDedupe($source) {
    $s = strtolower(trim((string)$source));
    if (strpos($s, 'job bank') !== false) return 50;
    if (strpos($s, 'careerjet') !== false) return 40;
    if (strpos($s, 'jooble') !== false) return 35;
    if (strpos($s, 'adzuna') !== false) return 34;
    if (strpos($s, 'indeed') !== false) return 10;
    return 30;
}

function chooseBetterJobForDedupe($a, $b) {
    $aFallback = !empty($a['isFallback']) ? 1 : 0;
    $bFallback = !empty($b['isFallback']) ? 1 : 0;
    if ($aFallback !== $bFallback) return $aFallback < $bFallback ? $a : $b;

    $aPriority = sourcePriorityForDedupe((string)($a['source'] ?? ''));
    $bPriority = sourcePriorityForDedupe((string)($b['source'] ?? ''));
    if ($aPriority !== $bPriority) return $aPriority > $bPriority ? $a : $b;

    $aScore = (int)($a['score'] ?? 0);
    $bScore = (int)($b['score'] ?? 0);
    if ($aScore !== $bScore) return $aScore > $bScore ? $a : $b;

    $aPosted = strtotime((string)($a['postedAt'] ?? '')) ?: 0;
    $bPosted = strtotime((string)($b['postedAt'] ?? '')) ?: 0;
    if ($aPosted !== $bPosted) return $aPosted > $bPosted ? $a : $b;

    $aUrl = trim((string)($a['url'] ?? ''));
    $bUrl = trim((string)($b['url'] ?? ''));
    if ($aUrl !== '' && $bUrl === '') return $a;
    if ($bUrl !== '' && $aUrl === '') return $b;

    return $a;
}

function dedupeJobsWithDiagnostics($jobs, &$removedCount, &$samples) {
    $result = [];
    $removedCount = 0;
    $samples = [];
    $indexByKey = [];
    $indexByCareerjetKey = [];

    foreach ($jobs as $job) {
        $titleKey = normalizeTextForKey((string)($job['title'] ?? ''));
        $companyKey = normalizeTextForKey((string)($job['company'] ?? ''));
        $locRaw = trim((string)($job['location'] ?? ''));
        $provRaw = trim((string)($job['province'] ?? ''));
        $locKey = normalizeTextForKey($locRaw !== '' ? $locRaw : $provRaw);
        $baseKey = $titleKey . '|' . $companyKey . '|' . $locKey;
        $source = strtolower((string)($job['source'] ?? ''));
        $careerjetKey = '';
        if (strpos($source, 'careerjet') !== false) {
            $careerjetKey = 'cj|' . $titleKey . '|' . $companyKey;
        }

        $existingIndex = null;
        $existingReason = '';
        if (isset($indexByKey[$baseKey])) {
            $existingIndex = (int)$indexByKey[$baseKey];
            $existingReason = 'same_title_company_location';
        } elseif ($careerjetKey !== '' && isset($indexByCareerjetKey[$careerjetKey])) {
            $existingIndex = (int)$indexByCareerjetKey[$careerjetKey];
            $existingReason = 'near_identical_careerjet';
        }

        if ($existingIndex === null) {
            $result[] = $job;
            $newIdx = count($result) - 1;
            $indexByKey[$baseKey] = $newIdx;
            if ($careerjetKey !== '') $indexByCareerjetKey[$careerjetKey] = $newIdx;
            continue;
        }

        $existing = $result[$existingIndex];
        $preferred = chooseBetterJobForDedupe($existing, $job);
        $removedCount++;

        $removedJob = $preferred === $existing ? $job : $existing;
        if (count($samples) < 10) {
            $samples[] = [
                'reason' => $existingReason,
                'removedTitle' => (string)($removedJob['title'] ?? ''),
                'removedCompany' => (string)($removedJob['company'] ?? ''),
                'removedSource' => (string)($removedJob['source'] ?? ''),
                'keptSource' => (string)($preferred['source'] ?? ''),
            ];
        }

        if ($preferred !== $existing) {
            $result[$existingIndex] = $preferred;
            $newSource = strtolower((string)($preferred['source'] ?? ''));
            if (strpos($newSource, 'careerjet') !== false) {
                $newCareerjetKey = 'cj|' . normalizeTextForKey((string)($preferred['title'] ?? '')) . '|' . normalizeTextForKey((string)($preferred['company'] ?? ''));
                $indexByCareerjetKey[$newCareerjetKey] = $existingIndex;
            }
        }
    }

    return $result;
}

function jobBankMergeKey($job) {
    $jobNum = trim((string)($job['jobNumber'] ?? ''));
    if ($jobNum !== '') return 'jn|' . $jobNum;
    $title = normalizeTextForKey((string)($job['title'] ?? ''));
    $company = normalizeTextForKey((string)($job['company'] ?? ''));
    $location = normalizeTextForKey((string)($job['location'] ?? ''));
    $province = normalizeTextForKey((string)($job['province'] ?? ''));
    return 'tcl|' . $title . '|' . $company . '|' . ($location !== '' ? $location : $province);
}

function mergeJobBankRssAndHtml($rssJobs, $htmlJobs, &$rssPreferredDuplicates, &$samples) {
    $rssPreferredDuplicates = 0;
    $samples = [];
    $merged = [];
    $idxByKey = [];

    foreach ($rssJobs as $job) {
        $job['jobBankOrigin'] = 'rss';
        $key = jobBankMergeKey($job);
        $idxByKey[$key] = count($merged);
        $merged[] = $job;
    }

    foreach ($htmlJobs as $job) {
        $job['jobBankOrigin'] = 'html';
        $key = jobBankMergeKey($job);
        if (isset($idxByKey[$key])) {
            $rssPreferredDuplicates++;
            $existing = $merged[(int)$idxByKey[$key]];
            if (empty($existing['salary']) && !empty($job['salary'])) {
                $merged[(int)$idxByKey[$key]]['salary'] = $job['salary'];
            }
            continue;
        }
        $idxByKey[$key] = count($merged);
        $merged[] = $job;
    }

    foreach ($merged as $job) {
        if (count($samples) >= 10) break;
        $samples[] = [
            'title' => (string)($job['title'] ?? ''),
            'company' => (string)($job['company'] ?? ''),
            'location' => (string)($job['location'] ?? ''),
            'url' => (string)($job['url'] ?? ''),
            'isFallback' => !empty($job['isFallback']),
            'jobNumber' => (string)($job['jobNumber'] ?? ''),
        ];
    }

    return $merged;
}

$feeds = [
    [
        'source' => 'Job Bank',
        'rssUrl' => 'https://www.jobbank.gc.ca/jobsearch/feed/jobSearchRSSfeed?fage=2&fcid=6994&fcid=6995&fcid=7000&fcid=7003&fcid=7010&fcid=7073&fn21=72321&term=insulator&sort=D&rows=100',
        'urls' => [
            'https://www.jobbank.gc.ca/jobsearch/jobsearch?fn21=72321&page=1&sort=D',
            'https://www.jobbank.gc.ca/jobsearch/jobsearch?searchstring=insulator&sort=D',
            'https://www.jobbank.gc.ca/jobsearch/jobsearch?searchstring=heat+and+frost+insulator&sort=D',
        ],
    ],
    ['source' => 'Local 110 Feed', 'url' => 'https://insulators110.com/feed/'],
    ['source' => 'Local 110 Dispatch Category', 'url' => 'https://insulators110.com/category/dispatch/feed/'],
    ['source' => 'Local 119 Dispatch Category', 'url' => 'https://www.local119.com/category/dispatch/feed'],
];

$allJobs = [];
$errors = [];
$sourceErrors = [];
$sourceTimingsMs = [];
$jobBankDebug = [];
$rejectedCountsBySource = [];
$rejectedSamples = [];

foreach ($feeds as $feed) {
    if ($feed['source'] === 'Job Bank') {
        $rssUrl = trim((string)($feed['rssUrl'] ?? ''));
        $jobBankUrls = isset($feed['urls']) && is_array($feed['urls']) ? $feed['urls'] : [];
        $selectedBody = null;
        $selectedStats = null;
        $selectedUrl = '';
        $attempts = [];
        $rssJobs = [];
        $htmlJobs = [];
        $htmlSupplementUsed = false;
        $rssPreferredDuplicates = 0;
        $mergedSamples = [];

        $jobBankDebug['rssUrl'] = $rssUrl;
        $jobBankDebug['htmlFallbackUsed'] = false;
        $jobBankDebug['htmlSupplementUsed'] = false;
        if ($rssUrl !== '') {
            [$rssBody, $rssErr, $rssTimingMs] = fetchUrl($rssUrl);
            $sourceTimingsMs['Job Bank RSS'] = $rssTimingMs;
            if ($rssErr) {
                $sourceErrors['Job Bank RSS'] = $rssErr;
            } else {
                $rssStats = null;
                $rssJobs = parseJobBankRssItems($rssBody, $rssStats);
                $jobBankDebug['rssRowsFound'] = (int)($rssStats['rssRowsFound'] ?? 0);
                $jobBankDebug['rssRowsAccepted'] = (int)($rssStats['rssRowsAccepted'] ?? 0);
                $jobBankDebug['rssDirectUrlSamples'] = $rssStats['rssDirectUrlSamples'] ?? [];
                $sourceTimingsMs['Job Bank RSS Parser'] = $rssStats;
                if (empty($rssJobs)) {
                    $sourceErrors['Job Bank RSS'] = 'rss_parser_found_zero_rows';
                }
            }
        }

        foreach ($jobBankUrls as $jbUrl) {
            [$jbBody, $jbErr, $jbTimingMs] = fetchUrl($jbUrl);
            $attempt = [
                'url' => $jbUrl,
                'timingMs' => $jbTimingMs,
                'fetchError' => $jbErr,
            ];

            if ($jbErr) {
                $attempts[] = $attempt;
                continue;
            }
            $htmlSupplementUsed = true;

            $jbStats = null;
            $jbJobs = parseJobBankHtml($jbBody, $jbStats);
            $attempt['parser'] = $jbStats;
            $attempt['analysis'] = analyzeJobBankHtml($jbBody, $debugMode);
            $attempts[] = $attempt;

            if (is_array($jbStats) && (int)$jbStats['rowsAccepted'] > 0) {
                $selectedBody = $jbBody;
                $selectedStats = $jbStats;
                $selectedUrl = $jbUrl;
                $htmlJobs = $jbJobs;
                break;
            }

            if ($selectedBody === null) {
                $selectedBody = $jbBody;
                $selectedStats = $jbStats;
                $selectedUrl = $jbUrl;
                $htmlJobs = $jbJobs;
            }
        }

        $jobBankDebug['htmlFallbackUsed'] = ($selectedBody !== null);
        $jobBankDebug['htmlSupplementUsed'] = $htmlSupplementUsed;
        $jobBankDebug['directUrlRequired'] = true;
        $jobBankDebug['selectedUrl'] = $selectedUrl;
        $jobBankDebug['attempts'] = $attempts;
        $sourceTimingsMs['Job Bank'] = array_map(function ($a) {
            return ['url' => $a['url'], 'timingMs' => $a['timingMs']];
        }, $attempts);
        $jobBankDebug['htmlRowsFound'] = (int)($selectedStats['rowsFound'] ?? 0);
        $jobBankDebug['htmlRowsAccepted'] = (int)($selectedStats['rowsAccepted'] ?? 0);
        $jobBankDebug['htmlRowsSuppressedNoDirectUrl'] = (int)($selectedStats['rowsSuppressedNoDirectUrl'] ?? 0);

        if ($selectedBody === null && empty($rssJobs)) {
            $sourceErrors['Job Bank'] = 'all_fetch_attempts_failed';
            $errors[] = 'Job Bank: all_fetch_attempts_failed';
        }

        if ($selectedBody !== null) {
            $sourceTimingsMs['Job Bank Parser'] = $selectedStats;
        }

        if (empty($rssJobs) && is_array($selectedStats) && (int)$selectedStats['rowsFound'] === 0) {
            $sourceErrors['Job Bank'] = 'html_fetched_parser_found_zero_rows';
        } elseif (empty($rssJobs) && is_array($selectedStats) && (int)$selectedStats['rowsAccepted'] === 0) {
            $sourceErrors['Job Bank'] = 'parser_found_rows_but_rejected_all';
        }

        $mergedJobBankJobs = mergeJobBankRssAndHtml($rssJobs, $htmlJobs, $rssPreferredDuplicates, $mergedSamples);
        $jobBankDebug['mergedRowsAccepted'] = count($mergedJobBankJobs);
        $jobBankDebug['htmlRowsRendered'] = 0;
        foreach ($mergedJobBankJobs as $jbRow) {
            $jbSource = (string)($jbRow['source'] ?? '');
            if (stripos($jbSource, 'Job Bank') === false) continue;
            if (!empty($jbRow['jobNumber']) && !empty($jbRow['jobBankOrigin']) && $jbRow['jobBankOrigin'] === 'html') {
                $jobBankDebug['htmlRowsRendered']++;
            }
        }
        $jobBankDebug['rssPreferredDuplicates'] = $rssPreferredDuplicates;
        $jobBankDebug['mergedSamples'] = $mergedSamples;
        $jobBankDebug['rssRowsFound'] = $jobBankDebug['rssRowsFound'] ?? 0;
        $jobBankDebug['rssRowsAccepted'] = $jobBankDebug['rssRowsAccepted'] ?? 0;
        $jobBankDebug['rssDirectUrlSamples'] = $jobBankDebug['rssDirectUrlSamples'] ?? [];
        $allJobs = array_merge($allJobs, $mergedJobBankJobs);
        continue;
    }

    [$body, $err, $timingMs] = fetchUrl($feed['url']);
    $sourceTimingsMs[$feed['source']] = $timingMs;

    if ($err) {
        $errors[] = $feed['source'] . ': ' . $err;
        $sourceErrors[$feed['source']] = $err;
        continue;
    }

    if ($feed['source'] === 'Local 110 Feed' || $feed['source'] === 'Local 110 Dispatch Category' || $feed['source'] === 'Local 119 Dispatch Category') {
        $feedJobs = parseUnionFeedItems($body, $feed['source']);
        $allJobs = array_merge($allJobs, $feedJobs);
        continue;
    }

    if (stripos($body, '<rss') !== false || stripos($body, '<channel') !== false) {
        $feedJobs = parseRssItems($body, $feed['source']);
        $allJobs = array_merge($allJobs, $feedJobs);
    }
}

$apiConfig = loadPrivateJobsConfig();
$allJobs = array_merge($allJobs, fetchOptionalApiJobs($apiConfig, $selectedProvince, $errors, $sourceErrors, $sourceTimingsMs));
$filteredJobs = [];
foreach ($allJobs as $job) {
    $reason = '';
    if (shouldRejectPublicJob($job, $reason)) {
        $source = (string)($job['source'] ?? 'Unknown');
        if (!isset($rejectedCountsBySource[$source])) $rejectedCountsBySource[$source] = 0;
        $rejectedCountsBySource[$source]++;
        if (count($rejectedSamples) < 10) {
            $rejectedSamples[] = [
                'title' => (string)($job['title'] ?? ''),
                'source' => $source,
                'reason' => $reason,
            ];
        }
        continue;
    }
    $filteredJobs[] = $job;
}

$allJobsBeforeFallback = $filteredJobs;

if (!sourceHasRealJob($allJobsBeforeFallback, 'Indeed')) {
    $filteredJobs[] = normalizeJob(
        'Search Insulator Jobs',
        'Indeed Canada',
        'Canada',
        'https://ca.indeed.com/jobs?q=insulator&l=Canada',
        'Indeed',
        null
    );
}

if (!sourceHasRealJob($allJobsBeforeFallback, 'Careerjet')) {
    $filteredJobs[] = normalizeJob(
        'Search Insulator Jobs',
        'Careerjet Canada',
        'Canada',
        'https://www.careerjet.ca/search/jobs?s=insulator&l=Canada',
        'Careerjet',
        null
    );
}

if (!sourceHasRealJob($allJobsBeforeFallback, 'Job Bank')) {
    $filteredJobs[] = normalizeJob(
        'Search Insulator Jobs',
        'Job Bank Canada',
        'Canada',
        'https://www.jobbank.gc.ca/jobsearch/jobsearch?searchstring=insulator&sort=D',
        'Job Bank',
        null
    );
}

$totalFetchedBeforeDedupe = count($filteredJobs);
$dedupeRemovedCount = 0;
$dedupeSamples = [];
$jobs = dedupeJobsWithDiagnostics($filteredJobs, $dedupeRemovedCount, $dedupeSamples);

$jobs = sortJobsForProvince($jobs, $selectedProvince);
$totalAfterDedupe = count($jobs);
$jobs = array_slice($jobs, 0, 60);
$fallbackCount = countFallbackJobs($jobs);
$jobsForResponse = applyDebugScoreReasons($jobs, $debugMode);
$jobBankMeta = null;
if (!empty($jobBankDebug)) {
    $jobBankMeta = $jobBankDebug;
    $renderedHtmlRows = 0;
    foreach ($jobsForResponse as $row) {
        if (stripos((string)($row['source'] ?? ''), 'Job Bank') === false) continue;
        if ((string)($row['jobBankOrigin'] ?? '') !== 'html') continue;
        $renderedHtmlRows++;
    }
    $jobBankMeta['htmlRowsRendered'] = $renderedHtmlRows;
    if (!$debugMode && isset($jobBankMeta['attempts'])) {
        unset($jobBankMeta['attempts']);
    }
}
$sourceCountsMeta = sourceCounts($jobs);
$sourceStatusMeta = buildSourceStatusMeta($apiConfig, $sourceErrors, $sourceCountsMeta);
$highlightCounts = [];
$highlightSamples = [];
buildHighlightDiagnostics($jobs, $highlightCounts, $highlightSamples);

$result = [
    'ok' => true,
    'jobs' => $jobsForResponse,
    'fetchedAt' => date('c'),
    'errors' => $errors,
    'meta' => [
        'refreshable' => true,
        'selectedProvince' => $selectedProvince,
        'aggregatorVersion' => 'jobs-normalized-v1.5',
        'sourceCounts' => $sourceCountsMeta,
        'sourceErrors' => $sourceErrors,
        'sourceTimingsMs' => $sourceTimingsMs,
        'sourceStatus' => $sourceStatusMeta,
        'rejectedCountsBySource' => $rejectedCountsBySource,
        'rejectedSamples' => $rejectedSamples,
        'dedupeRemovedCount' => $dedupeRemovedCount,
        'dedupeSamples' => $dedupeSamples,
        'highlightCounts' => $highlightCounts,
        'highlightSamples' => $highlightSamples,
        'jobBankDebug' => $jobBankMeta,
        'totalFetchedBeforeDedupe' => $totalFetchedBeforeDedupe,
        'totalAfterDedupe' => $totalAfterDedupe,
        'totalReturned' => count($jobs),
        'fallbackCount' => $fallbackCount,
    ],
];

$cachePayload = $result;
$cachePayload['jobs'] = applyDebugScoreReasons($jobs, false);
file_put_contents($cacheFile, json_encode($cachePayload));

if ($selectedProvince !== '') {
    $result['jobs'] = sortJobsForProvince($result['jobs'], $selectedProvince);
}

echo json_encode($result);
