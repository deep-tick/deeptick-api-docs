/**
 * DeepTick REST 示例。Node.js 18+：node rest_example.mjs
 * 环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
 */

const token = process.env.DEEPTICK_TOKEN || "your_api_key";
const base = "https://api.deeptick.cc";
const symbol = "BTCUSDT.CR";

async function get(path, params, auth) {
  const url = new URL(base + path);
  for (const [key, value] of Object.entries(params || {})) {
    url.searchParams.set(key, value);
  }
  const headers = auth ? { deeptoken: token } : {};
  const resp = await fetch(url, { headers });
  const body = await resp.text();
  console.log(`\n== ${path} HTTP ${resp.status}\n${body}`);
}

await get("/api/v1/time", {}, false);
await get("/api/v1/quote", { symbols: symbol }, true);
await get("/api/v1/orderbook", { symbol }, true);
await get("/api/v1/trades", { symbol, limit: "5" }, true);
await get("/api/v1/candles", { symbols: symbol, interval: "1m", limit: "5" }, true);
await get("/api/v1/symbols", { market: "CR", page: "1", page_size: "5" }, true);
