> [中文](./README.md) | [English](./README_en.md)

# DeepTick · 实时金融行情数据 API

面向量化交易和金融系统的低延迟行情。一次接入，覆盖 A 股、港股、美股、外汇、指数、贵金属和加密货币的实时与历史数据。

[官网](https://www.deeptick.cc) · [字段与错误码](./Deeptick行情API文档.md) · API v3.8

## 介绍

DeepTick 为开发者、交易平台和研究机构提供全球金融行情。A 股、港股、美股、外汇、指数、贵金属和加密货币走同一套 REST 与 WebSocket，字段和时间格式统一，不必按市场各接一套接口。

能拿到的数据包括实时快照、盘口深度、逐笔成交、Tick、实时 K 线和历史 K 线。外汇按 Tick 返回报价和时间戳；加密货币 7×24 小时，不同交易所的交易对收成同一套代码。注册后有免费额度，只能请求 `AAPL.US`、`EURUSD.FX`、`BTCUSDT.CR`、`SPX.IDX`、`XAUUSD.MT`、`XAGUSD.MT`，其他标的返回 `4002`。数据仅供信息参考，不构成任何交易或投资建议。

常见用法：

- **量化交易**：用实时行情和历史 K 线做回测、监控和执行。
- **交易平台**：用稳定的报价和深度，作为经纪、CFD 和资管终端的数据层。
- **研究与 AI**：用快照、逐笔和 K 线给模型、投研和智能投顾供数。

本仓库是接入材料：v3.8 接口说明，以及 Python、Java、Go、PHP、Node.js 可运行示例。

---

## 第一条报价

在 [控制台](https://www.deeptick.cc) 复制 API Key。REST 和 WebSocket 用同一把钥匙，放在 `deeptoken`。

```bash
# Windows PowerShell
$env:DEEPTICK_TOKEN = "your_api_key"
curl.exe -s -H "deeptoken: $env:DEEPTICK_TOKEN" "https://api.deeptick.cc/api/v1/quote?symbols=BTCUSDT.CR"

# macOS / Linux
export DEEPTICK_TOKEN=your_api_key
curl -s -H "deeptoken: $DEEPTICK_TOKEN" "https://api.deeptick.cc/api/v1/quote?symbols=BTCUSDT.CR"
```

成功时 `code` 为 `0`：

```json
{
  "code": 0,
  "msg": "ok",
  "data": [{ "s": "BTCUSDT.CR", "ld": "65000.12", "t": 1723100000000 }]
}
```

`s` 是标的，`ld` 是最新价，`t` 是行情时间，单位毫秒。报价不含买一卖一，多档盘口用 `/api/v1/orderbook`。

先确认服务是否可达，可以不带钥匙访问 `GET /api/v1/time`。

---

## 接入地址

| | 地址 | 钥匙 |
| --- | --- | --- |
| 查询 | `https://api.deeptick.cc` | 请求头 `deeptoken` |
| 推送 | `wss://api.deeptick.cc/ws/v1` | 握手头 `deeptoken`。浏览器放在 `?deeptoken=` |

查一次快照、历史 K 线或标的列表，用 REST。看板和策略要持续收行情，用 WebSocket。

---

## 标的写法

REST 参数写成 `{代码}.{市场}`，例如 `AAPL.US`。WebSocket Topic 写成 `{类型}.{市场}.{代码}`，例如 `quote.US.AAPL`。K 线在末尾加周期：`candle.CN.600519.SH.1m`。A 股代码自带交易所后缀，Topic 里保留 `.SH` 或 `.SZ`。

可订阅标的以 `GET /api/v1/symbols` 的返回为准。

| 市场 | 品种 | 查询 | 推送 |
| --- | --- | --- | --- |
| `CN` | A 股 | `600519.SH` | `quote.CN.600519.SH` |
| `HK` | 港股 | `00700.HK` | `quote.HK.00700` |
| `US` | 美股 | `AAPL.US` | `depth.US.AAPL` |
| `FX` | 外汇 | `EURUSD.FX` | `quote.FX.EURUSD` |
| `MT` | 贵金属 | `XAUUSD.MT` | `quote.MT.XAUUSD` |
| `CR` | 加密货币 | `BTCUSDT.CR` | `trade.CR.BTCUSDT` |
| `IDX` | 指数 | `SPX.IDX` | `quote.IDX.SPX` |

---

## 查询接口

| 请求 | 用途 |
| --- | --- |
| `GET /api/v1/time` | 服务器时间。免鉴权，免限流 |
| `GET /api/v1/quote?symbols=` | 最新价和当日统计。一次 1 个标的 |
| `GET /api/v1/orderbook?symbol=` | 盘口深度 |
| `GET /api/v1/trades?symbol=&limit=` | 最近成交 |
| `GET /api/v1/candles?symbols=&interval=&limit=` | K 线 |
| `GET /api/v1/symbols` | 按市场检索标的 |

K 线周期：`1m` `5m` `15m` `30m` `60m` `4h` `1d` `1w` `1M`。

---

## 推送

连接成功后先收到 `welcome`，再发送订阅。大约每 30 秒发一次 `ping`。大约 60 秒没有上行，连接会被关闭。

一次 `subscribe` 只能包含同一种类型。下面这一条只订报价。盘口、K 线、逐笔要各发一条，混在一起整批返回 `4001`。

```json
{"type":"subscribe","topics":["quote.CR.BTCUSDT","quote.US.AAPL"],"request_id":"r1"}
```

```json
{"type":"ping","ts":1723100000000,"request_id":"h1"}
```

`quote` 报价，`depth` 盘口，`tick` 逐笔，`trade` 成交，`candle` K 线。

---

## 示例

脚本读取环境变量 `DEEPTICK_TOKEN`。各语言的运行方式见 [examples/README.md](./examples/README.md)。

| | 查询 | 推送 |
| --- | --- | --- |
| Python | [rest_example.py](./examples/python/rest_example.py) | [websocket_example.py](./examples/python/websocket_example.py) |
| Java 11+ | [RestExample.java](./examples/java/RestExample.java) | [WebSocketExample.java](./examples/java/WebSocketExample.java) |
| Go 1.22+ | [rest](./examples/go/rest) | [ws](./examples/go/ws) |
| PHP | [rest_example.php](./examples/php/rest_example.php) | [websocket_example.php](./examples/php/websocket_example.php) |
| Node.js 22+ | [rest_example.mjs](./examples/nodejs/rest_example.mjs) | [websocket_example.mjs](./examples/nodejs/websocket_example.mjs) |

```bash
pip install -r examples/python/requirements.txt
python examples/python/rest_example.py
python examples/python/websocket_example.py
```

---

## 接入时注意

- 钥匙缺失或无效返回 `1001`。请求头的名字是 `deeptoken`，值是控制台复制的整串。
- `/api/v1/quote` 一次只能传 1 个代码，传多个返回 `8005`。盘口和成交的参数名是 `symbol`，报价和 K 线是 `symbols`。
- 报价不含买卖档。多档盘口用 `/api/v1/orderbook`。
- 调用额度见 [文档第 7 章](./Deeptick行情API文档.md)，错误码见第 8 章。一次订阅只能是同一种行情类型。

字段、错误码和限流以本文档和官网控制台为准。公开文档只保留对客接口。套餐额度以控制台当前方案为准。
