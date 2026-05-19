<?php

use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require 'vendor/autoload.php';

const KNOWN_TOKENS_URL = 'https://bsn.expert/tokens/';
const KNOWN_TOKENS_CACHE_FILE = 'known_tokens.json';
const KNOWN_TOKENS_SEED_FILE = __DIR__ . '/known_tokens.json';
const KNOWN_TOKENS_REQUEST_TIMEOUT = 15;

function loadKnownTokens(MTLA\CollectTags $CollectTags): array
{
    try {
        $json = fetchKnownTokensJson(KNOWN_TOKENS_URL, KNOWN_TOKENS_REQUEST_TIMEOUT);
        $tokens = parseKnownTokensJson($json, KNOWN_TOKENS_URL);
        if (file_put_contents(KNOWN_TOKENS_CACHE_FILE, $json) === false) {
            $CollectTags->print('Known tokens cache update failed: ' . KNOWN_TOKENS_CACHE_FILE);
        }

        return $tokens;
    } catch (RuntimeException $e) {
        $CollectTags->print('Known tokens request failed: ' . $e->getMessage());

        foreach (array_unique([KNOWN_TOKENS_CACHE_FILE, KNOWN_TOKENS_SEED_FILE]) as $fallback_file) {
            if (!is_readable($fallback_file)) {
                continue;
            }

            $json = file_get_contents($fallback_file);
            if ($json === false) {
                $CollectTags->print('Known tokens fallback cannot be read: ' . $fallback_file);
                continue;
            }

            try {
                $tokens = parseKnownTokensJson($json, $fallback_file);
            } catch (RuntimeException $fallbackException) {
                $CollectTags->print($fallbackException->getMessage());
                continue;
            }

            $CollectTags->print('Using known tokens fallback: ' . $fallback_file);
            if (
                $fallback_file !== KNOWN_TOKENS_CACHE_FILE
                && file_put_contents(KNOWN_TOKENS_CACHE_FILE, $json) === false
            ) {
                $CollectTags->print('Known tokens cache restore failed: ' . KNOWN_TOKENS_CACHE_FILE);
            }

            return $tokens;
        }

        throw new RuntimeException('Known tokens fallback is unavailable', previous: $e);
    }
}

function fetchKnownTokensJson(string $url, int $timeout): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('curl initialization failed');
    }

    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($curl);
    $curl_errno = curl_errno($curl);
    $curl_error = curl_error($curl);
    $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new RuntimeException('curl error ' . $curl_errno . ': ' . $curl_error);
    }

    if ($http_code < 200 || $http_code >= 300) {
        throw new RuntimeException('unexpected HTTP status ' . $http_code);
    }

    return $response;
}

function parseKnownTokensJson(string $json, string $source): array
{
    $tokens = json_decode($json, true);
    if (!is_array($tokens)) {
        throw new RuntimeException('invalid known tokens JSON from ' . $source);
    }

    foreach ($tokens as $code => $token) {
        if (!is_string($code) || $code === '') {
            throw new RuntimeException('invalid known token code in ' . $source);
        }

        if (
            !is_array($token)
            || !array_key_exists('issuer', $token)
            || !is_string($token['issuer'])
            || !MTLA\CollectTags::validateStellarAccountIdFormat($token['issuer'])
        ) {
            throw new RuntimeException('invalid known token issuer for ' . $code . ' in ' . $source);
        }
    }

    return $tokens;
}

function buildKnownTokenKeys(array $knownTokens): array
{
    $tokens = [];
    foreach ($knownTokens as $code => $token) {
        $tokens[] = $code . '-' . $token['issuer'];
    }

    return $tokens;
}

$CollectTags = new MTLA\CollectTags(
    StellarSDK::getPublicNetInstance()
);

$CollectTags->isDebugMode(false);

chdir('/data/public/');

$knownTokens = loadKnownTokens($CollectTags);
$tokens = buildKnownTokenKeys($knownTokens);
foreach ($tokens as $token) {
    $CollectTags->addBalanceToken($token);
}

