# DeepTick 行情数据 API 文档

> 版本：v3.8（2026-10） · 对外接口口径与官网控制台「API 文档」一致（https://www.deeptick.cc 控制台 / 官网文档）
>
> **权威来源**：公开 REST / WebSocket 路径、字段名、错误码、套餐配额以官网为准。本文第 1～8 章描述对客契约；第 9～10 章为网关内部实现附录（quote-gateway、配置项），不作为对客接口承诺。

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
- [9. 附录](#9-附录)
- [10. 附录：上游行情采集网关 (quote-gateway)](#10-附录上游行情采集网关-quote-gateway)

---

## 1. 文档概述

### 1.1 文档目的与适用范围

本文档面向接入 DeepTick 行情服务的开发者，完整描述 DeepTick 行情网关（push-gateway）提供的 REST API 与 WebSocket API 两种接入方式。

| 接入方式 | 说明 | 适用场景 |
|----------|------|----------|
| **REST API** | HTTPS/JSON，请求头 `deeptoken` 带 API Key 整串；实时报价、盘口、成交、K 线查询与标的检索 | 低频查询、快照初始化 |
| **WebSocket API** | 长连接发布/订阅，握手时带 `deeptoken`，实时推送 tick / quote / depth / candle / trade | 实时看板、策略信号、K 线推送 |

### 1.2 DeepTick 行情服务简介

DeepTick 行情服务由两个核心网关协同构成完整的数据链路：

- **deeptick-quote-gateway**（行情采集网关，端口 8082）：负责从外部数据源（AllTick / iTick）通过 WebSocket 订阅获取实时行情，经适配器层归一化处理后，通过 Redis Stream 发布给下游消费。技术栈：Java 17 + Spring Boot 3.2.12 + Netty + LMAX Disruptor + Redis Stream。
- **deeptick-push-gateway**（行情推送网关，端口 8081/8084）：面向客户端的实时行情推送网关，从 Redis Stream 消费行情数据，经分发主链路推送给 WebSocket 客户端；REST 快照经 quote-gateway 查上游。基于以下核心技术栈构建：
  - **语言/框架**：Java 17 + Spring Boot 3.2.12（REST/Actuator）+ Netty 4.1.109（WebSocket）
  - **数据存储**：Redis（Lettuce，用于鉴权/配额/限流/订阅注册表/行情流）+ MySQL（用量/平台统计；标的目录在 quote-gateway）
  - **缓存/队列**：Caffeine（API Key 本地缓存 60s）+ JCTools（有界投递队列）
  - **可观测性**：Micrometer + Actuator（health/info/prometheus）

全链路数据流架构：

```
外部数据源 (AllTick / iTick)
    |  WebSocket 订阅
    v
deeptick-quote-gateway (:8082)
    |-- 适配器层 (AllTickAdapter / ITickAdapter)
    |-- 行情流水线 (解析 / 清洗 / 归一化 / 去重 / K线聚合)
    |-- Disruptor 高性能队列
    `-- QuoteRedisStreamProducer --XADD--> Redis Stream (market:stream)
                                        |
    v-----------------------------------|
deeptick-push-gateway
    |-- DataSourceRouter (热切换 / 去重 / 有界队列)
    |-- MarketDataFanout (分发主链路)
    |     |-- TopicDispatcher --> Netty WS (:8081 /ws/v1) --> 客户端 xN
    |     `-- MarketSnapshotCache (进程内旁路；REST 生产路径不读缓存)
    |-- REST /quote /orderbook /trades /candles --> quote-gateway snapshots --> AllTick/iTick
    |-- REST /symbols --> quote-gateway --> MySQL supported_symbols
    `-- UpstreamSubscribeService (Redis注册表查重 -> quote-gateway REST 订阅)
```

> 详细的 quote-gateway 架构说明见 [第 10 章内部附录](#10-附录上游行情采集网关-quote-gateway)。

### 1.3 术语表

| 术语 | 英文 | 说明 |
|------|------|------|
| API Key | deeptoken | 控制台复制的整串钥匙。REST 放请求头，WebSocket 放握手头或查询参数 |
| 套餐档位 | Plan Tier | 控制台 FREE / BASE / PRO / PREMIUM，网关映射为 FREE / DEVELOPER / PRO / ENTERPRISE |
| Topic | — | WebSocket 订阅标识，格式为 `{type}.{market}.{symbol}`，K 线再加 `.{interval}` |
| 标的 | Symbol | 交易品种代码，格式为 `{code}.{market}`，如 `BTCUSDT.CR` |
| 市场码 | Market | 资产类别标识，如 CN/HK/US/CR 等 |
| 快照 | Snapshot | REST 经 quote-gateway 查上游：实时报价 / 盘口 / 成交 / K 线 |
| 断线续传 | Resume | 断线重连后补发缺失的 K 线数据（仅 WS candle，60s 窗口） |
| 令牌桶 | Token Bucket | 连接风暴保护算法，限制新连接速率 |
| 滑动窗口 | Sliding Window | REST 限流算法，基于 Redis ZSET 实现 |

---

## 2. 快速开始

### 2.1 接入流程概述

```
申请 API Key → 请求头 deeptoken → REST/WebSocket 接入
```

1. **申请 API Key**：在 DeepTick 控制台注册账号并创建 API Key，复制整串钥匙。REST 和 WebSocket 用同一把钥匙。
2. **REST 接入**：把钥匙放进请求头 `deeptoken`。`GET /api/v1/time` 免鉴权、免限流。
3. **WebSocket 接入**：连接 `wss://api.deeptick.cc/ws/v1`。服务端客户端把 `deeptoken` 放进握手头；浏览器不能自定义握手头，把同一整串放在查询参数 `deeptoken`。收到 `welcome` 后直接订阅。
4. **时钟对齐**：调用 `GET /api/v1/time` 确认网关可达，并把本地时钟对准服务器。

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

生产环境使用统一域名。REST 与 WebSocket 端口在网关侧分离，对外分别映射到 HTTPS 与 WSS。

| 协议 | 地址 | 说明 |
|------|------|------|
| REST | `https://api.deeptick.cc/api/v1/*` | JSON over HTTPS |
| WebSocket | `wss://api.deeptick.cc/ws/v1` | JSON 文本帧 |

本地联调（网关进程端口，不对客）：

| 协议 | 端口 | 路径 | 示例 |
|------|------|------|------|
| REST API | 8084 | `/api/v1/*` | `http://localhost:8084/api/v1/quote` |
| WebSocket | 8081 | `/ws/v1` | `ws://localhost:8081/ws/v1` |
| Actuator | 8084 | `/actuator/*` | `http://localhost:8084/actuator/health` |

> **管理端点**：`/admin/*` 路径仅供内部运维使用，需 `X-Admin-Token` 鉴权（fail-closed 策略，token 未配置时返回 503）。

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

API Key 数据结构（`ApiKeyInfo`）：

| 字段 | 类型 | 说明 |
|------|------|------|
| `ak` | String | API Key 整串，请求时放在 `deeptoken` |
| `plan` | PlanTier | 网关档位（FREE / DEVELOPER / PRO / ENTERPRISE），对应控制台 FREE / BASE / PRO / PREMIUM |
| `enabled` | boolean | 是否启用；禁用的 ak 一律拒绝 |
| `rateLimit` | Integer | 单 Key 限流值（次/分钟），`null` 表示不收紧、回落套餐默认值 |

存储方式：Redis string JSON，key 为 `apikey:{ak}`，Caffeine 本地缓存 TTL 60 秒。

#### 套餐档位

配额按 appKey 的 `plan` 生效（控制台：`FREE/BASE/PRO/PREMIUM` → 网关 `FREE/DEVELOPER/PRO/ENTERPRISE`）。

| 控制台套餐 | 网关档位 | 订阅上限 | WS 并发连接 | REST 限流（次/分钟） |
|------------|----------|----------|-------------|---------------------|
| `FREE` | `FREE` | 10 | 1 | 10 |
| `BASE` | `DEVELOPER` | 100 | 3 | 60 |
| `PRO` | `PRO` | 500 | 6 | 600 |
| `PREMIUM` | `ENTERPRISE` | 3000 | 12 | 1200 |

> 所有默认值以 admin `plans.quotas` → Redis `planquota:{TIER}` 为准；本地 YAML/`PlanTier` 仅兜底。单 Key 的 `rateLimit` 只能收紧（取 min），不能突破套餐限额。
>
> push-gateway 限制逻辑：`AuthHandler` 鉴权后占 WS 连接配额（超限 `3003`）；`SubscriptionHandler` 占订阅总量（超限 `3002`）；`RateLimitFilter` 按次/分钟限 REST（超限 `3001`）。

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
| Actuator | `/actuator/**` | 健康检查与监控端点 |

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

REST 接口统一前缀 `/api/v1`，由以下 Controller 提供：

| Controller | 接口 |
|------------|------|
| `TimeController` | `GET /api/v1/time` |
| `SnapshotController` | `GET /api/v1/quote`、`GET /api/v1/candles`、`GET /api/v1/orderbook`、`GET /api/v1/trades` |
| `SymbolsController` | `GET /api/v1/symbols` |

Filter 链处理顺序：

1. `SignatureFilter`（order=10）：校验请求头 `deeptoken`，仅拦截 `/api/*`，白名单 `/api/v1/time` + `/actuator/**`
2. `RateLimitFilter`（order=20）：限流，仅拦截 `/api/*`，白名单同上

---

### 5.1 GET /api/v1/time — 服务器时间

获取服务器当前时间，用于连通性检查与时钟对齐。

| 属性 | 值 |
|------|-----|
| 路径 | `GET /api/v1/time` |
| 鉴权 | **免鉴权**（SignatureFilter 白名单） |
| 限流 | **免限流**（RateLimitFilter 白名单） |

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

查询一个标的的当前最新价和当日统计。一次只能传 1 个代码，多个代码返回 `8005`。生产路径经 quote-gateway 查上游。字段上游有才出现，缺失则省略，值为 0 时保留。报价不带买一卖一，多档买卖盘用盘口查询。加密货币报价通常没有昨收和涨跌。

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
| `at` | String | 资产类型（若上游提供） |
| `ld` | String/Number | 最新价 |
| `p` | String/Number | 前收 |
| `o` / `h` / `l` | String/Number | 开 / 高 / 低 |
| `v` | String/Number | 成交量 |
| `tu` | String/Number | 成交额。上游没有则不出现 |
| `ch` / `chp` | String/Number | 涨跌额 / 涨跌幅。上游没有则不出现，0 保留 |
| `ts` | int | 交易状态。0 正常，1 停牌，2 退市，3 熔断。不是时间戳。上游没有则不出现 |
| `t` | long | 行情时间戳（Unix 毫秒） |

> `p`、`ch`、`chp`、`tu`、`ts` 都是上游有才出现。加密货币报价通常没有昨收和涨跌。

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

查询单个标的当前买卖盘。档位统一为 `{p, v}`。一次返回当前盘口，不是历史深度。生产路径经 quote-gateway **始终查上游**。

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
| `t` | long | 时间戳（毫秒，若上游提供） |

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

查询单个标的最近成交列表，最新在前。一次拉取，不是逐笔推送。生产路径经 quote-gateway **始终查上游**。

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

### 5.5 REST 快照查询上游（默认开启）

`/quote`、`/candles`、`/orderbook`、`/trades` 生产路径始终经 quote-gateway 查询上游 AllTick / iTick（`gateway.rest.upstream-snapshot.enabled` 默认 **true**）。进程内 `MarketSnapshotCache` 仅为旁路；关闭回退时（单测）才读缓存。K 线始终查上游、不读不写快照缓存。已订阅产品会锁定 `adapter`，不顺延另一厂商。

**数据流走向**：

```
REST /quote /orderbook /trades /candles
    |-- 命中 MarketSnapshotCache / trades 缓冲 --> 直接返回
    `-- 未命中
        |-- 回退开启（双侧开关均为 true）
        |     --> quote-gateway 上游实时 REST（AllTick 优先 / iTick 后备）
        |     |     |-- 命中 --> 返回结果（quote/orderbook 按 putIfAbsent 回填快照缓存）
        |     `-- 上游也无数据 / 全部源失败 --> HTTP 200 空数据（quote=[] / orderbook 空档 / trades d=[] / candles=[]）
        `-- 回退关闭 --> 生产路径仍返回 HTTP 200 空数据；单测无 Facade 且 candles 全未命中时保留 8001
```

#### 8001 语义说明

| 场景 | 行为 | 8001 语义 |
|------|------|-----------|
| 生产（回退开启） | 上游也无数据 | **不再报 8001**，返回 HTTP 200 空数据 |
| 标的明确不存在 | 目录/订阅拒绝 | `8001` 仅保留给「标的不存在」 |
| 单测无 Facade | candles 全未命中 | 仍可能 8001（测试兼容） |

回退开启条件（双侧开关需**同时**为 `true`）：

- push-gateway：`gateway.rest.upstream-snapshot.enabled=true`
- quote-gateway：`gateway.snapshot-query.enabled=true`

#### 回退配置项清单

**push-gateway（`gateway.rest.upstream-snapshot.*`）**：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | true | 回退总开关（push-gateway 侧，生产默认开启） |
| `quote-gateway-url` | （已废弃） | push→quote 改为 gRPC，不再使用 HTTP 基地址 |
| `connect-timeout-millis` | 2000 | 回退请求连接超时 |
| `request-timeout-millis` | 1500 | 回退请求响应超时 |
| `max-concurrent` | 32 | 回退在途并发上限 |
| `write-back-enabled` | true | 命中结果是否回填快照缓存 |
| `worker-threads` | 8 | 回退工作线程数 |
| `executor-queue-capacity` | 256 | 回退执行器队列容量 |

**quote-gateway（`gateway.snapshot-query.*`）**：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | true | 快照查询总开关（quote-gateway 侧，生产默认开启） |
| `alltick-base-host` | - | AllTick 行情 REST 基准地址 |
| `alltick-stock-base-host` | - | AllTick 股票 REST 基准地址 |
| `itick-base-host` | - | iTick REST 基准地址 |
| `alltick-qps` | 0.15 | AllTick 源令牌桶限速（QPS） |
| `itick-qps` | 0.08 | iTick 源令牌桶限速（QPS） |
| `connect-timeout-ms` | 3000 | 上游连接超时 |
| `read-timeout-ms` | 5000 | 上游读取超时 |
| `positive-cache-ttl-ms` | 2000 | 正缓存 TTL |
| `negative-cache-ttl-ms` | 3000 | 负缓存 TTL（3 秒） |

> **上线 checklist 注意**：双侧开关需**同时开启**回退才生效；仅开一侧时（如 push-gateway 侧开启但 quote-gateway 侧关闭），push-gateway 调用 quote-gateway 快照查询不可用，将**静默回落**返回 HTTP 200 空数据。

#### 降级策略摘要

1. **选源对齐订阅规则**：优先读取订阅注册表（Redis `deeptick:subscription:{symbol}`）中的 `adapterSource` 确定数据源；未命中注册表时按默认优先级选源（alltick 优先、itick 后备，优先级可配）。
2. **assetType 支持矩阵过滤**：按数据源 assetType 支持矩阵筛选候选源，并跳过处于配额熔断冷却期的源。
3. **令牌桶限速**：每源独立 TokenBucket 限速（默认 alltick 0.15 QPS / itick 0.08 QPS），超限则顺延下一源。
4. **单源失败顺延**：单源查询失败（超时 / 无数据 / 限流）时自动顺延下一候选源。
5. **结果缓存防击穿**：命中结果正缓存 2s，无数据结果负缓存 3s，防止缓存击穿。
6. **全败回落**：所有候选源均失败时回落 HTTP 200 空数据（不再报 8001）。
7. **快照回填**：命中结果按 `putIfAbsent` 回填 push-gateway 快照缓存（`/trades` 不回填）。

---

### 5.6 GET /api/v1/candles — K 线查询

批量查询 K 线。始终查上游，不走进程内缓存。K 线按时间升序。实时更新请订阅 WebSocket `candle.{market}.{symbol}.{interval}`。

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

分页查询可订阅标的列表。数据源为 quote-gateway MySQL 产品目录（`supported_symbols`）。

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

#### 传输层配置

| 配置项 | 值 | 说明 |
|--------|-----|------|
| 传输层 | epoll(Linux) / kqueue(macOS) / NIO | 自适应 |
| 路径 | `/ws/v1` | WebSocket 协议路径 |
| so-backlog | 1024 | accept 队列大小 |
| 最大帧 | 65536 bytes | 单帧最大字节 |
| 写缓冲低水位 | 32 KB | 背压低水位 |
| 写缓冲高水位 | 64 KB | 背压高水位 |

#### Pipeline 处理链

```
ConnectionGuard → HttpServerCodec → HttpObjectAggregator → WebSocketServerProtocolHandler(/ws/v1)
  → IdleStateHandler → ClientMessage Decoder → FlowControlHandler → AuthHandler
  → HeartbeatHandler → SubscriptionHandler
```

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

```json
{
  "type": "subscribe",
  "topics": [
    "quote.CR.BTCUSDT",
    "depth.US.AAPL",
    "candle.CN.600519.SH.1m",
    "tick.HK.00700"
  ],
  "request_id": "r1"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | `"subscribe"` 或 `"batch_subscribe"` |
| `topics` | Array<String> | 是 | Topic 列表，单次最多 100 个，超限整体拒绝 `4001` |
| `request_id` | String | 否 | 客户端自定义请求标识，用于匹配 ack |

#### 限制

| 限制项 | 值 | 超限错误码 |
|--------|-----|------------|
| 单次订阅上限 | 100 topic/次 | `4001`（整体拒绝） |
| 单连接订阅上限 | 400 topic | `4003` |
| 套餐订阅总量 | 按套餐档位（FREE:10 ~ ENTERPRISE:3000） | `3002` |

#### 响应 — ack (S→C)

```json
{
  "type": "ack",
  "op": "subscribe",
  "status": "ok",
  "request_id": "r1",
  "accepted": ["quote.CR.BTCUSDT", "depth.US.AAPL"],
  "rejected": ["candle.CN.600519.SH.9m:4001"],
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

- 支持**部分成功**：`accepted` 为成功列表，`rejected` 条目格式为 `topic:错误码`，不会整体失败。
- 重复订阅**幂等成功**（计入 `accepted`，不重复扣配额）。
- 上游订阅联动：订阅时先查 Redis 全局订阅注册表（`deeptick:subscription:` 前缀），已订阅直接成功；未订阅异步调用 quote-gateway 订阅接口，成功后缓存 Redis。

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

1. **断线保存**：断线时保存会话摘要 `{ak, 订阅列表, 各 candle topic last_seq}` 到 Caffeine（TTL 60 秒）。
2. **resume 查找**：按 ak 查找摘要，不存在或过期返回 `error 4005`。
3. **补发**：从 `CandleRingBuffer`（环形缓冲，容量 256）收集 `seq > from_seq` 的 candle 条目补发。
4. **恢复订阅**：成功后自动恢复断线前的订阅列表。
5. **一次性消费**：摘要一次性消费，避免重复恢复。

#### 限制

- **仅 candle 可补发**：tick/quote/depth/trade 不补。
- **仅同实例有效**：resume 缓冲在服务实例内存中，跨实例故障转移后同样收到 `4005`。
- **窗口 60 秒**：`resume-ttl-millis` 和 `resume-window-millis` 均为 60000 毫秒。

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
| `data` | Object | 行情内容透传（网关不解析，价格等 decimal 保持字符串原样） |

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

订阅 `depth.{market}.{symbol}`。推送的是当前买卖盘截面，档数以上游实际返回为准。字段与 REST 盘口查询相同：`s`、`b`、`a`、`t`。`b`/`a` 每档为 `{p, v}`。

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

#### 上游订阅联动

WS 订阅时先查 Redis 全局订阅注册表（`deeptick:subscription:` 前缀）：

- **已订阅**：直接成功，无需重复调用上游。
- **未订阅**：异步调用 quote-gateway（`http://127.0.0.1:8082`）订阅接口，成功后缓存到 Redis。
- **注册表刷新**：每 2 分钟刷新一次（`registry-refresh-minutes`，与拉流租约自愈兜底对齐）。

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | true | 是否启用上游联动 |
| `quote-gateway-url` | （已废弃） | push→quote 改为 gRPC，不再使用 HTTP 基地址 |
| `redis-key-prefix` | `deeptick:subscription:` | Redis 注册表前缀 |
| `connect-timeout-millis` | 2000 | 连接超时 |
| `request-timeout-millis` | 5000 | 请求超时 |
| `worker-threads` | 8 | 工作线程数 |
| `batch-size` | 50 | 批量订阅大小 |
| `registry-refresh-minutes` | 2 | 注册表刷新间隔（分钟） |
| `ownership-retry-max` | 3 | `ownership_pending` / `unavailable` / `draining` 最大尝试次数 |
| `ownership-retry-backoff-millis` | 200 | 上述重试退避基数（毫秒，按次数倍增） |

---

## 7. 限流与配额

### 7.1 套餐档位与配额表

| 控制台套餐 | 网关档位 | 订阅上限 | WS 并发连接 | REST 限流（次/分钟） |
|------------|----------|----------|-------------|---------------------|
| `FREE` | `FREE` | 10 | 1 | 10 |
| `BASE` | `DEVELOPER` | 100 | 3 | 60 |
| `PRO` | `PRO` | 500 | 6 | 600 |
| `PREMIUM` | `ENTERPRISE` | 3000 | 12 | 1200 |

> 数值来源：deeptick-admin `plans.quotas` → Redis `planquota:{PlanTier}`；push-gateway 按 `apikey:{ak}.plan` 读取。
>
> 本地 `gateway.quota.plans.*` / `PlanTier` 内置值仅作 Redis 未命中时的兜底。
>
> 单 Key 的 `rateLimit` 只能收紧（取 min），不能突破套餐限额。
>
> REST 超限返回 HTTP 429、code `3001`，并带 `Retry-After`。WS 连接超限 `3003`，订阅总量超限 `3002`。

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

### 7.2 REST 限流机制

#### 算法

Redis ZSET 滑动窗口（Lua 脚本），窗口 60 秒。

| 配置项 | 说明 |
|--------|------|
| Key | `ratelimit:{ak}` |
| 限额 | `min(套餐默认值, 单Key rateLimit)` |
| 窗口 | 60 秒 |
| 超限响应 | HTTP `429` + `code 3001` + `Retry-After` 响应头（秒） |

#### Lua 滑动窗口脚本逻辑

1. `ZREMRANGEBYSCORE` 清理窗口外成员。
2. `ZCARD` 判断当前窗口计数。
3. 超限：返回距最早成员滑出窗口的等待毫秒数（>0）。
4. 未超限：`ZADD` 当前请求 + `PEXPIRE` 续期，返回 0。

#### 降级策略

Redis 不可用时退化为 Caffeine 固定窗口，单实例配额 = 总配额 × `rate-limit-degrade-factor`（默认 1.0）。

### 7.3 WebSocket 并发限制

| 限制项 | 实现方式 | Key | 超限错误码 | 说明 |
|--------|----------|-----|------------|------|
| WS 并发连接 | Redis Lua check-and-incr | `quota:{ak}:conn` | `3003` | 超限拒绝并立即关闭 |
| 订阅总量 | Redis INCRBY 批量原子判限 | `quota:{ak}:topics` | `3002` | 超限部分由调用方逐个尝试 |
| 单连接上限 | 本地判断 | — | `4003` | 上限 400，与套餐无关 |
| 单次订阅上限 | 本地判断 | — | `4001` | 上限 100 topic/次，整体拒绝 |

#### Lua check-and-incr 脚本逻辑

```
current = GET key or '0'
if current + delta > limit then
    return -1  (拒绝)
else
    return INCRBY key delta  (放行)
```

#### 连接释放

连接关闭时使用 DECRBY 脚本释放并发配额，保护不为负。

#### 降级策略

Redis 不可用时退化为本地 CAS 计数（AtomicLong + ConcurrentHashMap），恢复后清空本地计数以 Redis 为准。

### 7.4 连接风暴保护

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `accept-burst` | 1000 | 令牌桶突发容量 |
| `accept-rate-per-second` | 500 | 令牌桶填充速率（个/秒） |
| `max-unauthenticated-connections` | 10000 | 未鉴权连接总数上限 |

- 超限直接关闭连接（不发任何报文），最小化被攻击时的资源消耗。
- 由 `ConnectionGuardHandler` 实现，位于 Pipeline 最前端。

---

## 8. 错误码字典

### 8.1 鉴权类 (1xxx)

| code | HTTP 状态码 | 语义说明 | 触发场景 |
|------|------------|----------|----------|
| 1001 | 401 | Token 无效 | ak 不存在，或 Redis 降级期间未缓存的新 ak |
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
| 4001 | 400 | 订阅 Topic 格式错误 | TopicParser 校验失败 / 单次订阅超 100 |
| 4002 | 403 | 无权限订阅该 Topic | 无权限，或 FREE 请求了未开放标的 |
| 4003 | 400 | 单连接订阅数超 400 | 超出 `max-topics-per-connection` |
| 4004 | 404 | Topic 对应标的不存在或当前不可订阅 | quote-gateway 按标的订阅失败（含 unsupported_symbol、适配器未就绪、上游 RPC 失败等）；写入 `rejected` 为 `topic:4004` |
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
| 8001 | 404 | 标的不存在 | 仅「标的不存在」场景。行情接口暂无数据返回 HTTP 200 空数据，不再报 8001，详见 [5.5 节](#55-rest-快照查询上游默认开启) |
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

### 8.7 异常处理映射

| 异常类型 | HTTP 状态码 | 错误码 | 说明 |
|----------|------------|--------|------|
| `GatewayException` | 按 ErrorCode 定义 | 按 ErrorCode | 业务异常 |
| `MissingServletRequestParameterException` | 400 | 400 | 参数缺失 |
| `MethodArgumentTypeMismatchException` | 400 | 400 | 参数类型不匹配 |
| `IllegalArgumentException` | 400 | 400 | 参数校验失败 |

---

## 10. 附录：上游行情采集网关 (quote-gateway)

> 本章描述内部采集链路，**不是**对客 REST / WebSocket API。对客契约以第 1～8 章及官网控制台文档为准。

### 10.1 quote-gateway 概述

**deeptick-quote-gateway** 是 DeepTick 行情数据平台的**行情采集网关**，负责从外部数据源（AllTick、iTick）获取实时行情数据，经归一化处理后通过 Redis Stream 发布给下游的 push-gateway 消费。它是整个行情数据链路的上游环节。

| 维度 | 说明 |
|------|------|
| 项目定位 | 行情数据采集网关 |
| 技术栈 | Java 17 / Spring Boot 3.2.12 / Netty 4.1.109 / LMAX Disruptor 3.4.4 / Caffeine 3.1.8 / Redis Stream |
| 服务端口 | 8082 |
| 核心职责 | 从外部数据源获取行情 → 适配器归一化 → 行情流水线处理 → 发布到 Redis Stream |
| 序列化 | Jackson 2.16.2（BOM 对齐 push-gateway） |
| 构建产物 | `deeptick-quote-gateway.jar`（`mvn clean package`） |

### 10.2 数据源适配器

#### 10.2.1 适配器框架设计

适配器框架采用**三层继承**结构：

```
QuoteSourceAdapter (接口)
  └── AbstractQuoteSourceAdapter (抽象基类：生命周期/状态机/指数退避重连/监听器)
        └── AbstractWebSocketAdapter (WebSocket公共基类：多连接分片/心跳/配额熔断)
              ├── AllTickAdapter
              └── ITickAdapter
```

**QuoteSourceAdapter 接口核心方法**：

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `getAdapterId()` | String | 适配器唯一标识 |
| `getAdapterName()` | String | 显示名称 |
| `getSupportedAssetTypes()` | Set<AssetType> | 支持的资产类型集合 |
| `getStatus()` | AdapterStatus | 当前状态 |
| `start(AdapterConfig)` | void | 启动适配器 |
| `stop(boolean graceful)` | void | 停止适配器 |
| `subscribe(Set<String> symbols)` | void | 订阅标的 |
| `unsubscribe(Set<String> symbols)` | void | 取消订阅 |
| `getSubscribedSymbols()` | Set<String> | 已订阅标的（只读） |
| `getHealthInfo()` | AdapterHealthInfo | 健康快照 |
| `getRemainingCapacity()` | int | 剩余可订阅容量 |

**多连接分片架构**（AbstractWebSocketAdapter）：
- 每个资产类型维护 `List<AssetConnection>` 多条连接
- 单连接订阅数达 `max-symbols-per-connection`（默认 500）时自动开新连接
- 每资产类型最多 `max-connections-per-asset`（默认 4）条连接
- 按需连接模式：有订阅时才建立连接

**配额熔断机制**：
- 连续握手失败达阈值（默认 5 次）后进入冷却期（默认 15 分钟）
- 握手失败（HTTP 429）：重置通道状态 + 指数退避重连（10s 起步，5min 封顶）
- 全量重发节流：30 秒内不重复全量重发

#### 10.2.2 AllTick 适配器

| 配置项 | 值 |
|--------|-----|
| 适配器 ID | `alltick` |
| 支持资产类型 | STOCK, FOREX, CRYPTO |
| 默认 base host | `quote.alltick.co` |
| 默认心跳间隔 | 10 秒 |
| 认证方式 | Token 在 URL 查询参数中（`?token=xxx`） |
| 订阅语义 | 全量替换（每次下发该连接的全量订阅集合） |

**WebSocket 端点映射**：

| 资产类型 | 端点路径 |
|----------|----------|
| STOCK | `/quote-stock-b-ws-api` |
| FOREX | `/quote-b-ws-api` |
| CRYPTO | `/quote-b-ws-api` |

WS URL 构建：`wss://{baseHost}{path}?token={token}`

**AllTick 协议命令码**：

| 命令码 | 含义 |
|--------|------|
| 22000 | 心跳请求 |
| 22001 | 心跳响应 |
| 22004 | 订阅请求 |
| 22005 | 订阅响应 |
| 22006 | 取消订阅 |
| 22998 | 行情推送 |

**订阅消息格式**：

```json
{
  "cmd_id": 22004,
  "seq_id": 1,
  "trace": "trace-xxxx-1234567890",
  "data": {
    "symbol_list": [{"code": "USDJPY"}, {"code": "BTCUSDT"}]
  }
}
```

**心跳消息格式**：

```json
{"cmd_id": 22000, "seq_id": 2, "trace": "hb-1234567890", "data": {}}
```

**成交量差分契约**（`AllTickVolumeTracker`）：
- AllTick 推送的 volume 是**当日累计量**，不是单笔量
- 差分规则：`delta = 当前累计值 - 上一笔累计值`
- 首笔/跨日：`delta = 当前累计值`（从 0 起算）
- 负差分归 0（上游脏数据）
- 与回填侧 deeptick-admin `KlineCaliberMapper` 保持一致

#### 10.2.3 iTick 适配器

| 配置项 | 值 |
|--------|-----|
| 适配器 ID | `itick` |
| 支持资产类型 | FOREX, CRYPTO, STOCK, INDEX, FUTURES |
| 默认 base host | `api-free.itick.org` |
| 默认心跳间隔 | 30 秒 |
| 认证方式 | Token 在 HTTP Header 中（`token: xxx`） |
| 订阅语义 | 增量（subscribe/unsubscribe 分别发送） |

**WebSocket 端点映射**：

| 资产类型 | 端点路径 | 默认地区码 |
|----------|----------|------------|
| FOREX | `/forex` | GB |
| STOCK | `/stock` | US |
| CRYPTO | `/crypto` | BA |
| INDEX | `/indices` | GB |
| FUTURES | `/future` | US |

WS URL 构建：`wss://{baseHost}{path}`（token 通过 HTTP Header 传递）

**订阅消息格式**：

```json
{"ac": "subscribe", "params": "EURUSD$GB,USDJPY$GB", "types": "quote"}
```

- `params` 格式：`{symbol}${region}` 逗号分隔
- `types` 固定为 `"quote"`

**取消订阅消息格式**：

```json
{"ac": "unsubscribe", "params": "EURUSD$GB", "types": "quote"}
```

**心跳消息格式**：

```json
{"ac": "ping", "params": "1234567890"}
```

**行情推送解析**（通过 `data.type` 字段区分）：

| type | QuoteType | 提取的字段 |
|------|----------|------------|
| `quote` | QUOTE | ld(lastPrice), p(prevClose), o(open), h(high), l(low), ch(change), chp(changePercent), v(volume), tu(turnover), t(timestamp), r(region) |
| `tick` | TICK | ld(lastPrice), v(volume), t(timestamp) |
| `depth` | DEPTH | asks[0].p/v + bids[0].p/v（最优买卖盘） |

#### 10.2.4 适配器配置示例

```yaml
gateway:
  adapters:
    alltick:
      enabled: true
      ws-url: wss://quote.alltick.co/quote-b-ws-api
      api-key: <REDACTED_UPSTREAM_API_KEY>
      extra-properties:
        endpoint.stock: /quote-stock-b-ws-api
        endpoint.forex: /quote-b-ws-api
        endpoint.crypto: /quote-b-ws-api
    itick:
      enabled: true
      ws-url: wss://api-free.itick.org
      api-key: <REDACTED_UPSTREAM_API_KEY>
      heartbeat-interval-ms: 30000
      extra-properties:
        base-host: api-free.itick.org
        endpoint.forex: /forex
        endpoint.stock: /stock
```

### 10.3 行情流水线

行情数据从适配器接收后，经过以下流水线阶段依次处理：

| 阶段 | 组件 | 职责 |
|------|------|------|
| 协议解析 | `ProtocolParser` | 解析上游协议格式，提取字段 |
| 数据清洗 | `QuoteCleaner` | 过滤无效数据、空值检查 |
| 归一化 | `QuoteNormalizer` | 统一字段命名、单位转换 |
| 标签/去重 | `QuoteTagger` | 打标签、基于 seq 去重（TTL 10s） |
| 派生计算 | `DerivedCalculator` | K线实时聚合、移动平均等派生指标 |
| 异常检测 | `AnomalyDetector` | 价格跳变、量为零等异常检测 |

**K线实时聚合**：支持 `1m, 5m, 15m, 30m, 60m, 4h, 1d` 周期的实时 K 线聚合，通过 `KlineAggregationService` 旁路驱动。

**对象池化优化**：`RawQuoteData` 和 `NormalizedQuote` 均使用 Netty Recycler 对象池，减少 GC 压力。池大小默认分别为 10000 和 50000。

### 10.4 数据发布机制 (Redis Stream)

行情数据经流水线处理后，通过 Disruptor 高性能队列异步写入 Redis Stream。

**Stream Key**：`market:stream`（可配置 `gateway.redis-stream.stream-key`）

**Entry 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `topic` | byte[] (UTF-8) | WS 逻辑 topic，如 `quote.FX.EURUSD`、`candle.HK.700.HK.60m` |
| `payload` | byte[] | JSON 序列化的 `UnifiedQuoteMessage`（quote 通路）或 K 线契约 JSON（candle 通路） |
| `ts` | byte[] (UTF-8 数字) | 毫秒时间戳 |
| `seq` | byte[] (UTF-8 数字) | 进程生命周期内单调递增序号（从 1 开始） |

**序列号管理**：
- `AtomicLong` 单调递增，从 1 开始
- **背压丢弃不消耗 seq**：只有在途检查通过后才 `incrementAndGet()`，保证已写入 entry 的 seq 严格连续
- 进程重启从 1 重新计数

**背压控制**：
- `maxInFlight`（默认 10000）：在途 XADD 上限
- 超限直接丢弃消息，计入 `gateway.redis.stream.xadd.drops`
- `whenComplete` 回调中无论成败释放在途额度

**MAXLEN 裁剪**：
- `XAddArgs.Builder.maxlen(10000).approximateTrimming()`：保留最近约 10000 条 entry
- 近似裁剪防止 stream 无限增长

### 10.5 Topic 解析规则

quote-gateway 使用 `WsTopicResolver` 将品种代码解析为 WS 逻辑 topic，与 push-gateway 消费侧的 `UnifiedTopicMapper` 规则**完全一致**。

**Quote Topic 格式**：`quote.{market}.{wsSymbol}`

**Candle Topic 格式**：`candle.{market}.{wsSymbol}.{itv}`

**资产类型 → market 码映射**：

| 资产类型 | market 码 |
|----------|-----------|
| CRYPTO | CR |
| FOREX | FX |
| FUTURES | FT |
| INDEX | IDX |
| METAL | MT |
| STOCK | 按 symbol 末段推导（见下表） |

**STOCK symbol 后缀映射规则**：

| 末段 | market 码 | 还原后缀 |
|------|-----------|----------|
| hk | HK | HK |
| us | US | US |
| cn | CN | CN |
| sh | CN | SH |
| sz | CN | SZ |
| 未知 | US（默认） | - |

**示例**：
- `EURUSD` + `FOREX` → `quote.FX.EURUSD`
- `700.HK` + `STOCK` → `quote.HK.700.HK`
- `BTCUSDT` + `CRYPTO` → `quote.CR.BTCUSDT`
- `EURUSD` + `FOREX` + `1m` → `candle.FX.EURUSD.1m`
- `700.HK` + `STOCK` + `60m` → `candle.HK.700.HK.60m`

**Candle 周期合法集**：`1m, 5m, 15m, 30m, 60m, 4h, 1d, 1w, 1M`（小时线用 `60m` 而非 `1h`）

### 10.6 订阅管理 REST API

quote-gateway 仍保留订阅管理 REST API（运维/调试）。push-gateway 收到 WebSocket 客户端订阅后，经 **gRPC**（Nacos 发现实例，端口见元数据 `gRPC_port`）调用 quote-gateway，不再走 HTTP。

**基础路径**：`/api/v1/subscriptions`

**接口列表**：

| 序号 | HTTP 方法 | 路径 | 功能说明 |
|------|-----------|------|----------|
| 1 | POST | `/api/v1/subscriptions/subscribe` | 订阅品种（支持单个和批量） |
| 2 | DELETE | `/api/v1/subscriptions/unsubscribe/{symbol}` | 取消订阅 |
| 3 | GET | `/api/v1/subscriptions/list` | 查询所有订阅 |
| 4 | GET | `/api/v1/subscriptions/config` | 查询路由配置 |

#### POST /api/v1/subscriptions/subscribe

**请求 DTO** (`SubscribeRequest`)：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `symbol` | String | 否* | 单个品种代码（*单个订阅形式时必填） |
| `symbols` | List<String> | 否 | 批量品种代码列表（批量形式时使用） |
| `assetType` | String | 否 | 资产类型（FOREX/CRYPTO/STOCK 等，留空自动推断） |
| `assetTypes` | Map<String, String> | 否 | 按 symbol 指定资产类型（批量时覆盖全局 assetType） |
| `adapter` | String | 否 | 适配器名称（alltick/itick，留空按优先级自动选择） |

**单个订阅请求示例**：

```json
{"symbol": "USDJPY", "assetType": "FOREX"}
```

**批量订阅请求示例**：

```json
{
  "symbols": ["AAPL", "BTCUSDT"],
  "assetTypes": {"AAPL": "STOCK"},
  "assetType": "CRYPTO"
}
```

**单订阅响应** (`SubscriptionResponse`)：

| 字段 | 类型 | 说明 |
|------|------|------|
| `success` | boolean | 操作是否成功 |
| `symbol` | String | 品种代码 |
| `adapter` | String | 处理该请求的适配器名称 |
| `message` | String | 结果描述（`newly_subscribed` / `already_subscribed` / `unsubscribed` / 错误信息，如 `invalid_symbol_format` / `unsupported_symbol` / `adapter_symbol_mismatch`） |
| `topic` | String | 解析出的 WS 逻辑 topic（如 `quote.FX.USDJPY`），订阅成功时返回 |

**批量订阅响应** (`BatchSubscribeResponse`)：

| 字段 | 类型 | 说明 |
|------|------|------|
| `success` | boolean | 本批是否全部成功 |
| `total` | int | 本批 symbol 总数（去重后） |
| `succeeded` | int | 成功条数（含 already_subscribed） |
| `failed` | int | 失败条数 |
| `results` | List<SubscriptionResponse> | 逐 symbol 订阅结果 |

**HTTP 状态码语义**：

| 状态码 | 场景 |
|--------|------|
| 200 | 订阅成功（含已订阅的 `already_subscribed`） |
| 400 | 请求参数错误（symbol 为空 / 批量超限 / 未知适配器名 / 品种格式非法 `invalid_symbol_format` / 显式适配器不支持该品种 `adapter_symbol_mismatch`） |
| 404 | 取消订阅时品种未订阅 / 产品库未登记该品种（`unsupported_symbol`） |
| 429 | 适配器容量耗尽 |
| 500 | 订阅/取消订阅内部异常 |
| 503 | 无可用适配器 / 适配器未找到 |

**订阅校验错误消息**（两级校验：格式校验始终生效；产品库校验由 quote-gateway `gateway.catalog.enforced` 开关控制，详见《DeepTick产品清单.md》第 10 节）：

| 错误消息 | HTTP 状态码 | 触发条件 |
|---------|:---:|---------|
| `invalid_symbol_format: {symbol}` | 400 | 品种格式非法（非法字符 / 超过 32 字符 / 纯分隔符等） |
| `unsupported_symbol: {symbol}` | 404 | 产品库（enforced=true）未登记该品种（含资产类型不匹配） |
| `adapter_symbol_mismatch: {adapter} not support {symbol}` | 400 | 显式指定的适配器不在该品种的产品库候选通道集合内 |

批量订阅部分失败时逐 symbol 结果承载失败消息；整批全部失败时整批状态码取最高优先级错误码（格式/归属不匹配 400 优先）。

#### 订阅池去重机制

采用**双重持久化**：内存 ConcurrentHashMap + Redis。

- **Redis Key 格式**：`deeptick:subscription:{symbol}`
- **Redis Value**：`SubscriptionInfo` 的 JSON 序列化
- **Write-ahead 模式**：先写 Redis 持久化，成功后再写内存
- **对账补偿**：每 5 分钟执行 `reconcileSubscriptions()`，以 Redis 为准同步内存

**SubscriptionInfo 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `symbol` | String | 品种代码 |
| `adapterSource` | String | 适配器来源（`alltick` 或 `itick`） |
| `subscribedAt` | long | 订阅时间戳（毫秒） |
| `assetType` | String | 资产类型 |
| `topic` | String | WS 逻辑 topic |

#### 适配器选择策略

**SubscriptionRouter** 基于 `gateway.subscription.route.capacities` 配置：

| 适配器 | 优先级 | 最大订阅数 |
|--------|--------|------------|
| alltick | 1（高） | 500 |
| itick | 2（低） | 1000 |

选择逻辑：
1. 请求显式指定 `adapter` 时校验存在性
2. 否则按优先级遍历所有适配器
3. 跳过状态非 CONNECTED/RUNNING 的适配器
4. 跳过剩余容量为 0 的适配器
5. 全部不可用时返回 503

#### 启动恢复机制

`StartupSubscriptionRestorer` 在 `ApplicationReadyEvent` 时：
1. 从订阅池（已由 Redis 加载）读取所有历史订阅
2. 按适配器来源分组
3. 并行恢复 AllTick 和 iTick 订阅（`CompletableFuture`）
4. 预设 symbol→assetType 映射后调用 `adapter.subscribe()`

### 10.7 UnifiedQuoteMessage 数据模型

`UnifiedQuoteMessage` 是 quote-gateway 写入 Redis Stream 的统一行情消息格式，使用 Jackson 序列化。
**对外 JSON 采用 iTick 风格 1–2 字母短字段**（Java 属性仍为长名；反序列化兼容历史长字段）。

**完整字段表**（19 个字段）。报价帧不输出 `bp` / `bv` / `ap` / `av`。`ts` 是交易状态，外层帧的 `ts` 是发送时间，`data.t` 是行情时间。

| 序号 | JSON | Java 属性 | 类型 | 说明 |
|------|------|-----------|------|------|
| 1 | `s` | symbol | String | 标的代码（如 USDJPY、EURUSD、BTCUSDT） |
| 2 | `at` | assetType | String | 资产类型（FOREX/METAL/STOCK/CRYPTO/INDEX/FUTURES） |
| 3 | `qt` | quoteType | String | 行情粒度（QUOTE/TICK/DEPTH） |
| 4 | `ld` | lastPrice | BigDecimal | 最新价 |
| 5 | `p` | prevClose | BigDecimal | 昨收价 |
| 6 | `o` | open | BigDecimal | 开盘价 |
| 7 | `h` | high | BigDecimal | 最高价 |
| 8 | `l` | low | BigDecimal | 最低价 |
| 9 | `v` | volume | BigDecimal | 成交量 |
| 10 | `tu` | turnover | BigDecimal | 成交额 |
| 11 | `ch` | change | BigDecimal | 涨跌额 |
| 12 | `chp` | changePercent | BigDecimal | 涨跌幅百分比 |
| 13 | `ts` | tradingStatus | Integer | 交易状态。0 正常，1 停牌，2 退市，3 熔断。报价才有 |
| 14 | `bp` | bidPrice | BigDecimal | 买一价。只在盘口帧 |
| 15 | `bv` | bidVolume | BigDecimal | 买一量。只在盘口帧 |
| 16 | `ap` | askPrice | BigDecimal | 卖一价。只在盘口帧 |
| 17 | `av` | askVolume | BigDecimal | 卖一量。只在盘口帧 |
| 18 | `t` | timestamp | long | 数据源时间戳（UTC 毫秒） |
| 19 | `r` | region | String | 地区/交易所代码（如 GB） |

- `@JsonInclude(NON_NULL)`：null 字段不输出，减少序列化体积
- `@JsonProperty` + `@JsonAlias`：短字段输出，兼容长字段入站
- 不暴露上游来源标识（adapterId），消费端无需感知数据来源

**AllTick → UnifiedQuoteMessage 字段映射**：

| AllTick 字段 | UnifiedQuoteMessage | 说明 |
|--------------|---------------------|------|
| `data.code` | `s` | 直接映射 |
| `data.price` | `ld` | BigDecimal.valueOf 转换 |
| `data.volume`（累计量） | `v` | 经 `AllTickVolumeTracker.toDeltaVolume()` 差分 |
| `data.turnover` | `tu` | >0 时填充，否则 null |
| `data.tick_time` | `t` | 直接映射 |
| — | `qt` | 固定为 `"TICK"` |
| — | `r` | 固定为 `"GB"` |
| — | OHLC/ch/chp | 不填充（null，AllTick 未提供） |

**iTick → UnifiedQuoteMessage 字段映射**：

| iTick 字段 | UnifiedQuoteMessage | 说明 |
|------------|---------------------|------|
| `data.s` | `s` | 直接映射 |
| `data.ld` | `ld` | 直接映射 |
| `data.p` | `p` | !=0 时填充 |
| `data.o/h/l` | `o/h/l` | !=0 时填充 |
| `data.ch` | `ch` | !=0 时填充 |
| `data.chp` | `chp` | !=0 时填充 |
| `data.v` | `v` | !=0 时填充 |
| `data.tu` | `tu` | !=0 时填充 |
| `data.t` | `t` | 直接映射 |
| `data.r` | `r` | 缺省取 DEFAULT_REGION_MAP |
| `data.type` | `qt` | quote→QUOTE, tick→TICK, depth→DEPTH |
| depth: `asks[0].p/v` | `ap/av` | 最优卖盘 |
| depth: `bids[0].p/v` | `bp/bv` | 最优买盘 |

### 10.8 Candle Payload 格式

candle 通路写入 Redis Stream 的 payload 不是 `UnifiedQuoteMessage`，而是跨服务契约 JSON（短字段）：

```json
{
  "m": "HK",
  "s": "700.HK",
  "i": "60m",
  "t": 1785147395000,
  "o": 163.587,
  "h": 163.714,
  "l": 163.33,
  "c": 163.554,
  "v": 460604.2
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `m` | String | 市场码（如 HK/US/CR/FX） |
| `s` | String | 统一标的代码 |
| `i` | String | K 线周期 |
| `t` | long | K 线开盘时间（UTC 毫秒） |
| `o` | BigDecimal | 开盘价 |
| `h` | BigDecimal | 最高价 |
| `l` | BigDecimal | 最低价 |
| `c` | BigDecimal | 收盘价 |
| `v` | BigDecimal | 成交量（保留聚合小数值） |

**周期合法集**：`1m, 5m, 15m, 30m, 60m, 4h, 1d, 1w, 1M`

> **注意**：周期 `1h` 会被防御性归一为 `60m`，确保与 push-gateway 消费侧一致。

### 10.9 quote-gateway 与 push-gateway 交互协议

quote-gateway 与 push-gateway 之间存在三种交互方式：

| 交互方式 | 方向 | 说明 |
|----------|------|------|
| REST 调用 | push-gateway → quote-gateway | push-gateway 调用 quote-gateway 的订阅管理 REST API |
| Redis Stream | quote-gateway → push-gateway | quote-gateway 写入行情消息，push-gateway XREAD 消费 |
| Redis 注册表 | 双向共享 | 订阅注册表（`deeptick:subscription:{symbol}`），两端共享同一 Redis 实例 |

#### gRPC 调用

push-gateway 收到 WebSocket 客户端订阅请求后，经 gRPC（Nacos 发现 quote-gateway 实例）调用：

- `QuoteSubscription.Subscribe` / `Unsubscribe`
- `QuoteSnapshot.QueryQuote` / `QueryDepth` / `QueryTicks` / `QuerySymbols`

quote-gateway 提供端默认端口 **9090**（`grpc.server.port`，写入 Nacos `gRPC_port`）。REST `/api/v1/subscriptions` 仍可用于运维调试。

#### Redis Stream 消息格式

**共享 Stream Key**：`market:stream`

**消费方式**：push-gateway 使用 `XREAD` 消费该 Stream（BLOCK 批量消费，每实例独立全量）

**Entry 示例**（quote-gateway 生产侧）：

```
XADD market:stream MAXLEN ~ 10000 *
  topic "quote.FX.EURUSD"
  payload '{"symbol":"EURUSD","assetType":"FOREX","quoteType":"TICK","lastPrice":163.554,"volume":460604.2,"timestamp":1785147395081,"region":"GB"}'
  ts "1785147395100"
  seq "1"
```

#### Redis 注册表

**Key 格式**：`deeptick:subscription:{symbol}`

**Value**：SubscriptionInfo JSON

```json
{
  "symbol": "EURUSD",
  "adapterSource": "alltick",
  "subscribedAt": 1785147395000,
  "assetType": "FOREX",
  "topic": "quote.FX.EURUSD"
}
```

**共享场景**：
- quote-gateway 的 `SubscriptionPool` 写入和读取
- push-gateway 可读取该注册表了解当前订阅状态，避免重复订阅

### 10.10 quote-gateway 配置参数

#### 核心配置项（gateway.* 前缀）

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.pool.raw-quote-pool-size` | 10000 | RawQuoteData 池大小 |
| `gateway.pool.normalized-quote-pool-size` | 50000 | NormalizedQuote 池大小 |
| `gateway.pool.warmup-ratio` | 0.5 | 启动预热比例 |
| `gateway.disruptor.ring-buffer-size` | 1048576 | RingBuffer 槽位数（2 的幂） |
| `gateway.disruptor.consumer-count` | 4 | 并行消费者数量 |
| `gateway.disruptor.wait-strategy` | SLEEPING | 等待策略（BUSYSPIN/YIELDING/SLEEPING/ADAPTIVE/BLOCKING） |
| `gateway.pipeline.dedup-cache-ttl-ms` | 10000 | 去重缓存 TTL |
| `gateway.pipeline.dedup-cache-max-size` | 100000 | 去重缓存最大条目数 |
| `gateway.pipeline.kline-enabled` | true | 是否启用 K 线聚合 |
| `gateway.pipeline.kline-periods` | 1m,5m,15m,30m,60m,4h,1d | 实时 K 线聚合周期 |
| `gateway.pipeline.anomaly-detection-enabled` | true | 异常检测开关 |
| `gateway.pipeline.quality-scoring-enabled` | true | 质量评分开关 |
| `gateway.heartbeat.interval-ms` | 10000 | 心跳检查间隔 |
| `gateway.heartbeat.degraded-threshold-ms` | 30000 | 降级阈值 |
| `gateway.heartbeat.failover-threshold-ms` | 60000 | 故障转移阈值 |
| `gateway.reconnect.max-attempts` | 10 | 最大重连次数 |
| `gateway.reconnect.base-delay-ms` | 1000 | 重连基础延迟 |
| `gateway.reconnect.multiplier` | 2.0 | 重连延迟倍率 |
| `gateway.reconnect.max-delay-ms` | 60000 | 重连最大延迟 |
| `gateway.reconnect.handshake-base-delay-ms` | 10000 | 通道级重连基础延迟 |
| `gateway.reconnect.handshake-max-delay-ms` | 300000 | 通道级重连最大延迟 |
| `gateway.data-silence.enabled` | true | 断流监控开关 |
| `gateway.data-silence.check-interval-ms` | 60000 | 检查间隔 |
| `gateway.data-silence.threshold-ms` | 300000 | 断流告警阈值 |
| `gateway.redis-stream.stream-key` | market:stream | Redis Stream key |
| `gateway.redis-stream.max-in-flight` | 10000 | 在途 XADD 背压上限 |
| `gateway.subscription.max-batch-size` | 50 | 单次批量订阅上限 |
| `gateway.subscription.route.capacities` | alltick:500/itick:1000 | 路由容量配置 |
| `gateway.adapters.{id}.enabled` | false | 适配器开关 |
| `gateway.adapters.{id}.ws-url` | - | WebSocket 地址 |
| `gateway.adapters.{id}.api-key` | - | API Key |
| `gateway.adapters.{id}.extra-properties` | - | 扩展属性 |
| `gateway.netty.boss-threads` | 1 | Boss 线程数 |
| `gateway.netty.worker-threads` | 0 (auto) | Worker 线程数 |
| `gateway.netty.connect-timeout-ms` | 10000 | 连接超时 |
| `gateway.snapshot-query.enabled` | false | 快照查询总开关（供 push-gateway 缓存未命中回退调用，见 5.5 节） |
| `gateway.snapshot-query.alltick-base-host` | - | AllTick 行情 REST 基准地址 |
| `gateway.snapshot-query.alltick-stock-base-host` | - | AllTick 股票 REST 基准地址 |
| `gateway.snapshot-query.itick-base-host` | - | iTick REST 基准地址 |
| `gateway.snapshot-query.alltick-qps` | 0.15 | AllTick 源令牌桶限速（QPS） |
| `gateway.snapshot-query.itick-qps` | 0.08 | iTick 源令牌桶限速（QPS） |
| `gateway.snapshot-query.connect-timeout-ms` | 3000 | 上游连接超时 |
| `gateway.snapshot-query.read-timeout-ms` | 5000 | 上游读取超时 |
| `gateway.snapshot-query.positive-cache-ttl-ms` | 2000 | 正缓存 TTL |
| `gateway.snapshot-query.negative-cache-ttl-ms` | 30000 | 负缓存 TTL |

> **上线 checklist 注意**：快照回退需 push-gateway `gateway.rest.upstream-snapshot.enabled` 与 quote-gateway `gateway.snapshot-query.enabled` **同时开启**才生效；仅开一侧时 push-gateway 静默回落返回 HTTP 200 空数据。

#### Redis 配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `spring.data.redis.host` | 47.243.250.18 (Producer) / localhost (Template) | Redis 主机 |
| `spring.data.redis.port` | 6379 | Redis 端口 |
| `spring.data.redis.password` | - | Redis 密码 |

> **注意**：quote-gateway 存在两条 Redis 通路：`QuoteRedisStreamProducer`（自建 Lettuce 连接）和 `SubscriptionPool`（走 Spring Boot Redis 自动配置），启动时会做一致性校验。

#### Nacos 配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `spring.cloud.nacos.server-addr` | 47.243.250.18:8848 | Nacos 地址 |
| `spring.cloud.nacos.namespace` | e3f4843c-ba63-4397-b583-60acdac40649 | 命名空间 |
| `spring.cloud.nacos.config.prefix` | deeptick-quote-gateway | 配置文件前缀 |
| `spring.cloud.nacos.config.group` | DEFAULT_GROUP | 配置分组 |

**Nacos 热更新 dataId**：
- `adapter-routes`：适配器路由表变更（group: QUOTE_GATEWAY）
- `adapter-config`：适配器配置变更

#### 监控配置

| 路径 | 说明 |
|------|------|
| `/actuator/health` | 健康检查（show-details: always） |
| `/actuator/info` | 应用信息 |
| `/actuator/prometheus` | Prometheus 指标导出 |
| `/actuator/metrics` | Micrometer 指标 |

**Micrometer 关键指标**：

| 指标名 | 类型 | 说明 |
|--------|------|------|
| `gateway.redis.stream.xadd.success` | Counter | XADD 成功次数 |
| `gateway.redis.stream.xadd.failures` | Counter | XADD 失败次数 |
| `gateway.redis.stream.xadd.drops` | Counter | 背压丢弃次数 |
| `gateway.disruptor.ringbuffer.remaining` | Gauge | RingBuffer 剩余槽位 |
| `gateway.disruptor.ringbuffer.usage.percent` | Gauge | RingBuffer 使用率 |
| `gateway.disruptor.drops.total` | Gauge | RingBuffer 满丢弃累计 |

---

## 9. 附录

### 9.1 数据模型字段速查表

#### 报价 (Quote) 字段表

对客 REST / WebSocket `quote` 使用短字段（与官网一致）：

| 字段 | 类型 | 说明 |
|------|------|------|
| `s` | String | 标的代码 |
| `at` | String | 资产类型（若上游提供） |
| `ld` | String/Number | 最新价 |
| `p` | String/Number | 前收 |
| `o` / `h` / `l` | String/Number | 开 / 高 / 低 |
| `v` | String/Number | 成交量 |
| `tu` | String/Number | 成交额。上游没有则不出现 |
| `ch` / `chp` | String/Number | 涨跌额 / 涨跌幅。上游没有则不出现，0 保留 |
| `ts` | int | 交易状态。0 正常，1 停牌，2 退市，3 熔断。不是时间戳 |
| `t` | long | 行情时间戳（Unix 毫秒） |

#### 盘口 (Depth) 字段表

| 字段 | 类型 | 说明 |
|------|------|------|
| `s` | String | 标的代码 |
| `b` | `{p,v}[]` | 买盘，价格从高到低 |
| `a` | `{p,v}[]` | 卖盘，价格从低到高 |
| `t` | long | 时间戳（毫秒） |

#### 成交 (Tick/Trade) 字段表

| 字段 | 类型 | 说明 |
|------|------|------|
| `ld` | String/Number | 成交价 |
| `v` | String/Number | 成交量 |
| `t` | long | 成交时间戳（Unix 毫秒） |

#### K 线 (Candle/OHLCV) 字段表

REST `GET /api/v1/candles` 的 `d[]` 含成交额。WebSocket 实时 K 线不带 `tu`、`tc`。

| 字段 | 类型 | 说明 |
|------|------|------|
| `t` | long | K 线开盘时间（Unix 毫秒） |
| `o` | Number | 开盘价 |
| `h` | Number | 最高价 |
| `l` | Number | 最低价 |
| `c` | Number | 收盘价 |
| `v` | Number | 成交量 |
| `tu` | Number | 成交额。只在 REST K 线查询中出现 |
| `m` / `s` / `i` | String | 市场码 / 标的 / 周期。出现在 WebSocket candle 帧 |

#### 标的信息 (SymbolInfo) 字段表

| 字段 | 类型 | 说明 |
|------|------|------|
| `symbol` | String | 标的代码 |
| `name` | String | 标的名称 |
| `market` | String | 市场码 |
| `base_currency` | String | 基础币种 |
| `quote_currency` | String | 计价币种 |
| `status` | String | 状态（`ACTIVE` / `SUSPENDED`） |
| `capabilities` | Array<String> | 支持的数据类型 |
| `price_precision` | int | 价格精度（小数位） |
| `size_precision` | int | 数量精度（小数位） |

#### 统一行情消息 (UnifiedQuoteMessage) 字段表

> quote-gateway 写入 Redis Stream 的内部格式，详见 [10.7 UnifiedQuoteMessage 数据模型](#107-unifiedquotemessage-数据模型)。

| 字段 | 类型 | 说明 |
|------|------|------|
| `symbol` | String | 标的代码 |
| `assetType` | String | 资产类型（FOREX/METAL/STOCK/CRYPTO/INDEX/FUTURES） |
| `quoteType` | String | 行情粒度（QUOTE/TICK/DEPTH） |
| `lastPrice` | BigDecimal | 最新价 |
| `prevClose` | BigDecimal | 昨收价 |
| `open` | BigDecimal | 开盘价 |
| `high` | BigDecimal | 最高价 |
| `low` | BigDecimal | 最低价 |
| `volume` | BigDecimal | 成交量 |
| `turnover` | BigDecimal | 成交额 |
| `change` | BigDecimal | 涨跌额 |
| `changePercent` | BigDecimal | 涨跌幅百分比 |
| `bidPrice` | BigDecimal | 买一价（可选） |
| `bidVolume` | BigDecimal | 买一量（可选） |
| `askPrice` | BigDecimal | 卖一价（可选） |
| `askVolume` | BigDecimal | 卖一量（可选） |
| `timestamp` | long | 数据源时间戳（UTC 毫秒） |
| `region` | String | 地区/交易所代码 |

### 9.2 K 线周期对照表

WebSocket Topic `candle.{market}.{symbol}.{interval}` 的周期取值：

| interval | 说明 |
|----------|------|
| `1m` | 1 分钟 |
| `5m` | 5 分钟 |
| `15m` | 15 分钟 |
| `30m` | 30 分钟 |
| `60m` | 1 小时 |
| `4h` | 4 小时 |
| `1d` | 日 K |
| `1w` | 周 K |
| `1M` | 月 K |

### 9.3 与上游数据源对照

#### DeepTick vs iTick 接口对照表

| 维度 | iTick | DeepTick |
|------|-------|--------|
| REST 鉴权 | Token 直接放 Header（`token: Your Token`） | 请求头 `deeptoken` 带 API Key 整串 |
| WS 鉴权 | 连接 Header 携带 `token` | 握手头 `deeptoken`；浏览器用查询参数 |
| REST 基准 URL | `https://api.itick.org` | `https://api.deeptick.cc/api/v1` |
| WS 连接 URL | `wss://api.itick.org/{crypto|forex}` | `wss://api.deeptick.cc/ws/v1` |
| REST 路由 | 按产品类别路由（`/crypto/kline`、`/stock/quote`） | 统一前缀 + 功能路由（`/api/v1/quote`、`/api/v1/orderbook`、`/api/v1/trades`） |
| WS 协议风格 | `ac`/`params`/`types`（操作+参数+类型） | `type`/`topics`（类型+Topic 列表） |
| 订阅标识 | `symbol$region`（如 `BTCUSDT$BA`） | 标准化 Topic：`{type}.{market}.{symbol}` |
| K 线推送 | 支持（`kline@1`，仅高级以上套餐） | 支持（`candle.{market}.{symbol}.{interval}`） |
| K 线续传 | 不支持 | 支持 `resume` 断线续传（60s 窗口） |
| 心跳方式 | JSON `{"ac":"ping","params":"timestamp"}` | JSON `{"type":"ping","ts":...}` |
| 心跳间隔 | 建议 30s，超时 60s | 建议 30s，超时 60s（读空闲） |
| 取消订阅 | 未文档化 | `unsubscribe` / `unsubscribe_all` |
| 最大订阅 | 500 个/连接 | 单连接 400；账号总量按套餐 10~3000 |
| K 线周期 | 8 种（1m/5m/15m/30m/1h/1d/1w/1M） | 9 种（增加 4h） |
| 成交方向 | `d`: 1=卖, 2=买 | `side`: `buy` / `sell` |
| 交易时段 | `te`: 0=常规, 1=盘前, 2=盘后 | 不提供 |
| 交易状态 | `ts`: 0=正常, 1=停牌, 2=退市, 3=熔断 | 不提供 |
| 涨跌幅 | `ch`（涨跌额）, `chp`（涨跌幅%） | 不提供，需客户自行计算 |
| 错误码数量 | 3 个（E001-E003） | 30+ 个（1xxx~8xxx） |
| 限流策略 | 未公开具体数值 | 按套餐精细划分（10~1200 次/分钟） |

#### DeepTick vs AllTick 接口对照表

| 维度 | AllTick | DeepTick |
|------|---------|--------|
| REST 鉴权 | Token 作为 URL Query 参数（`?token=`） | 请求头 `deeptoken` 带 API Key 整串 |
| WS 鉴权 | URL Query 参数（`?token=`） | 握手头 `deeptoken`；浏览器用查询参数 |
| REST 基准 URL | `https://quote.alltick.co` | `https://api.deeptick.cc/api/v1` |
| WS 连接 URL | `wss://quote.alltick.co/{quote-stock-b-ws-api|quote-b-ws-api}` | `wss://api.deeptick.cc/ws/v1` |
| REST 路由 | 按数据类型路由 + `query` 参数（`/quote-stock-b-api/kline?query=`） | 统一前缀 + 功能路由 |
| WS 协议风格 | `cmd_id`/`seq_id`/`trace`（协议号驱动） | `type`/`topics`（类型+Topic 列表） |
| K 线推送 | **不支持**（WS 无 K 线） | **支持** candle 实时推送 + resume 续传 |
| 订阅方式 | 覆盖式（每次订阅覆盖上次列表） | 增量式（subscribe/unsubscribe 累加/移除） |
| 心跳方式 | JSON `{"cmd_id":22000,...}` | JSON `{"type":"ping","ts":...}` |
| 心跳间隔 | 建议 10s，超时 30s | 建议 30s，超时 60s |
| 最大订阅 | 按套餐 5~3000 个/连接 | 单连接 400；账号总量按套餐 10~3000 |
| K 线周期 | 10 种（含 2h/4h，股票不支持 2h/4h） | 9 种（含 4h） |
| 盘口档位 | 按产品 1~10 档 | 上游给几档就返回几档，请求不带档位 |
| 成交方向 | `trade_direction`: 0=中性, 1=买, 2=卖 | `side`: `buy` / `sell` |
| 响应字段命名 | 长字段名（`open_price`, `close_price`） | 短字段名（`open`, `high`, `low`, `close`） |
| 响应数据类型 | string | number / string（透传上游） |
| 批量 K 线 | POST `/batch-kline`（批量多产品） | 不支持批量，单产品查询 |
| 基础信息 | `GET /static_info`（股票名称/交易所等） | `GET /api/v1/symbols`（标的列表+元信息） |
| 停复牌 | 支持（SSE/NYSE/NASDAQ） | 不支持 |
| 错误码数量 | 11 个（200/400/401/429/600-606） | 30+ 个（1xxx~8xxx） |
| 限流策略 | 按计划精细划分（免费~专业） | 按套餐精细划分（FREE~ENTERPRISE） |
| WS 连接数限制 | 免费 1 ~ 专业 10 | FREE 1 ~ ENTERPRISE 12 |

#### DeepTick 融合两者特点说明

DeepTick 行情网关在设计上融合了 iTick 和 AllTick 的优点，同时引入了更高的安全性和可靠性：

1. **统一钥匙**：REST 和 WebSocket 共用同一把 API Key，放在 `deeptoken`。服务端客户端放握手头，浏览器放查询参数。
2. **统一接口设计**：iTick 按产品类别分 6 类路由，AllTick 按数据类型分 2 类路由，DeepTick 统一为 `/api/v1/{功能}` 路由，简化接入。
3. **K 线实时推送 + 续传**：AllTick 的 WS 不支持 K 线推送，DeepTick 支持 candle 实时推送并提供 60 秒 resume 断线续传。
4. **增量订阅**：AllTick 采用覆盖式订阅，DeepTick 采用增量式 subscribe/unsubscribe，更灵活。
5. **完善的错误码体系**：iTick 仅 3 个错误码，AllTick 11 个，DeepTick 提供 30+ 个精细化错误码。
6. **连接风暴保护**：引入令牌桶（突发 1000 / 速率 500/s）和未鉴权连接上限（10000），防止恶意连接。
7. **慢消费者保护**：背压水位 + 不可写超时断开，防止慢消费者拖垮服务端。
8. **微批写入优化**：flush 间隔 2ms / 批量 64 帧，平衡延迟与吞吐。

#### quote-gateway 适配器层与多数据源支持

DeepTick 平台通过 quote-gateway 的**适配器层**同时支持 AllTick 和 iTick 两个上游数据源，实现多数据源的自动路由与故障转移：

1. **适配器自动路由**：`SubscriptionRouter` 按优先级（alltick 优先级 1 > itick 优先级 2）自动选择状态为 CONNECTED/RUNNING 且容量充足的适配器进行订阅，客户端无需感知数据源切换。
2. **故障转移**：当主适配器（alltick）连接异常或配额耗尽时，自动降级到备选适配器（itick），保证行情数据连续性。心跳降级阈值 30s，故障转移阈值 60s。
3. **配额熔断**：连续握手失败达阈值后进入冷却期，防止上游并发连接配额受限时的重连风暴。
4. **统一归一化**：无论数据来自 AllTick 还是 iTick，均归一化为 `UnifiedQuoteMessage` 格式写入 Redis Stream，下游 push-gateway 无需感知数据来源。
5. **多连接分片**：每个适配器按资产类型维护多组 WebSocket 连接，突破上游单连接 symbol 配额限制。

> 详细的适配器框架说明见 [第 10 章内部附录](#10-附录上游行情采集网关-quote-gateway)。

### 9.4 配置参数参考表

#### WebSocket 参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.ws.port` | 8081 | WS 端口 |
| `gateway.ws.path` | `/ws/v1` | WS 路径 |
| `gateway.ws.so-backlog` | 1024 | accept 队列 |
| `gateway.ws.max-frame-size` | 65536 | 最大帧字节 |
| `gateway.ws.write-buffer-low-water-mark` | 32768 | 写缓冲低水位 |
| `gateway.ws.write-buffer-high-water-mark` | 65536 | 写缓冲高水位 |
| `gateway.ws.max-unauthenticated-connections` | 10000 | 未鉴权连接上限 |
| `gateway.ws.accept-rate-per-second` | 500 | accept 速率 |
| `gateway.ws.accept-burst` | 1000 | 突发连接数 |
| `gateway.ws.auth-timeout-millis` | 10000 | 鉴权超时 |
| `gateway.ws.read-idle-millis` | 60000 | 读空闲超时 |
| `gateway.ws.write-idle-millis` | 50000 | 写空闲超时 |
| `gateway.ws.heartbeat-hint-seconds` | 30 | 心跳提示 |
| `gateway.ws.max-topics-per-request` | 100 | 单次订阅上限 |
| `gateway.ws.max-topics-per-connection` | 400 | 单连接订阅上限 |
| `gateway.ws.flush-interval-millis` | 2 | 微批 flush 间隔 |
| `gateway.ws.flush-batch-size` | 64 | 微批 flush 阈值 |
| `gateway.ws.pending-queue-capacity` | 1024 | 不可写期间缓冲容量 |
| `gateway.ws.unwritable-timeout-millis` | 10000 | 慢消费者断开超时 |
| `gateway.ws.throttle-window-millis` | `{}` | per-type 节流窗口（默认关闭） |
| `gateway.ws.resume-ttl-millis` | 60000 | resume 摘要 TTL |
| `gateway.ws.resume-window-millis` | 60000 | candle 补发窗口 |
| `gateway.ws.candle-ring-capacity` | 256 | 环形缓冲容量 |
| `gateway.ws.shutdown-flush-timeout-ms` | 5000 | 优雅停机超时 |

#### REST 参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `server.port` | 8084 | REST/Actuator 端口 |
| `gateway.rest.trades-buffer-capacity` | 512 | trades 环形缓冲容量 |
| `gateway.rest.rate-limit-degrade-factor` | 1.0 | 限流降级折减系数 |
| `gateway.rest.upstream-snapshot.enabled` | true | REST 查 quote-gateway 上游快照总开关（见 5.5 节） |
| `gateway.rest.upstream-snapshot.quote-gateway-url` | （已废弃） | 快照回退改为 gRPC |
| `gateway.rest.upstream-snapshot.connect-timeout-millis` | 2000 | 回退请求连接超时 |
| `gateway.rest.upstream-snapshot.request-timeout-millis` | 1500 | 回退请求响应超时 |
| `gateway.rest.upstream-snapshot.max-concurrent` | 32 | 回退在途并发上限 |
| `gateway.rest.upstream-snapshot.write-back-enabled` | true | 命中结果是否回填快照缓存 |
| `gateway.rest.upstream-snapshot.worker-threads` | 8 | 回退工作线程数 |
| `gateway.rest.upstream-snapshot.executor-queue-capacity` | 256 | 回退执行器队列容量 |

#### 统计落库参数（MySQL）

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.stats.enabled` | true | 统计写入开关 |
| `gateway.stats.mysql.url` | `jdbc:mysql://…/deeptick_admin` | JDBC URL |
| `gateway.stats.mysql.username` | `root` | 用户名 |
| `gateway.stats.mysql.password` | 空 | 密码（env 覆盖） |
| `gateway.stats.mysql.max-pool-size` | 2 | 连接池最大 |
| `gateway.stats.mysql.connection-timeout-ms` | 3000 | 连接超时 |
| `gateway.stats.mysql.user-stats-table` | `user_usage_stats` | 用户用量表 |
| `gateway.stats.mysql.server-stats-table` | `push_server_stats` | 实例统计表 |

#### 数据源参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.datasource.active` | `redis-stream` | 激活数据源 |
| `gateway.datasource.redis-stream.uri` | `redis://:...@47.243.250.18:6379` | 行情流 Redis URI |
| `gateway.datasource.redis-stream.stream-keys` | `market:stream` | Stream key |
| `gateway.datasource.redis-stream.block-ms` | 1000 | XREAD 阻塞毫秒 |
| `gateway.datasource.redis-stream.batch-count` | 256 | 批量读取数 |
| `gateway.datasource.redis-stream.topic-field` | `topic` | topic 字段名 |
| `gateway.datasource.redis-stream.payload-field` | `payload` | payload 字段名 |
| `gateway.datasource.redis-stream.ts-field` | `ts` | 时间戳字段名 |
| `gateway.datasource.redis-stream.seq-field` | `seq` | 序列号字段名 |
| `gateway.datasource.router.switch-timeout-ms` | 10000 | 热切换超时 |
| `gateway.datasource.router.health-poll-interval-ms` | 100 | 健康轮询间隔 |
| `gateway.datasource.router.dedupe-enabled` | true | 双源去重 |
| `gateway.datasource.router.dedupe-window-ms` | 5000 | 去重窗口 |
| `gateway.datasource.router.queue-capacity` | 65536 | 投递队列容量 |

#### 套餐配额参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| Redis `planquota:{PlanTier}` | admin 同步 | 正式配额来源（maxTopics / maxWsConnections / restQpsPerMinute） |
| `gateway.quota.plans.*` | `{}` | 仅 Redis 未命中时的本地兜底 |

#### 管理端点参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.admin.token` | `""`（空，端点禁用） | 管理端点静态令牌 |
| `gateway.instance-id` | `${HOSTNAME:local-1}` | 实例标识 |
| `gateway.auto-start` | true | ApplicationReadyEvent 后自动启动 |

#### Actuator 参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `management.endpoints.web.exposure.include` | `health,info,prometheus` | 暴露端点 |
| `management.endpoint.health.show-details` | `always` | 显示健康详情 |

#### 统计参数

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `gateway.stats.enabled` | true | 是否启用统计 |
| `gateway.stats.instance-id` | `${gateway.instance-id}` | 实例标识 |
| `gateway.stats.flush-interval` | 10s | 刷新间隔 |
| `gateway.stats.buffer-size` | 10000 | 降级缓冲容量 |

### 9.5 更新日志

| 日期 | 版本 | 变更内容 |
|------|------|----------|
| 2026-10-26 | v3.8 | 实时 K 线不再补 `tu=0`、`tc=0`。成交不再返回方向 `sd` |
| 2026-10-26 | v3.7 | 报价去掉买一卖一。昨收、涨跌、成交额、交易状态 `ts` 上游有才出现，0 保留。`ts` 是交易状态，不是时间戳 |
| 2026-10-26 | v3.6 | 盘口不再接受档位：REST 去掉 `levels` / `lv` / `8003`；WebSocket depth 主题改为 `depth.{market}.{symbol}` |
| 2026-10-26 | v3.5 | 对客鉴权改为与官网一致：请求头或握手 `deeptoken` 带 API Key 整串；WebSocket 握手后直接 `welcome` 再订阅，不再要求首帧 `auth` 与 HMAC 签名 |
| 2026-09-24 | v3.4 | 行情无数据改为 HTTP 200 空数据（8001 仅保留「标的不存在」）；`candles` 的 `et` 按套餐 `lookbackYears` 校验，超范围 3004；nonce 防重放窗口更正为 600 秒；REST 响应 `Cache-Control: no-store`；负缓存 TTL 更正为 3 秒 |
| 2026-09-17 | v3.3 | 对客契约对齐官网 API 文档：生产域名 `https://api.deeptick.cc` / `wss://api.deeptick.cc/ws/v1`；恢复 `GET /api/v1/candles`；REST 字段与官网一致（quote `s/ld/bp/ap`、orderbook `b/a/lv`、trades `d/mt`、symbols `n/d/mt`）；补充 8006/8007/8008 与 FREE 固定标的；quote-gateway 章改为内部附录 |
| 2026-09-03 | v3.2 | 标的列表与统计改 MySQL；REST 快照默认查上游（当时误写 candles 下线，已由 v3.3 更正） |
| 2026-08-21 | v3.1 | 新增 REST 快照缓存未命中回退上游查询说明（5.5 节，双侧开关 `gateway.rest.upstream-snapshot.enabled` / `gateway.snapshot-query.enabled`，默认关闭）：补充 8001 语义变化、回退配置项清单（push-gateway `gateway.rest.upstream-snapshot.*` / quote-gateway `gateway.snapshot-query.*`）、降级策略摘要，同步更新 8.5 错误码字典与 9.4/10.10 配置参数表；原 5.5/5.6 章节顺延为 5.6/5.7 |
| 2026-08-19 | v3.0 | 补充 quote-gateway 上游数据源章节：适配器框架、AllTick/iTick 适配器详解、行情流水线、Redis Stream 数据发布机制、Topic 解析规则、订阅管理 REST API、UnifiedQuoteMessage 数据模型、quote-gateway 与 push-gateway 交互协议、配置参数 |
| 2026-08-19 | v2.0 | 全面重写文档：补充完整信令总表、错误码字典（30+ 个）、配置参数参考表（50+ 项）、上游数据源对照表、断线续传机制详解、连接风暴保护说明、慢消费者保护说明、微批写入优化说明 |
| 2026-08 | v1.0 | 初版文档：包含基础接入流程、REST 6 个接口、WS 核心信令、错误码字典、FAQ |

---

> **文档维护**：
> - 对客 REST / WebSocket 契约以官网控制台「API 文档」为准；本文第 1～8 章与之对齐
> - REST 实现见 `gateway-rest`（`SignatureFilter` / `RateLimitFilter` / 各 Controller）
> - WS 协议见 `gateway-common/protocol` 与 `gateway-ws/handler`
> - 错误码字典见 `ErrorCode.java`
> - 配置参数见 `gateway-app/src/main/resources/application.yml`
