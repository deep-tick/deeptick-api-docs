"""DeepTick REST 示例：时间、报价、盘口、成交、K 线、标的列表。

运行：python rest_example.py
环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
"""

import json
import os
import urllib.error
import urllib.parse
import urllib.request

BASE = "https://api.deeptick.cc"
TOKEN = os.environ.get("DEEPTICK_TOKEN", "your_api_key")
SYMBOL = "BTCUSDT.CR"


def get(path, params=None, auth=True):
    query = urllib.parse.urlencode(params or {})
    url = BASE + path + (("?" + query) if query else "")
    headers = {"deeptoken": TOKEN} if auth else {}
    req = urllib.request.Request(url, headers=headers)
    try:
        resp = urllib.request.urlopen(req, timeout=15)
    except urllib.error.HTTPError as exc:
        resp = exc
    body = resp.read().decode("utf-8")
    print("\n==", path, "HTTP", resp.status)
    print(body)
    return json.loads(body)


def main():
    get("/api/v1/time", auth=False)
    get("/api/v1/quote", {"symbols": SYMBOL})
    get("/api/v1/orderbook", {"symbol": SYMBOL})
    get("/api/v1/trades", {"symbol": SYMBOL, "limit": 5})
    get("/api/v1/candles", {"symbols": SYMBOL, "interval": "1m", "limit": 5})
    get("/api/v1/symbols", {"market": "CR", "page": 1, "page_size": 5})


if __name__ == "__main__":
    main()
