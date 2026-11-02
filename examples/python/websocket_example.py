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
# 一次 subscribe 只能是同一种类型，所以按类型分开发送。标的用免费套餐可请求的 BTCUSDT.CR。
SUBSCRIPTIONS = [
    (["quote.CR.BTCUSDT"], "q1"),
    (["depth.CR.BTCUSDT"], "d1"),
    (["candle.CR.BTCUSDT.1m"], "c1"),
    (["trade.CR.BTCUSDT"], "t1"),
]


def on_open(ws):
    print("connected, waiting for welcome")


def on_message(ws, message):
    msg = json.loads(message)
    kind = msg.get("type")
    print(kind, message)
    if kind == "welcome":
        for topics, request_id in SUBSCRIPTIONS:
            ws.send(json.dumps({
                "type": "subscribe",
                "topics": topics,
                "request_id": request_id,
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
