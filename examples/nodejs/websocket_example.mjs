/**
 * DeepTick WebSocket 示例。Node.js 22+（全局 WebSocket）：node websocket_example.mjs
 * 浏览器不能设置握手头，因此这里把 deeptoken 放在查询参数里。
 * 收到 welcome 后订阅，并每 30 秒发送 ping。
 */

const token = process.env.DEEPTICK_TOKEN || "your_api_key";
if (typeof WebSocket === "undefined") {
  console.error("需要 Node.js 22 及以上，或自行改用 ws 库");
  process.exit(1);
}

const url = new URL("wss://api.deeptick.cc/ws/v1");
url.searchParams.set("deeptoken", token);
const ws = new WebSocket(url);

const subscriptions = [
  { topics: ["quote.CR.BTCUSDT"], request_id: "q1" },
  { topics: ["depth.CR.BTCUSDT"], request_id: "d1" },
  { topics: ["candle.CR.BTCUSDT.1m"], request_id: "c1" },
  { topics: ["trade.CR.BTCUSDT"], request_id: "t1" },
];

ws.addEventListener("open", () => {
  console.log("connected");
  setInterval(() => {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({
        type: "ping",
        ts: Date.now(),
        request_id: "h1",
      }));
    }
  }, 30_000);
});

ws.addEventListener("message", (event) => {
  const text = String(event.data);
  console.log(text);
  const msg = JSON.parse(text);
  if (msg.type === "welcome") {
    for (const sub of subscriptions) {
      ws.send(JSON.stringify({ type: "subscribe", ...sub }));
    }
  }
});

ws.addEventListener("error", (event) => {
  console.error("error", event);
});

ws.addEventListener("close", (event) => {
  console.log("closed", event.code, event.reason);
  process.exit(0);
});
