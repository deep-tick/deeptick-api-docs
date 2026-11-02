# DeepTick 行情数据 API 文档

> 版本：v3.8（2026-10） · 对外接口口径与官网控制台「API 文档」一致（https://www.deeptick.cc 控制台 / 官网文档）
>
> **权威来源**：公开 REST / WebSocket 路径、字段名、错误码、套餐配额以官网控制台为准。本文只保留对客接口。

---

## 目录

- [1. 文档概述](#1-文档概述)
- [2. 快速开始](#2-快速开始)
- [3. 接入地址与通用约定](#3-接入地址与通用约定)
- [4. 认证](#4-认证)
- [5. REST API 接口详述](#5-rest-api-接口详述)
- [6. WebSocket 接口详述](#6-websocket-接口详述)
- [7. 限流与配额](#7-限流与配额)
- [8. 错误码字典](#8-错误码字典)

---

## 1. 文档概述

### 1.1 文档目的与适用范围

本文档面向接入 DeepTick 行情服务的开发者，说明 REST API 与 WebSocket API 的对客用法。

| 接入方式 | 说明 | 适用场景 |
|----------|------|----------|
| **REST API** | HTTPS/JSON，请求头 `deeptoken` 带 API Key 整串；实时报价、盘口、成交、K 线查询与标的检索 | 低频查询、快照初始化 |
| **WebSocket API** | 长连接发布/订阅，握手时带 `deeptoken`，实时推送 tick / quote / depth / candle / trade | 实时看板、策略信号、K 线推送 |

### 1.2 服务范围

DeepTick 用同一套接口提供 A 股、港股、美股、外汇、指数、贵金属和加密货币行情。查询走 `https://api.deeptick.cc`，推送走 `wss://api.deeptick.cc/ws/v1`。各市场的字段和时间格式一致。

### 1.3 术语表

| 术语 | 英文 | 说明 |
|------|------|------|
| API Key | deeptoken | 控制台复制的整串钥匙。REST 放请求头，WebSocket 放握手头或查询参数 |
| 套餐档位 | Plan Tier | 控制台方案：FREE / BASE / PRO / PREMIUM |
| Topic | — | WebSocket 订阅标识，格式为 `{type}.{market}.{symbol}`，K 线再加 `.{interval}` |
| 标的 | Symbol | 交易品种代码，格式为 `{code}.{market}`，如 `BTCUSDT.CR` |
| 市场码 | Market | 资产类别标识，如 CN/HK/US/CR 等 |
| 快照 | Snapshot | REST 返回的当前报价、盘口、成交或 K 线 |
| 断线续传 | Resume | 断线重连后补发缺失的 K 线数据（仅 WS candle，60s 窗口） |
| 令牌桶 | Token Bucket | 连接风暴保护算法，限制新连接速率 |
| 滑动窗口 | Sliding Window | REST 按最近 60 秒内的请求次数限流 |

---

## 2. 快速开始

### 2.1 接入流程概述

```
申请 API Key → 请求头 deeptoken → REST/WebSocket 接入
```

1. **申请 API Key**：在 DeepTick 控制台注册账号并创建 API Key，复制整串钥匙。REST 和 WebSocket 用同一把钥匙。
2. **REST 接入**：把钥匙放进请求头 `deeptoken`。`GET /api/v1/time` 免鉴权、免限流。
3. **WebSocket 接入**：连接 `wss://api.deeptick.cc/ws/v1`。服务端客户端把 `deeptoken` 放进握手头；浏览器不能自定义握手头，把同一整串放在查询参数 `deeptoken`。收到 `welcome` 后直接订阅。
4. **时钟对齐**：调用 `GET /api/v1/time` 确认服务可达，并把本地时钟对准服务器。

### 2.2 Python 快速示例

#### REST 调用

```python
import urllib.request, urllib.error, json

token = "your_api_key"
path = "/api/v1/quote?symbols=BTCUSDT.CR"
req = urllib.request.Request(
    "https://api.deeptick.cc" + path,
    headers={"deeptoken": token},
)
try:
    resp = urllib.request.urlopen(req)
except urllib.error.HTTPError as e:
    resp = e
print(json.load(resp))
# {"code": 0, "msg": "ok", "data": [{"s": "BTCUSDT.CR", "ld": "65000.12", ...}]}
```

#### WebSocket 连接

```python
# pip install websockets
import asyncio, json, websockets

token = "your_api_key"
topic = "quote.CR.BTCUSDT"

async def main():
    async with websockets.connect(
        "wss://api.deeptick.cc/ws/v1",
        additional_headers={"deeptoken": token},
    ) as ws:
        print(await ws.recv())  # welcome
        await ws.send(json.dumps({
            "type": "subscribe",
            "topics": [topic],
            "request_id": "r1",
        }))
        while True:
            print(await ws.recv())

asyncio.run(main())
```

### 2.3 Java 快速示例

#### REST 调用

```java
String token = "your_api_key";
String path = "/api/v1/quote?symbols=BTCUSDT.CR";
HttpRequest req = HttpRequest.newBuilder()
    .uri(URI.create("https://api.deeptick.cc" + path))
    .header("deeptoken", token)
    .GET()
    .build();
System.out.println(HttpClient.newHttpClient()
    .send(req, HttpResponse.BodyHandlers.ofString()).body());
```

#### WebSocket 连接

```java
String token = "your_api_key";
String topic = "quote.CR.BTCUSDT";
HttpClient.newHttpClient().newWebSocketBuilder()
    .header("deeptoken", token)
    .buildAsync(URI.create("wss://api.deeptick.cc/ws/v1"), new WebSocket.Listener() {
        public void onOpen(WebSocket ws) { ws.request(1); }
        public CompletionStage<?> onText(WebSocket ws, CharSequence data, boolean last) {
            if (data.toString().contains("\"welcome\"")) {
                ws.sendText("{\"type\":\"subscribe\",\"topics\":[\"" + topic + "\"],\"request_id\":\"r1\"}", true);
            } else {
                System.out.println(data);
            }
            ws.request(1);
            return null;
        }
    }).join();
```

---

## 3. 接入地址与通用约定

### 3.1 接入地址

只使用下面两个生产地址。

| 协议 | 地址 | 说明 |
|------|------|------|
| REST | `https://api.deeptick.cc/api/v1/*` | JSON over HTTPS |
| WebSocket | `wss://api.deeptick.cc/ws/v1` | JSON 文本帧 |

### 3.2 统一响应格式

#### 成功响应

HTTP 状态码 2xx，响应体为 `ApiResponse` 结构：

```json
{
  "code": 0,
  "msg": "ok",
  "data": { ... }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | int | 业务状态码，`0` 表示成功 |
| `msg` | String | 状态描述，成功时为 `"ok"` |
| `data` | Object/Array | 业务数据，结构因接口而异 |

行情 REST 响应一律带 `Cache-Control: no-store`，客户端与中间层不要缓存。

#### 错误响应

HTTP 状态码 400/401/403/404/429/503，响应体为 `ErrorBody` 结构：

```json
{
  "code": 8001,
  "message": "标的无快照数据: BTCUSDT.CR",
  "request_id": "6f1c3a8b2d7e4f9082a1b3c5d6e7f8a9"
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | int | 业务错误码（见 [第 8 章 错误码字典](#8-错误码字典)） |
| `message` | String | 错误描述 |
| `request_id` | String | 服务端生成的请求追踪标识，反馈问题时请携带 |

### 3.3 Symbol 命名规范

标的代码采用 `{code}.{market}` 格式，REST 与 WebSocket 通用：

| 示例 | 说明 |
|------|------|
| `BTCUSDT.CR` | 加密货币 BTC/USDT 交易对 |
| `ETHUSDT.CR` | 加密货币 ETH/USDT 交易对 |
| `EURUSD.FX` | 外汇欧元/美元 |
| `600519.SH` | A 股贵州茅台（沪市） |
| `000001.SZ` | A 股平安银行（深市） |
| `00700.HK` | 港股腾讯控股 |
| `AAPL.US` | 美股苹果 |
| `XAUUSD.MT` | 贵金属现货黄金 |

> 具体可订阅标的以 `GET /api/v1/symbols` 返回为准。

### 3.4 Topic 命名规范（WebSocket 订阅）

```
{type}.{market}.{symbol}[.{interval}]
```

| 段 | 取值 | 说明 |
|----|------|------|
| `type` | `tick` / `quote` / `depth` / `candle` / `trade` | 数据类型 |
| `market` | `CN` / `HK` / `US` / `FX` / `MT` / `CR` / `IDX` | 市场码 |
| `symbol` | 可含点（如 `600519.SH`） | 第 2 段之后的部分；candle 再去掉末段周期 |
| `interval`（candle） | `1m` / `5m` / `15m` / `30m` / `60m` / `4h` / `1d` / `1w` / `1M` | K 线周期，仅 candle 类型需要 |

示例：

```
quote.CR.BTCUSDT                  # 加密货币实时报价
depth.US.AAPL                     # 美股盘口
candle.CN.600519.SH.1m            # A 股 1 分钟 K 线
tick.HK.00700                     # 港股逐笔成交
trade.CR.ETHUSDT                  # 加密货币成交推送
```

### 3.5 市场代码表

| 市场码 | 资产类别 | REST 示例 | WS Topic 示例 |
|--------|----------|-----------|---------------|
| `CN` | A 股（沪深） | `600519.SH` | `quote.CN.600519.SH` |
| `HK` | 港股 | `00700.HK` | `quote.HK.00700` |
| `US` | 美股 | `AAPL.US` | `quote.US.AAPL` |
| `FX` | 外汇 | `EURUSD.FX` | `quote.FX.EURUSD` |
| `MT` | 贵金属 | `XAUUSD.MT` | `quote.MT.XAUUSD` |
| `CR` | 加密货币 | `BTCUSDT.CR` | `quote.CR.BTCUSDT` |
| `IDX` | 指数 | `SPX.IDX` | `quote.IDX.SPX` |

> 不提供期货市场码（无 `FT`），也不提供基金市场码（无 `FD`）。具体可订阅标的以 `GET /api/v1/symbols` 返回为准。

---

## 4. 认证

### 4.1 API Key

在控制台复制 API Key 整串。REST 和 WebSocket 用同一把钥匙，放进 `deeptoken`，不再使用 HMAC 签名。

| 位置 | 说明 |
|------|------|
| REST 请求头 `deeptoken` | API Key 整串 |
| WebSocket 握手头 `deeptoken` | 服务端客户端 |
| WebSocket 查询参数 `deeptoken` | 浏览器不能自定义握手头时，放同一整串 |

#### 套餐档位

控制台套餐决定订阅数、WebSocket 连接数和 REST 每分钟次数。数字以控制台当前方案为准。

| 控制台套餐 | 订阅上限 | WS 并发连接 | REST 限流（次/分钟） |
|------------|----------|-------------|---------------------|
| `FREE` | 10 | 1 | 5 |
| `BASE` | 100 | 3 | 30 |
| `PRO` | 500 | 6 | 300 |
| `PREMIUM` | 2000 | 12 | 600 |

REST 超限返回 HTTP 429、code `3001`。WebSocket 连接超限返回 `3003` 并关闭。订阅总量超限返回 `3002`。禁用的 Key 返回 `1004`。

### 4.2 REST

`GET /api/v1/time` 免鉴权、免限流。其余 `/api/v1/*` 都要带 `deeptoken`，并计入套餐 REST 限流。

```http
GET /api/v1/quote?symbols=BTCUSDT.CR HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

缺少或无效的 API Key 返回 `1001`。Key 已禁用返回 `1004`。IP 不在白名单返回 `2004`。

#### 免鉴权接口

| 接口 | 路径 | 说明 |
|------|------|------|
| 服务器时间 | `GET /api/v1/time` | 连通性检查与时钟对齐 |

### 4.3 WebSocket

握手时带上 `deeptoken`。通过后服务端下发 `welcome`，之后就可以订阅，不必再发 `auth` 帧。

```
握手（头或查询参数 deeptoken） → welcome → subscribe
```

服务端客户端：

```
wss://api.deeptick.cc/ws/v1
握手头 deeptoken: your_api_key
```

浏览器：

```
wss://api.deeptick.cc/ws/v1?deeptoken=your_api_key
```

缺少钥匙时连接被拒绝，code `1001`。并发超限返回 `3003` 并关闭连接。

---

## 5. REST API 接口详述

REST 接口统一前缀 `/api/v1`。除 `GET /api/v1/time` 外，都要带 `deeptoken`，并计入套餐限流。

---

### 5.1 GET /api/v1/time — 服务器时间

获取服务器当前时间，用于连通性检查与时钟对齐。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/time` |
| 鉴权 | **免鉴权** |
| 限流 | **免限流** |

#### 响应字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `st` | long | 服务器当前 Unix 毫秒时间戳 |

#### 请求示例

```http
GET /api/v1/time HTTP/1.1
Host: api.deeptick.cc
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "st": 1723100000000
  }
}
```

---

### 5.2 GET /api/v1/quote — 实时报价

查询一个标的的当前最新价和当日统计。一次只能传 1 个代码，多个代码返回 `8005`。字段有值才出现，缺失则省略，值为 0 时保留。报价不带买一卖一，多档买卖盘用盘口查询。加密货币报价通常没有昨收和涨跌。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/quote` |
| 鉴权 | 请求头 deeptoken |
| 限流 | 受套餐限流约束 |

#### 请求参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `symbols` | String | 是 | — | 一个标的，如 `BTCUSDT.CR`。一次只能查 1 个，多个代码返回 `8005` |

#### 响应字段

`data` 为数组，每个元素为报价快照（短字段）：

| 字段 | 类型 | 说明 |
|------|------|------|
| `s` | String | 标的代码 |
| `at` | String | 资产类型。没有则不出现 |
| `ld` | String/Number | 最新价 |
| `p` | String/Number | 前收 |
| `o` / `h` / `l` | String/Number | 开 / 高 / 低 |
| `v` | String/Number | 成交量 |
| `tu` | String/Number | 成交额。没有则不出现 |
| `ch` / `chp` | String/Number | 涨跌额 / 涨跌幅。没有则不出现，0 保留 |
| `ts` | int | 交易状态。0 正常，1 停牌，2 退市，3 熔断。不是时间戳。没有则不出现 |
| `t` | long | 行情时间戳（Unix 毫秒） |

> `p`、`ch`、`chp`、`tu`、`ts` 都是有值才出现。加密货币报价通常没有昨收和涨跌。

#### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `8005` | 400 | 报价一次只能查询 1 个标的 |
| `8001` | 404 | 标的不存在。暂无快照数据返回 HTTP 200 空列表，不再报 8001；部分命中只返回命中子集 |

#### 请求示例

```http
GET /api/v1/quote?symbols=BTCUSDT.CR HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    {
      "s": "BTCUSDT.CR",
      "ld": "65000.12",
      "o": "64800.0",
      "h": "65200.0",
      "l": "64750.0",
      "v": "1234.5",
      "tu": "80000000",
      "t": 1723100000000
    }
  ]
}
```

---

### 5.3 GET /api/v1/orderbook — 盘口查询

查询单个标的当前买卖盘。档位统一为 `{p, v}`。一次返回当前盘口，不是历史深度。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/orderbook` |
| 鉴权 | 请求头 deeptoken |
| 限流 | 受套餐限流约束 |

#### 请求参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `symbol` | String | 是 | — | 单个标的，如 `BTCUSDT.CR` |

#### 响应字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `s` | String | 标的代码 |
| `b` | `{p,v}[]` | 买盘，价格从高到低 |
| `a` | `{p,v}[]` | 卖盘，价格从低到高 |
| `t` | long | 时间戳（毫秒）。没有则不出现 |

#### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `8001` | 404 | 标的不存在。暂无盘口返回 HTTP 200，`data` 为 `{s, b:[], a:[]}`，不再报 8001 |

#### 请求示例

```http
GET /api/v1/orderbook?symbol=BTCUSDT.CR HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "s": "BTCUSDT.CR",
    "b": [{ "p": "65000.10", "v": "1.5" }, { "p": "65000.00", "v": "2.3" }],
    "a": [{ "p": "65000.20", "v": "1.2" }, { "p": "65000.30", "v": "3.0" }],
    "t": 1723100000000
  }
}
```

---

### 5.4 GET /api/v1/trades — 最新成交

查询单个标的最近成交列表，最新在前。一次拉取，不是逐笔推送。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/trades` |
| 鉴权 | 请求头 deeptoken |
| 限流 | 受套餐限流约束 |

#### 请求参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `symbol` | String | 是 | — | 单个标的 |
| `limit` | int | 否 | 50 | 返回条数，1～200，超限返回 `8004` |

#### 响应字段

`data.d` 为成交数组，**最新在前**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `d[].ld` | String/Number | 成交价 |
| `d[].v` | String/Number | 成交量 |
| `d[].t` | long | 成交时间戳（毫秒） |
| `mt.s` | String | 查询标的 |
| `mt.n` | int | 请求的 limit |

#### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `8004` | 400 | trades limit > 200 |
| `8001` | 404 | 标的不存在。暂无成交返回 HTTP 200，`data.d=[]`，不再报 8001 |

#### 请求示例

```http
GET /api/v1/trades?symbol=BTCUSDT.CR&limit=50 HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "d": [
      { "ld": "65000.12", "v": "0.5", "t": 1723100000000 },
      { "ld": "65000.10", "v": "1.2", "t": 1723099999000 }
    ],
    "mt": { "s": "BTCUSDT.CR", "n": 50 }
  }
}
```

---

### 5.5 没有快照时的返回

`/quote`、`/orderbook`、`/trades`、`/candles` 暂时没有数据时，返回 HTTP 200 和空结果：报价和 K 线是空数组，盘口为空档，成交的 `d` 为空数组。这种情况不返回 `8001`。`8001` 只表示标的不存在。

### 5.6 GET /api/v1/candles — K 线查询

批量查询 K 线，按时间升序。实时更新请订阅 WebSocket `candle.{market}.{symbol}.{interval}`。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/candles` |
| 鉴权 | 请求头 deeptoken |
| 限流 | 受套餐限流约束 |

#### 请求参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `symbols` | String | 是 | — | 逗号分隔的标的，如 `BTCUSDT.CR,AAPL.US`。单次最多 **20** 个，超限返回 `8006` |
| `interval` | String | 否 | `5m` | 周期：`1m` / `5m` / `15m` / `30m` / `60m` / `4h` / `1d` / `1w` / `1M`。`1h` 视为 `60m`；非法值返回 `8008` |
| `limit` | int | 否 | 40 | 条数，1～500，超限返回 `8007` |
| `et` | long | 否 | — | 截止时间 Unix 毫秒；缺省取最新。超出套餐 `lookbackYears` 返回 3004 |

#### 响应字段

`data` 为数组，每个元素对应一个标的：

| 字段 | 类型 | 说明 |
|------|------|------|
| `s` | String | 标的代码 |
| `i` | String | 周期 |
| `d` | `{t,o,h,l,c,v,tu}[]` | K 线，时间升序 |

#### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `8006` | 400 | candles symbols > 20 |
| `8007` | 400 | candles limit > 500 |
| `8008` | 400 | candles interval 非法 |
| `3004` | 429 | `et` 超出套餐历史回溯年限（FREE/BASE 1 年，PRO 3 年，PREMIUM 5 年） |
| `8001` | 404 | 标的不存在。暂无 K 线返回 HTTP 200 空列表，不再报 8001；部分命中只返回命中子集 |

实时 K 线仍走 WebSocket Topic `candle.{market}.{symbol}.{interval}`（周期见 [9.2](#92-k-线周期对照表)）。断线 60 秒内可用 `resume` 补缺口；超时返回 `4005`，重新 `subscribe` 即可。

#### 请求示例

```http
GET /api/v1/candles?symbols=BTCUSDT.CR,AAPL.US&interval=5m&limit=40 HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    {
      "s": "BTCUSDT.CR",
      "i": "5m",
      "d": [
        {
          "t": 1723100000000,
          "o": "64800.0",
          "h": "65020.0",
          "l": "64750.0",
          "c": "65000.12",
          "v": "12.5"
        }
      ]
    }
  ]
}
```

---

### 5.7 GET /api/v1/symbols — 标的列表

分页查询可订阅标的列表。按 `market` 过滤，用 `q` 搜索代码或名称。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/symbols` |
| 鉴权 | 请求头 deeptoken |
| 限流 | 受套餐限流约束 |

#### 请求参数

| 参数 | 类型 | 必填 | 默认 | 说明 |
|------|------|------|------|------|
| `market` | String | 否 | — | 市场码过滤，非法返回 `8002` |
| `keyword` | String | 否 | — | 代码/名称模糊搜索，≤64 字符 |
| `capability` | String | 否 | — | 能力过滤：`tick` / `quote` / `depth` / `candle` / `metadata` |
| `page` | int | 否 | 1 | 页码，≥1 |
| `page_size` | int | 否 | 20 | 每页条数，1~200 |

#### 响应字段

`data.d` 为数组，每个元素为标的元信息（`SymbolInfo`）：

| 字段 | 类型 | 说明 |
|------|------|------|
| `symbol` | String | 标的代码（如 `BTCUSDT.CR`） |
| `name` | String | 名称 |
| `market` | String | 市场码 |
| `base_currency` | String | 基础币种（加密货币、外汇适用） |
| `quote_currency` | String | 计价币种 |
| `status` | String | 状态：`ACTIVE`（可订阅）/ `SUSPENDED`（暂停） |
| `capabilities` | Array<String> | 支持的数据类型（如 `["tick","quote","depth","candle"]`） |
| `price_precision` | int | 价格小数位 |
| `size_precision` | int | 数量小数位 |

`data`：

| 字段 | 类型 | 说明 |
|------|------|------|
| `n` | long | 总条数 |
| `d` | Array | 当前页 |
| `mt.pg` / `mt.ps` | int | 页码 / 页大小 |

#### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `8002` | 400 | 市场码不支持 |
| `400` | 400 | 参数缺失 / 类型不匹配 / 校验失败 |

#### 请求示例

```http
GET /api/v1/symbols?market=CR&page=1&page_size=20 HTTP/1.1
Host: api.deeptick.cc
deeptoken: your_api_key
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "n": 128,
    "d": [{
      "symbol": "BTCUSDT.CR",
      "name": "Bitcoin",
      "market": "CR",
      "base_currency": "BTC",
      "quote_currency": "USDT",
      "status": "ACTIVE",
      "capabilities": ["tick", "quote", "depth", "candle"],
      "price_precision": 2,
      "size_precision": 6
    }],
    "mt": { "m": "CR", "pg": 1, "ps": 20 }
  }
}
```

---

## 6. WebSocket 接口详述

### 6.1 连接与生命周期

#### 连接地址

```
wss://api.deeptick.cc/ws/v1
```

单帧最大 65536 字节。短时间内大量新连接会被直接关闭，不返回报文。

#### 连接生命周期

```
客户端                                            服务端（wss://api.deeptick.cc/ws/v1）
  |──── WS 握手（头或查询参数 deeptoken） ────────────>|
  |<─── welcome {session_id, heartbeat_hint:30} ──────|
  |──── subscribe {topics:[...]} ─────────────────────>|
  |<─── ack {op:"subscribe", accepted, rejected} ──────|
  |<─── quote/tick/depth/candle/trade 数据帧 ──────────|   持续推送
  |──── ping {ts} ────────────────────────────────────>|   建议 30s/次
  |<─── pong {ts} ──────────────────────────────────── |
  |                                                    |
  |  (断线)                                            |
  |──── WS 握手（重连，同样带 deeptoken） ────────────>|
  |<─── welcome ────────────────────────────────────── |
  |──── resume {from_seq: N} ──────────────────────────>|   60s 内续传
  |<─── ack {op:"resume", recovered: N} ────────────────|
  |<─── candle 数据帧 (补发) ──────────────────────────|
```

#### 关键规则

- **鉴权**：钥匙在握手时提交。缺少或无效返回 `1001`，已禁用返回 `1004`，IP 不在白名单返回 `2004`。
- **并发限制**：握手通过后占用一个 WS 并发配额，超过套餐上限返回 `3003` 并立即关闭。
- **心跳**：建议按 `welcome.heartbeat_hint`（30s）周期发送 `ping`；60 秒无任何上行消息将收到 `error 4006` 并被断开；服务端 50 秒无推送时主动下发 `server_heartbeat` 保活。
- **断开清理**：连接关闭自动释放并发与订阅配额，并保存 60 秒的 resume 会话摘要。
- **慢消费者保护**：写入不可写期间缓冲容量 1024，超过 10 秒无法写入时断开连接。

### 6.2 信令总表

| 方向 | type | 说明 |
|------|------|------|
| C→S | `subscribe` | 订阅（单次 ≤ 100 topic） |
| C→S | `batch_subscribe` | 批量订阅（与 `subscribe` 等价） |
| C→S | `unsubscribe` | 取消订阅（幂等） |
| C→S | `unsubscribe_all` | 取消全部订阅 |
| C→S | `subscription_list` | 查询当前订阅列表 |
| C→S | `ping` | 客户端心跳 |
| C→S | `resume` | 断线续传（仅 K 线） |
| S→C | `welcome` | 连接成功 |
| S→C | `ack` | 操作确认 |
| S→C | `pong` | 心跳回复 |
| S→C | `server_heartbeat` | 服务端空闲保活提醒 |
| S→C | `error` | 错误推送 |
| S→C | `tick` / `quote` / `depth` / `candle` / `trade` | 行情数据帧 |

---

### 6.3 握手鉴权

钥匙在握手时提交，不必再发 `auth` 帧。服务端客户端放握手头 `deeptoken`；浏览器放查询参数 `deeptoken`。通过后立刻收到 `welcome`，然后就可以订阅。

#### 响应 — welcome (S→C)

握手成功后立即下发：

```json
{
  "type": "welcome",
  "session_id": "a1b2c3d4-e5f6-7890-1234-5678abcdef01",
  "heartbeat_hint": 30
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `session_id` | String | 会话唯一标识（UUID） |
| `heartbeat_hint` | int | 建议心跳间隔（秒），默认 30 |

#### 握手失败

缺少或无效的 API Key 在握手阶段被拒绝。

| 错误码 | 说明 |
|--------|------|
| `1001` | 缺少或无效的 API Key |
| `1004` | API Key 已禁用 |
| `2004` | IP 不在白名单 |
| `3003` | WebSocket 并发连接超限（直接关闭连接） |

---

### 6.4 订阅 (subscribe / batch_subscribe)

`subscribe` 与 `batch_subscribe` 等价，用于订阅行情 Topic。

#### 请求消息

一次请求里的 Topic 必须是同一种类型，例如全部是 `quote`。`quote` 和 `depth` 写在同一条里会整批返回 `4001`，需要分成两条发送。

```json
{
  "type": "subscribe",
  "topics": ["quote.CR.BTCUSDT", "quote.US.AAPL"],
  "request_id": "r1"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"subscribe"` 或 `"batch_subscribe"` |
| `topics` | Array<String> | 是 | Topic 列表。单次最多 100 个，且必须是同一种类型。超限或混类型整批拒绝 `4001` |
| `request_id` | String | 否 | 客户端自定义请求标识，用于匹配 ack |

#### 限制

| 限制项 | 值 | 超限错误码 |
|--------|-----|------------|
| 单次订阅上限 | 100 topic/次，且同一种类型 | `4001`（整体拒绝） |
| 单连接订阅上限 | 400 topic | `4003` |
| 套餐订阅总量 | FREE 10，BASE 100，PRO 500，PREMIUM 2000 | `3002` |

#### 响应 — ack (S→C)

```json
{
  "type": "ack",
  "op": "subscribe",
  "status": "ok",
  "request_id": "r1",
  "accepted": ["quote.CR.BTCUSDT", "quote.US.AAPL"],
  "rejected": ["quote.XX.NOPE:4004"],
  "removed": [],
  "ignored": [],
  "active_topics": 2,
  "recovered": null
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `op` | String | 操作类型，`"subscribe"` |
| `status` | String | `"ok"` 表示操作完成（含部分成功） |
| `request_id` | String | 客户端传入的 request_id |
| `accepted` | Array<String> | 成功订阅的 Topic 列表 |
| `rejected` | Array<String> | 被拒绝的 Topic，格式为 `topic:错误码`（如 `tick.XX.A:4001`） |
| `removed` | Array | 取消订阅时使用，subscribe 中为空 |
| `ignored` | Array<String> | 忽略的 Topic（如重复订阅、未订阅的取消） |
| `active_topics` | int | 当前连接活跃 Topic 总数 |
| `recovered` | Integer | resume 时补发的条数，其他场景为 null |

#### 部分成功机制

- 支持**部分成功**：`accepted` 为成功列表，`rejected` 条目格式为 `topic:错误码`，不会整体失败。混用多种行情类型不属于部分成功，整批返回 `4001`。
- 重复订阅**幂等成功**（计入 `accepted`，不重复扣配额）。
- 某个标的第一次被订阅时，服务端会向行情源建立订阅，随后开始推送。同一标的已经在推送时，再次订阅直接成功。

---

### 6.5 取消订阅 (unsubscribe / unsubscribe_all)

#### unsubscribe

取消指定 Topic 的订阅（幂等操作）。

##### 请求消息

```json
{
  "type": "unsubscribe",
  "topics": ["quote.CR.BTCUSDT", "depth.US.AAPL"],
  "request_id": "r2"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"unsubscribe"` |
| `topics` | Array<String> | 是 | 要取消的 Topic 列表 |
| `request_id` | String | 否 | 客户端自定义请求标识 |

##### 响应 — ack (S→C)

```json
{
  "type": "ack",
  "op": "unsubscribe",
  "status": "ok",
  "request_id": "r2",
  "accepted": [],
  "rejected": [],
  "removed": ["quote.CR.BTCUSDT", "depth.US.AAPL"],
  "ignored": ["tick.HK.00700"],
  "active_topics": 0,
  "recovered": null
}
```

| 字段 | 说明 |
|------|------|
| `removed` | 成功取消的 Topic 列表 |
| `ignored` | 未订阅的 Topic（幂等，不报错） |

#### unsubscribe_all

取消当前连接的全部订阅。

##### 请求消息

```json
{
  "type": "unsubscribe_all",
  "request_id": "r3"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"unsubscribe_all"` |
| `request_id` | String | 否 | 客户端自定义请求标识 |

##### 响应 — ack (S→C)

```json
{
  "type": "ack",
  "op": "unsubscribe_all",
  "status": "ok",
  "request_id": "r3",
  "accepted": [],
  "rejected": [],
  "removed": 5,
  "ignored": [],
  "active_topics": 0,
  "recovered": null
}
```

> **注意**：`unsubscribe_all` 的 `removed` 字段为**数量**（Integer），而非 Topic 列表（与 `unsubscribe` 不同）。

---

### 6.6 查询订阅列表 (subscription_list)

查询当前连接已订阅的全部 Topic。

#### 请求消息

```json
{
  "type": "subscription_list",
  "request_id": "r4"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"subscription_list"` |
| `request_id` | String | 否 | 客户端自定义请求标识 |

#### 响应 (S→C)

```json
{
  "type": "subscription_list",
  "topics": ["quote.CR.BTCUSDT", "candle.CN.600519.SH.1m"],
  "request_id": "r4"
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `topics` | Array<String> | 当前已订阅的 Topic 列表 |
| `request_id` | String | 客户端传入的 request_id |

---

### 6.7 心跳 (ping/pong) + server_heartbeat

#### 客户端心跳 (ping)

```json
{
  "type": "ping",
  "ts": 1723100000000,
  "request_id": "h1"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"ping"` |
| `ts` | long | 是 | 客户端时间戳（毫秒） |
| `request_id` | String | 否 | 客户端自定义请求标识 |

#### 服务端回复 (pong)

```json
{
  "type": "pong",
  "ts": 1723100000123
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `ts` | long | 服务端时间戳（毫秒） |

#### 服务端保活 (server_heartbeat)

当服务端 50 秒无任何推送时，主动下发：

```json
{
  "type": "server_heartbeat",
  "ts": 1723100000000
}
```

#### 心跳规则

| 规则 | 值 | 说明 |
|------|-----|------|
| 建议心跳间隔 | 30 秒 | `welcome.heartbeat_hint` |
| 读空闲超时 | 60 秒 | 无任何客户端消息 → `error 4006` + 断开 |
| 写空闲超时 | 50 秒 | 无推送 → 下发 `server_heartbeat` |
| 未鉴权连接 | 允许 ping | 保活不耗配额 |
| 数据推送 | 计为活跃消息 | 重置读空闲计时 |

> 任何客户端上行消息（ping/subscribe/...）都会重置 60 秒读空闲计时。

---

### 6.8 断线续传 (resume)

仅针对 **candle（K 线）** 数据，解决断线期间的 K 线缺口。tick/quote/depth/trade 不补发。

#### 触发条件

客户端断线后 60 秒内重连，握手带上 `deeptoken` 并收到 `welcome` 后，发送 `resume` 信令。

#### 请求消息

```json
{
  "type": "resume",
  "from_seq": 100200
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"resume"` |
| `from_seq` | long | 是 | 客户端记录的最后一条 candle 的 seq 值 |

#### 响应 — ack (S→C)

```json
{
  "type": "ack",
  "op": "resume",
  "status": "ok",
  "request_id": null,
  "accepted": [],
  "rejected": [],
  "removed": [],
  "ignored": [],
  "active_topics": 3,
  "recovered": 31
}
```

| 字段 | 说明 |
|------|------|
| `recovered` | 补发的 K 线条数 |

#### 机制说明

1. 断线后 60 秒内可以 resume，超时返回 `4005`。
2. 只补发 K 线，按 `seq` 大于 `from_seq` 的条目发送。
3. 成功后恢复断线前的订阅。同一次断线只能恢复一次。

#### 限制

- **仅 candle 可补发**：tick/quote/depth/trade 不补。
- **仅同实例有效**：resume 缓冲在服务实例内存中，跨实例故障转移后同样收到 `4005`。
- **窗口 60 秒**：超过 60 秒再 resume 会返回 `4005`。

#### 错误处理

| 错误码 | 说明 | 处理建议 |
|--------|------|----------|
| `4005` | resume 超出 60s 窗口或会话摘要不存在 | 重新 `subscribe`；实时 K 线无法用 REST 回补历史缺口 |

---

### 6.9 数据帧推送格式 (tick/quote/depth/candle/trade)

服务端向客户端推送的行情数据帧统一格式：

```json
{
  "type": "candle",
  "topic": "candle.CR.BTCUSDT.1m",
  "ts": 1723100000000,
  "seq": 42,
  "data": {
    "m": "CR",
    "s": "BTCUSDT",
    "i": "1m",
    "t": 1723100000000,
    "o": 65000.0,
    "h": 65100.0,
    "l": 64950.0,
    "c": 65050.0,
    "v": 123.4,
    "tu": 8020000.0
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `type` | String | 数据类型，与 topic 首段一致（`tick`/`quote`/`depth`/`candle`/`trade`） |
| `topic` | String | 订阅的 Topic 原文 |
| `ts` | long | 行情时间戳（Unix 毫秒） |
| `seq` | Long | 递增序号，**仅 candle 类型携带**（用于 resume 断线续传） |
| `data` | Object | 行情内容。价格等小数以字符串返回 |

#### 各类型数据帧示例

##### tick（逐笔成交）

```json
{
  "type": "tick",
  "topic": "tick.CR.BTCUSDT",
  "ts": 1723100000000,
  "data": {
    "s": "BTCUSDT",
    "qt": "TICK",
    "ld": "65000.12",
    "v": "0.5",
    "t": 1723100000000
  }
}
```

##### quote（实时报价）

```json
{
  "type": "quote",
  "topic": "quote.CR.BTCUSDT",
  "ts": 1723100000000,
  "data": {
    "s": "BTCUSDT",
    "at": "CRYPTO",
    "qt": "QUOTE",
    "ld": "65000.12",
    "o": "64800.0",
    "h": "65200.0",
    "l": "64750.0",
    "v": "1234.5",
    "tu": "80000000",
    "t": 1723100000000
  }
}
```

##### depth（盘口深度）

订阅 `depth.{market}.{symbol}`。推送的是当前买卖盘截面，档数以实际返回为准。字段与 REST 盘口查询相同：`s`、`b`、`a`、`t`。`b`/`a` 每档为 `{p, v}`。

```json
{
  "type": "depth",
  "topic": "depth.US.AAPL",
  "ts": 1723100000000,
  "data": {
    "s": "AAPL",
    "b": [{"p": "150.10", "v": "100"}, {"p": "150.00", "v": "200"}],
    "a": [{"p": "150.20", "v": "150"}, {"p": "150.30", "v": "300"}],
    "t": 1723100000000
  }
}
```

##### candle（K 线）

实时 K 线由成交价和成交量合成，不带成交额 `tu` 和成交笔数 `tc`。历史成交额用 REST `GET /api/v1/candles`。

```json
{
  "type": "candle",
  "topic": "candle.CN.600519.SH.1m",
  "ts": 1723100000000,
  "seq": 100231,
  "data": {
    "m": "CN",
    "s": "600519.SH",
    "i": "1m",
    "t": 1723100000000,
    "o": 1680.0,
    "h": 1685.0,
    "l": 1678.0,
    "c": 1682.0,
    "v": 5000
  }
}
```

##### trade（成交聚合）

```json
{
  "type": "trade",
  "topic": "trade.CR.ETHUSDT",
  "ts": 1723100000000,
  "data": {
    "ld": "3200.50",
    "v": "1.2",
    "t": 1723100000000
  }
}
```

---

### 6.10 Topic 命名规范详解

#### 格式

```
{type}.{market}.{symbol}[.{interval}]
```

#### 解析规则（TopicParser）

1. 以 `.` 分割 Topic 字符串。
2. 第 1 段为 `type`（数据类型）。
3. 第 2 段为 `market`（市场码）。
4. `candle` 的末段为 `interval`（1m/5m/15m/30m/60m/4h/1d/1w/1M），中间各段合并为 `symbol`（可含点，如 `600519.SH`）。
5. `tick` / `quote` / `depth` / `trade` 没有末段参数，第 3 段起全部为 `symbol`。

#### 完整示例

| Topic | type | market | symbol | 参数 |
|-------|------|--------|--------|------|
| `tick.CR.BTCUSDT` | tick | CR | BTCUSDT | — |
| `quote.US.AAPL` | quote | US | AAPL | — |
| `depth.HK.00700` | depth | HK | 00700 | — |
| `candle.CN.600519.SH.1m` | candle | CN | 600519.SH | interval=1m |
| `trade.CR.ETHUSDT` | trade | CR | ETHUSDT | — |

#### 第一次订阅

某个标的第一次被订阅时，服务端会向行情源建立订阅，随后开始推送。同一标的已经在推送时，再次订阅直接成功。

---

## 7. 限流与配额

### 7.1 套餐档位与配额表

| 控制台套餐 | 订阅上限 | WS 并发连接 | REST 限流（次/分钟） |
|------------|----------|-------------|---------------------|
| `FREE` | 10 | 1 | 5 |
| `BASE` | 100 | 3 | 30 |
| `PRO` | 500 | 6 | 300 |
| `PREMIUM` | 2000 | 12 | 600 |

额度以控制台当前方案为准。REST 超限返回 HTTP 429、code `3001`，并带 `Retry-After`。WebSocket 连接超限 `3003`，订阅总量超限 `3002`。

#### FREE 固定标的

FREE 只能请求下表 6 个标的（REST 与 WebSocket 相同）。其他品种返回 `4002`，需升级 BASE 及以上。

| Symbol | 市场 |
|--------|------|
| `AAPL.US` | 美股 |
| `EURUSD.FX` | 外汇 |
| `BTCUSDT.CR` | 加密货币 |
| `SPX.IDX` | 指数 |
| `XAUUSD.MT` | 贵金属 |
| `XAGUSD.MT` | 贵金属 |

### 7.2 REST 限流

按最近 60 秒内的请求次数计算。超限返回 HTTP `429`、`code` `3001`，响应头 `Retry-After` 是建议等待的秒数。`GET /api/v1/time` 不计入。

### 7.3 WebSocket 并发限制

| 限制项 | 超限错误码 | 说明 |
|--------|------------|------|
| WS 并发连接 | `3003` | 达到套餐连接数后拒绝，并立即关闭 |
| 订阅总量 | `3002` | 达到套餐订阅上限 |
| 单连接上限 | `4003` | 单连接最多 400 个 Topic，与套餐无关 |
| 单次订阅上限 | `4001` | 一次最多 100 个 Topic，且必须同一种类型。超出或混类型则本次整体拒绝 |

连接关闭后释放占用的连接数。

### 7.4 连接过载

短时间内大量新连接会被直接关闭，不返回报文。

---

## 8. 错误码字典

### 8.1 鉴权类 (1xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 1001 | 401 | Token 无效 | API Key 不存在或无效 |
| 1002 | 401 | Token 已过期 | 请求时间偏差 > 300 秒 |
| 1003 | 401 | Refresh Token 失效 | （预留） |
| 1004 | 403 | 账号被禁用 | API Key `enabled=false` |
| 1005 | 401 | MFA 缺失或错误 | （预留） |
| 1006 | 429 | 登录尝试次数过多 | （预留） |
| 1007 | 401 | Google 授权失败 | （预留） |
| 1008 | 409 | Google 邮箱归属冲突 | （预留） |
| 1009 | 400 | Google 回调 redirect_uri 不在白名单 | （预留） |

### 8.2 应用类 (2xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 2001 | 404 | App 不存在 | （预留） |
| 2002 | 403 | App 已禁用 | （预留） |
| 2003 | 403 | App 已删除 | （预留） |
| 2004 | 403 | IP 不在白名单 | Key 配置了 IP 白名单且请求出口 IP 不在列表 |
| 2006 | 403 | product_grant 不在套餐范围内 | （预留） |
| 2007 | 503 | 依赖服务不可用 | REST 标的目录/快照回退等整请求依赖故障；**不用于** WS `subscribe.rejected` |

### 8.3 配额类 (3xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 3001 | 429 | QPS 超限 | REST 限流超限（附 `Retry-After` 响应头） |
| 3002 | 429 | 订阅符号数超限 | WS 订阅总量超套餐 |
| 3003 | 429 | WebSocket 并发超限 | WS 连接数超套餐（直接关闭连接） |
| 3004 | 429 | 历史 K 线回溯超套餐范围 | `GET /api/v1/candles` 的 `et` 早于套餐 `lookbackYears`（FREE/BASE 1 年，PRO 3 年，PREMIUM 5 年） |
| 3005 | 429 | 当月用量已达 100% | （预留，暂未启用） |

### 8.4 订阅类 (4xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 4001 | 400 | 订阅 Topic 无效 | 格式错误、单次超过 100 个，或同一次混了多种行情类型。混类型时整批拒绝 |
| 4002 | 403 | 无权限订阅该 Topic | 无权限，或 FREE 请求了未开放标的 |
| 4003 | 400 | 单连接订阅数超 400 | 超出 `max-topics-per-connection` |
| 4004 | 404 | Topic 对应标的不存在或当前不可订阅 | 该标的当前不能订阅；写入 `rejected` 为 `topic:4004` |
| 4005 | 400 | resume 超出 60s 窗口 | 会话摘要不存在 / `from_seq` 过旧 |
| 4006 | — | 心跳超时，服务端断开连接 | 读空闲 60 秒无上行消息 |
| 4007 | — | 取消订阅失败（未订阅，幂等进 ignored） | 日志排查用 |
| 4008 | — | 重复订阅（已订阅，幂等成功，仅提示） | 提示用 |
| 4009 | — | 连接未完成鉴权即操作订阅 | 未 auth 先 subscribe（仅 ping 放行） |
| 4010 | — | 订阅列表查询失败（连接无会话上下文） | 防御性错误 |

> HTTP 状态码为 `—` 的错误码仅用于 WS 场景，不对应 HTTP 状态码。

### 8.5 行情数据类 (8xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 8001 | 404 | 标的不存在 | 仅「标的不存在」场景。行情接口暂无数据返回 HTTP 200 空数据，不再报 8001，详见 [5.5 节](#55-没有快照时的返回) |
| 8002 | 400 | 市场码不支持 | `market` 不在 CN/HK/US/FX/MT/CR/IDX 集合 |
| 8004 | 400 | 成交查询参数超限 | `trades` 的 `limit` > 200 |
| 8005 | 400 | 报价一次只能查询 1 个标的 | `quote` 的 `symbols` 多于 1 个 |
| 8006 | 400 | K 线标的数量超限 | `candles` 的 `symbols` > 20 |
| 8007 | 400 | K 线条数超限 | `candles` 的 `limit` > 500 |
| 8008 | 400 | K 线周期不支持 | `candles` 的 `interval` 非法 |

### 8.6 REST 通用参数错误

| code | HTTP 状态码 | 说明 |
|------|------------|------|
| 400 | 400 | 参数缺失 / 类型不匹配 / 校验失败 |

### 8.7 参数错误

缺少参数、类型不匹配或校验失败时，返回 HTTP 400，`code` 为 `400`。业务错误码见上面各表。

---
