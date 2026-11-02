<?php
/**
 * DeepTick WebSocket 示例。php websocket_example.php
 * 使用 PHP 流实现文本帧，不依赖 Composer。需要 openssl。
 * 收到 welcome 后订阅，并在空闲时发送 ping。
 */

$token = getenv('DEEPTICK_TOKEN') ?: 'your_api_key';
$host = 'api.deeptick.cc';
$path = '/ws/v1';

$fp = stream_socket_client(
    "ssl://$host:443",
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT,
    stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
);
if (!$fp) {
    fwrite(STDERR, "$errstr ($errno)\n");
    exit(1);
}
stream_set_timeout($fp, 35);

$key = base64_encode(random_bytes(16));
$req = "GET $path HTTP/1.1\r\n"
    . "Host: $host\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $key\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "deeptoken: $token\r\n\r\n";
fwrite($fp, $req);

$headers = '';
while (($line = fgets($fp)) !== false) {
    $headers .= $line;
    if (rtrim($line) === '') {
        break;
    }
}
if (strpos($headers, '101') === false) {
    fwrite(STDERR, $headers);
    exit(1);
}
echo "connected\n";

function ws_send($fp, string $text): void
{
    $len = strlen($text);
    $mask = random_bytes(4);
    $frame = chr(0x81);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($fp, $frame . $mask . $masked);
}

function ws_read_exact($fp, int $n): string
{
    $data = '';
    while (strlen($data) < $n) {
        $chunk = fread($fp, $n - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
}

function ws_recv($fp): ?string
{
    $hdr = ws_read_exact($fp, 2);
    if (strlen($hdr) < 2) {
        return null;
    }
    $opcode = ord($hdr[0]) & 0x0f;
    $masked = (ord($hdr[1]) & 0x80) !== 0;
    $len = ord($hdr[1]) & 0x7f;
    if ($len === 126) {
        $len = unpack('n', ws_read_exact($fp, 2))[1];
    } elseif ($len === 127) {
        $parts = unpack('Nhi/Nlo', ws_read_exact($fp, 8));
        $len = ($parts['hi'] << 32) | $parts['lo'];
    }
    $mask = $masked ? ws_read_exact($fp, 4) : '';
    $data = $len > 0 ? ws_read_exact($fp, $len) : '';
    if ($masked) {
        $plain = '';
        $size = strlen($data);
        for ($i = 0; $i < $size; $i++) {
            $plain .= $data[$i] ^ $mask[$i % 4];
        }
        $data = $plain;
    }
    if ($opcode === 0x8) {
        return null;
    }
    if ($opcode === 0x9) {
        fwrite($fp, chr(0x8A) . chr(0x80) . random_bytes(4));
        return ws_recv($fp);
    }
    if ($opcode !== 0x1) {
        return ws_recv($fp);
    }
    return $data;
}

$subscribed = false;
$lastPing = time();
while (true) {
    $read = [$fp];
    $write = $except = null;
    $ready = stream_select($read, $write, $except, 5);
    if ($ready === false) {
        break;
    }
    if ($ready > 0) {
        $msg = ws_recv($fp);
        if ($msg === null) {
            echo "closed\n";
            break;
        }
        echo $msg, "\n";
        $decoded = json_decode($msg, true);
        if (!$subscribed && ($decoded['type'] ?? '') === 'welcome') {
            $subscriptions = [
                ['quote.CR.BTCUSDT', 'q1'],
                ['depth.CR.BTCUSDT', 'd1'],
                ['candle.CR.BTCUSDT.1m', 'c1'],
                ['trade.CR.BTCUSDT', 't1'],
            ];
            foreach ($subscriptions as [$topic, $requestId]) {
                ws_send($fp, json_encode([
                    'type' => 'subscribe',
                    'topics' => [$topic],
                    'request_id' => $requestId,
                ], JSON_UNESCAPED_SLASHES));
            }
            $subscribed = true;
        }
    }
    if (time() - $lastPing >= 30) {
        ws_send($fp, json_encode([
            'type' => 'ping',
            'ts' => (int) round(microtime(true) * 1000),
            'request_id' => 'h1',
        ]));
        $lastPing = time();
    }
}
