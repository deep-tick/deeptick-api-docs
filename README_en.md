> [中文](./README.md) | [English](./README_en.md)

# DeepTick · Real-time market data API

[![Stars](https://img.shields.io/github/stars/deep-tick/deeptick-realtime-market-data-api?style=flat-square)](https://github.com/deep-tick/deeptick-realtime-market-data-api/stargazers)
[![Forks](https://img.shields.io/github/forks/deep-tick/deeptick-realtime-market-data-api?style=flat-square)](https://github.com/deep-tick/deeptick-realtime-market-data-api/network/members)
[![API](https://img.shields.io/badge/API-v3.8-2ea44f?style=flat-square)](./Deeptick行情API文档.md)

REST and WebSocket access to stocks, forex, crypto, metals, and indices. Use it for dashboards, signals, and snapshot queries.

**Website:** https://www.deeptick.cc  
**Reference:** [Deeptick行情API文档.md](./Deeptick行情API文档.md) (v3.8, Chinese)

---

## Two ways in

| | REST | WebSocket |
| --- | --- | --- |
| Endpoint | `https://api.deeptick.cc` | `wss://api.deeptick.cc/ws/v1` |
| Use for | Snapshots, historical candles, symbol search | Live quotes, depth, trades, candle pushes |
| Auth | Header `deeptoken` | Handshake header `deeptoken`; browsers use the query parameter |

Create the key in the [console](https://www.deeptick.cc). The same key works for REST and WebSocket. `GET /api/v1/time` needs no key and is a useful reachability check.

---

## Markets

REST symbols look like `{code}.{market}`, for example `BTCUSDT.CR`. WebSocket topics look like `{type}.{market}.{code}`, for example `quote.CR.BTCUSDT`. The tradable list comes from `GET /api/v1/symbols`.

| Code | Market | REST example | Topic example |
| --- | --- | --- | --- |
| `CR` | Crypto | `BTCUSDT.CR` | `quote.CR.BTCUSDT` |
| `FX` | Forex | `EURUSD.FX` | `quote.FX.EURUSD` |
| `CN` | China A-shares | `600519.SH` | `candle.CN.600519.SH.1m` |
| `HK` | Hong Kong | `00700.HK` | `tick.HK.00700` |
| `US` | US stocks | `AAPL.US` | `depth.US.AAPL` |
| `MT` | Metals | `XAUUSD.MT` | `quote.MT.XAUUSD` |
| `IDX` | Indices | `SPX.IDX` | `quote.IDX.SPX` |

---

## Endpoints

### REST

| Method | Purpose |
| --- | --- |
| `GET /api/v1/time` | Server time, no auth |
| `GET /api/v1/quote?symbols=` | Latest quote, one symbol per call |
| `GET /api/v1/orderbook?symbol=` | Order book |
| `GET /api/v1/trades?symbol=&limit=` | Recent trades |
| `GET /api/v1/candles?symbols=&interval=&limit=` | Candles: `1m` `5m` `15m` `30m` `60m` `4h` `1d` `1w` `1M` |
| `GET /api/v1/symbols` | Search symbols by market |

A success body looks like `{"code":0,"msg":"ok","data":...}`.

### WebSocket

After connect, wait for `welcome`, then subscribe. Send `ping` about every 30 seconds. A connection with no client messages for about 60 seconds is closed.

```json
{"type":"subscribe","topics":["quote.CR.BTCUSDT","depth.US.AAPL","candle.CN.600519.SH.1m"],"request_id":"r1"}
```

Topic types: `quote`, `depth`, `tick`, `trade`, and `candle` (interval is the last segment).

---

## Quick start

1. Register at https://www.deeptick.cc and copy the API key.
2. Export `DEEPTICK_TOKEN`.
3. Run an example, or request a quote directly.

```bash
export DEEPTICK_TOKEN=your_api_key
```

```python
import os, json, urllib.request

token = os.environ.get("DEEPTICK_TOKEN", "your_api_key")
req = urllib.request.Request(
    "https://api.deeptick.cc/api/v1/quote?symbols=BTCUSDT.CR",
    headers={"deeptoken": token},
)
print(json.load(urllib.request.urlopen(req)))
```

The streaming sample is [examples/python/websocket_example.py](./examples/python/websocket_example.py).

---

## Examples

Each language has one REST script and one WebSocket script. See [examples/README.md](./examples/README.md).

| Language | REST | WebSocket |
| --- | --- | --- |
| Python | [rest_example.py](./examples/python/rest_example.py) | [websocket_example.py](./examples/python/websocket_example.py) |
| Java 11+ | [RestExample.java](./examples/java/RestExample.java) | [WebSocketExample.java](./examples/java/WebSocketExample.java) |
| Go | [go/rest](./examples/go/rest) | [go/ws](./examples/go/ws) |
| PHP | [rest_example.php](./examples/php/rest_example.php) | [websocket_example.php](./examples/php/websocket_example.php) |
| Node.js | [rest_example.mjs](./examples/nodejs/rest_example.mjs) | [websocket_example.mjs](./examples/nodejs/websocket_example.mjs) |

```bash
pip install -r examples/python/requirements.txt
python examples/python/rest_example.py
python examples/python/websocket_example.py
```

---

## In this repo

| File | Contents |
| --- | --- |
| [Deeptick行情API文档.md](./Deeptick行情API文档.md) | Fields, error codes, quotas, subscribe rules |
| [examples](./examples) | Python, Java, Go, PHP, and Node.js samples |

Chapters 1–8 of the reference, together with the website console, describe the customer contract.

---

## FAQ

**REST or WebSocket?**  
Use REST for a snapshot or historical candles. Use WebSocket when a dashboard or strategy needs a continuous stream.

**Where does the key go?**  
The header name is `deeptoken` for both HTTP and the WebSocket handshake. Browsers that cannot set handshake headers put the same string in `?deeptoken=`.

**Why is the code 1001?**  
The key is missing or invalid. Check `DEEPTICK_TOKEN`. Quote requests accept one symbol; more than one returns `8005`.

**Are there rate limits?**  
Yes. Quotas follow the plan. See chapter 7 of the reference for the error codes.

---

## Links

- Website: https://www.deeptick.cc
- Repository: https://github.com/deep-tick/deeptick-realtime-market-data-api
