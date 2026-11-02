# DeepTick 代码示例

每种语言各有一份 REST 脚本和一份 WebSocket 脚本，可单独运行。接口以 [行情 API 文档](../Deeptick行情API文档.md) 为准。

| 语言 | REST | WebSocket |
| --- | --- | --- |
| Python | `python/rest_example.py` | `python/websocket_example.py` |
| Java 11+ | `java/RestExample.java` | `java/WebSocketExample.java` |
| Go | `go/rest/` | `go/ws/` |
| PHP | `php/rest_example.php` | `php/websocket_example.php` |
| Node.js 22+ | `nodejs/rest_example.mjs` | `nodejs/websocket_example.mjs` |

## 准备

在 [DeepTick 控制台](https://www.deeptick.cc) 复制 API Key，然后设置环境变量：

```bash
# Windows PowerShell
$env:DEEPTICK_TOKEN = "your_api_key"

# macOS / Linux
export DEEPTICK_TOKEN=your_api_key
```

未设置时，脚本会使用占位符 `your_api_key`，鉴权接口会返回 `1001`。`GET /api/v1/time` 不需要钥匙。

| 接入 | 地址 | 认证 |
| --- | --- | --- |
| REST | `https://api.deeptick.cc` | 请求头 `deeptoken` |
| WebSocket | `wss://api.deeptick.cc/ws/v1` | 握手头 `deeptoken`。浏览器和 Node 内置 WebSocket 改用查询参数 |

标的写法：REST 查询参数是 `{代码}.{市场}`，例如 `BTCUSDT.CR`。WebSocket Topic 是 `{类型}.{市场}.{代码}`，例如 `quote.CR.BTCUSDT`。

## 运行

```bash
# Python 3。WebSocket 需要：pip install -r python/requirements.txt
python python/rest_example.py
python python/websocket_example.py

# Java 11+
java java/RestExample.java
java java/WebSocketExample.java

# Go 1.22+
cd go && go run ./rest
cd go && go run ./ws

# PHP（需启用 curl 与 openssl）
php php/rest_example.php
php php/websocket_example.php

# Node.js 22+（全局 fetch 与 WebSocket）
node nodejs/rest_example.mjs
node nodejs/websocket_example.mjs
```

REST 示例依次请求：服务器时间、报价、盘口、最近成交、K 线、标的列表。WebSocket 示例在收到 `welcome` 后，按类型各发一条 `subscribe`（报价、盘口、K 线、成交），并按 30 秒发送 `ping`。一次把多种类型写进同一条会整批返回 `4001`。示例标的是免费套餐可请求的 `BTCUSDT.CR`。按 Ctrl+C 结束。