$CollectTags->addSource('MTLAP', 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA');
$CollectTags->addSource('MTLAC', 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA');
$CollectTags->addSource('EURMTL', 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V');

$data = $CollectTags->run();

$result = [
    'createDate' => (new DateTime('now', new DateTimeZone('UTC')))->format('c'),
    'knownTokens' => $knownTokens,
    'usedSources' => $CollectTags->getSources(),
    'accounts' => $data,
];

// JSON, только базовые данные, для всех
file_put_contents('bsn-new.json', json_encode($result, JSON_UNESCAPED_UNICODE));
rename('bsn-new.json', 'bsn.json');
file_put_contents('bsn-new.json,gz', gzencode(json_encode($result, JSON_UNESCAPED_UNICODE), 9));
rename('bsn-new.json,gz', 'bsn.json.gz');

// Тут уже красота и отсебятина
// HTML

// Входящие теги
foreach ($result['accounts'] as $account => $datum) {
    if (!array_key_exists('tags', $datum)) {
        continue;
    }
    foreach ($datum['tags'] as $tag => $accounts) {
        foreach ($accounts as $acc) {
            if (!array_key_exists($acc, $result['accounts'])) {
                $result['accounts'][$acc] = [];
            }
            if (!array_key_exists('income', $result['accounts'][$acc])) {
                $result['accounts'][$acc]['income'] = [];
            }
            if (!array_key_exists($tag, $result['accounts'][$acc]['income'])) {
                $result['accounts'][$acc]['income'][$tag] = [];
            }
            $result['accounts'][$acc]['income'][$tag][] = $account;
        }
    }
}
// Отсортировать входящие теги так же, как сортированы исходящие
foreach ($result['accounts'] as $account => & $datum) {
    if (array_key_exists('income', $datum)) {
        $CollectTags->semantic_sort_keys($datum['income'], $CollectTags->sort_tags_example);
    }
}
// Level
$mtla_accounts = [
    'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA',
    'GDGC46H4MQKRW3TZTNCWUU6R2C7IPXGN7HQLZBJTNQO6TW7ZOS6MSECR',
];
foreach ($result['accounts']  as $account => & $datum) {
    if (in_array($account, $mtla_accounts, true)) {
        $datum['relation'] = [
            'type' => 'mtlap',
            'level' => 5,
        ];
        continue;
    }

    if (!array_key_exists('balances', $datum)) {
        continue;
    }

    if (array_key_exists('MTLAP', $datum['balances']) && (int) $datum['balances']['MTLAP']) {
        $datum['relation'] = [
            'type' => 'mtlap',
            'level' => intval($datum['balances']['MTLAP']),
        ];
    } else if (array_key_exists('MTLAC', $datum['balances']) && (int) $datum['balances']['MTLAC']) {
        $datum['relation'] = [
            'type' => 'mtlac',
            'level' => intval($datum['balances']['MTLAC']),
        ];
    }
}
// Inherited level
foreach ($result['accounts']  as $account => & $datum) {
    if (
        array_key_exists('relation', $datum)
        || !array_key_exists('tags', $datum)
        || !array_key_exists('Owner', $datum['tags'])
        || count($datum['tags']['Owner']) !== 1
    ) {
        continue;
    }

    $owner_id = $datum['tags']['Owner'][0];

    if (
        !array_key_exists($owner_id, $result['accounts'])
        || !array_key_exists('relation', $result['accounts'][$owner_id])
        || !array_key_exists('tags', $result['accounts'][$owner_id])
        || !array_key_exists('OwnershipFull', $result['accounts'][$owner_id]['tags'])
        || !in_array($account, $result['accounts'][$owner_id]['tags']['OwnershipFull'], true)

    ) {
        continue;
    }

    $datum['relation'] = $result['accounts'][$owner_id]['relation'];
    $datum['relation']['inherited'] = true;
}
// Second level
$good_tags = $CollectTags->sort_tags_example;
foreach ($result['accounts']  as $account => & $datum) {
    if (
        array_key_exists('relation', $datum)
        || !array_key_exists('income', $datum)
    ) {
        continue;
    }

    foreach ($datum['income'] as $tag_name => $taggers) {
        if (!in_array($tag_name, $good_tags, true)) {
            continue;
        }

        foreach ($taggers as $tagger) {
            if (
                array_key_exists('relation', $result['accounts'][$tagger])
                && $result['accounts'][$tagger]['relation']['type'] !== 'second'
            ) {
                $datum['relation'] = [
                    'type' => 'second',
                ];
                break 2;
            }
        }
    }

}

file_put_contents(
    'bsn-extra-new.json,gz',
    gzencode(json_encode($result, JSON_UNESCAPED_UNICODE), 9)
);
rename('bsn-extra-new.json,gz', 'bsn-extra.json,gz');

$Twig = new Environment(new FilesystemLoader(__DIR__ . '/templates'), [
//    'cache' => 'twig_cache',
]);
$Twig->addExtension(new \MTLA\TwigAppExtension($result));
$Template = $Twig->load('simple_html.twig');
$fp = gzopen('bsn-new.html.gz', 'w9');
gzwrite($fp, $Template->render($result));
gzclose($fp);
rename('bsn-new.html.gz', 'bsn.html.gz');
