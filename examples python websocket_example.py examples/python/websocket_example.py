"""DeepTick WebSocket 示例：订阅报价、盘口、K 线、成交，并定时 ping。

运行：pip install -r requirements.txt && python websocket_example.py
环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
"""

import json
import os
import threading
import time

import websocket

TOKEN = os.environ.get("DEEPTICK_TOKEN", "your_api_key")
URL = "wss://api.deeptick.cc/ws/v1"
TOPICS = [
    "quote.CR.BTCUSDT",
    "depth.CR.BTCUSDT",
    "candle.CR.BTCUSDT.1m",
    "trade.CR.ETHUSDT",
]


def on_open(ws):
    print("connected, waiting for welcome")


def on_message(ws, message):
    msg = json.loads(message)
    kind = msg.get("type")
    print(kind, message)
    if kind == "welcome":
        ws.send(json.dumps({
            "type": "subscribe",
            "topics": TOPICS,
            "request_id": "r1",
        }))
    elif kind == "error":
        print("server error", msg.get("code"), msg.get("message"))


def on_error(ws, error):
    print("error", error)


def on_close(ws, status, reason):
    print("closed", status, reason)


def heartbeat(ws):
    while True:
        time.sleep(30)
        if not ws.keep_running:
            return
        ws.send(json.dumps({
            "type": "ping",
            "ts": int(time.time() * 1000),
            "request_id": "h1",
        }))


def main():
    ws = websocket.WebSocketApp(
        URL,
        header={"deeptoken": TOKEN},
        on_open=on_open,
        on_message=on_message,
        on_error=on_error,
        on_close=on_close,
    )
    threading.Thread(target=heartbeat, args=(ws,), daemon=True).start()
    ws.run_forever(ping_interval=0)


if __name__ == "__main__":
    main()
