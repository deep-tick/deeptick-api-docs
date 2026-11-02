> [中文](./README.md) | [English](./README_en.md)

# DeepTick · Real-time market data API

Low-latency market data for trading systems and quantitative apps. One integration covers China A-shares, Hong Kong, US stocks, forex, indices, metals, and crypto, both live and historical.

[Website](https://www.deeptick.cc) · [Field reference](./Deeptick行情API文档.md) (v3.8, Chinese)

---

## First quote

Copy an API key from the [console](https://www.deeptick.cc). The same key is sent as `deeptoken` for REST and WebSocket.

```bash
export DEEPTICK_TOKEN=your_api_key
curl -s -H "deeptoken: $DEEPTICK_TOKEN" \
  "https://api.deeptick.cc/api/v1/quote?symbols=BTCUSDT.CR"
```

A success body has `"code": 0`:

```json
{
  "code": 0,
  "msg": "ok",
  "data": [{ "s": "BTCUSDT.CR", "ld": "65000.12", "t": 1723100000000 }]
}
```

`s` is the symbol, `ld` is the last price, and `t` is the quote time in Unix milliseconds. Quotes do not include bid or ask. Use `/api/v1/orderbook` for the book.

`GET /api/v1/time` needs no key and checks that the gateway is reachable.

---

## Endpoints

| | Address | Key |
| --- | --- | --- |
| Query | `https://api.deeptick.cc` | Header `deeptoken` |
| Stream | `wss://api.deeptick.cc/ws/v1` | Handshake header `deeptoken`. Browsers use `?deeptoken=` |

Use REST for a snapshot, historical candles, or a symbol search. Use WebSocket when a dashboard or strategy needs a continuous stream.

---

## Symbols

REST parameters use `{code}.{market}`, for example `AAPL.US`. WebSocket topics use `{type}.{market}.{code}`, for example `quote.US.AAPL`. Candles add the interval at the end: `candle.CN.600519.SH.1m`. A-share codes keep the exchange suffix inside the topic.

The tradable list is whatever `GET /api/v1/symbols` returns.

| Market | Asset | Query | Stream |
| --- | --- | --- | --- |
| `CN` | China A-shares | `600519.SH` | `quote.CN.600519.SH` |
| `HK` | Hong Kong | `00700.HK` | `quote.HK.00700` |
| `US` | US stocks | `AAPL.US` | `depth.US.AAPL` |
| `FX` | Forex | `EURUSD.FX` | `quote.FX.EURUSD` |
| `MT` | Metals | `XAUUSD.MT` | `quote.MT.XAUUSD` |
| `CR` | Crypto | `BTCUSDT.CR` | `trade.CR.BTCUSDT` |
| `IDX` | Indices | `SPX.IDX` | `quote.IDX.SPX` |

---

## REST

| Request | Returns |
| --- | --- |
| `GET /api/v1/time` | Server time. No auth, no quota |
| `GET /api/v1/quote?symbols=` | Last price and the session stats. One symbol per call |
| `GET /api/v1/orderbook?symbol=` | Order book |
| `GET /api/v1/trades?symbol=&limit=` | Recent trades |
| `GET /api/v1/candles?symbols=&interval=&limit=` | Candles |
| `GET /api/v1/symbols` | Symbol search by market |

Candle intervals: `1m` `5m` `15m` `30m` `60m` `4h` `1d` `1w` `1M`.

---

## WebSocket

Wait for `welcome`, then subscribe. Send `ping` about every 30 seconds. A connection with no client messages for about 60 seconds is closed.

```json
{"type":"subscribe","topics":["quote.CR.BTCUSDT","depth.US.AAPL","candle.CN.600519.SH.1m"],"request_id":"r1"}
```

```json
{"type":"ping","ts":1723100000000,"request_id":"h1"}
```

`quote` is the last price, `depth` is the book, `tick` is a tick, `trade` is a print, and `candle` is a bar.

---

## Examples

Samples read `DEEPTICK_TOKEN`. Run instructions are in [examples/README.md](./examples/README.md).

| | Query | Stream |
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

## Before you integrate

- A missing or invalid key returns `1001`. The header name is `deeptoken`, and the value is the full key from the console.
- `/api/v1/quote` accepts one symbol. More than one returns `8005`. Order book and trades use `symbol`. Quotes and candles use `symbols`.
- Quotes have no bid or ask. The book is `/api/v1/orderbook`.
- Quotas follow the plan. Error codes are in [chapter 7](./Deeptick行情API文档.md).

Fields, error codes, and quotas follow chapters 1–8 of the reference and the website console.
