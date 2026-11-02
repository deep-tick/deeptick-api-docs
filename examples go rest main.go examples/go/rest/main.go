// DeepTick REST 示例。在 examples/go 目录执行：go run ./rest
// 环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
package main

import (
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"time"
)

const base = "https://api.deeptick.cc"

func main() {
	token := os.Getenv("DEEPTICK_TOKEN")
	if token == "" {
		token = "your_api_key"
	}
	symbol := "BTCUSDT.CR"
	client := &http.Client{Timeout: 15 * time.Second}

	get(client, "/api/v1/time", nil, "")
	get(client, "/api/v1/quote", url.Values{"symbols": {symbol}}, token)
	get(client, "/api/v1/orderbook", url.Values{"symbol": {symbol}}, token)
	get(client, "/api/v1/trades", url.Values{"symbol": {symbol}, "limit": {"5"}}, token)
	get(client, "/api/v1/candles", url.Values{"symbols": {symbol}, "interval": {"1m"}, "limit": {"5"}}, token)
	get(client, "/api/v1/symbols", url.Values{"market": {"CR"}, "page": {"1"}, "page_size": {"5"}}, token)
}

func get(client *http.Client, path string, params url.Values, token string) {
	u := base + path
	if len(params) > 0 {
		u += "?" + params.Encode()
	}
	req, err := http.NewRequest(http.MethodGet, u, nil)
	if err != nil {
		panic(err)
	}
	if token != "" {
		req.Header.Set("deeptoken", token)
	}
	resp, err := client.Do(req)
	if err != nil {
		fmt.Println("\n==", path, err)
		return
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	fmt.Printf("\n== %s HTTP %d\n%s\n", path, resp.StatusCode, body)
}
