<?php
/**
 * DeepTick REST 示例。php rest_example.php
 * 环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
 */

$token = getenv('DEEPTICK_TOKEN') ?: 'your_api_key';
$base = 'https://api.deeptick.cc';
$symbol = 'BTCUSDT.CR';

function deeptick_get(string $base, string $path, array $params, ?string $token): void
{
    $url = $base . $path;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    $headers = [];
    if ($token !== null && $token !== '') {
        $headers[] = 'deeptoken: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $body = curl_error($ch);
    }
    curl_close($ch);
    echo "\n== $path HTTP $status\n$body\n";
}

deeptick_get($base, '/api/v1/time', [], null);
deeptick_get($base, '/api/v1/quote', ['symbols' => $symbol], $token);
deeptick_get($base, '/api/v1/orderbook', ['symbol' => $symbol], $token);
deeptick_get($base, '/api/v1/trades', ['symbol' => $symbol, 'limit' => 5], $token);
deeptick_get($base, '/api/v1/candles', ['symbols' => $symbol, 'interval' => '1m', 'limit' => 5], $token);
deeptick_get($base, '/api/v1/symbols', ['market' => 'CR', 'page' => 1, 'page_size' => 5], $token);
