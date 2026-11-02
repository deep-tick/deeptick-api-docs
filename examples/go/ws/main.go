// DeepTick WebSocket 示例。在 examples/go 目录执行：go run ./ws
// 收到 welcome 后订阅，并每 30 秒发送 ping。
package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

func main() {
	token := os.Getenv("DEEPTICK_TOKEN")
	if token == "" {
		token = "your_api_key"
	}
	header := http.Header{}
	header.Set("deeptoken", token)
	conn, _, err := websocket.DefaultDialer.Dial("wss://api.deeptick.cc/ws/v1", header)
	if err != nil {
		panic(err)
	}
	defer conn.Close()

	var writeMu sync.Mutex
	writeJSON := func(v any) error {
		writeMu.Lock()
		defer writeMu.Unlock()
		return conn.WriteJSON(v)
	}

	done := make(chan struct{})
	go func() {
		defer close(done)
		for {
			_, raw, err := conn.ReadMessage()
			if err != nil {
				fmt.Println("read", err)
				return
			}
			fmt.Println(string(raw))
			var msg struct {
				Type string `json:"type"`
			}
			if json.Unmarshal(raw, &msg) == nil && msg.Type == "welcome" {
				subs := []map[string]any{
					{"type": "subscribe", "topics": []string{"quote.CR.BTCUSDT"}, "request_id": "q1"},
					{"type": "subscribe", "topics": []string{"depth.CR.BTCUSDT"}, "request_id": "d1"},
					{"type": "subscribe", "topics": []string{"candle.CR.BTCUSDT.1m"}, "request_id": "c1"},
					{"type": "subscribe", "topics": []string{"trade.CR.BTCUSDT"}, "request_id": "t1"},
				}
				for _, sub := range subs {
					if err := writeJSON(sub); err != nil {
						fmt.Println("subscribe", err)
						return
					}
				}
			}
		}
	}()

	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt)
	for {
		select {
		case <-done:
			return
		case <-stop:
			writeMu.Lock()
			_ = conn.WriteMessage(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseNormalClosure, ""))
			writeMu.Unlock()
			return
		case <-ticker.C:
			ping := map[string]any{
				"type":       "ping",
				"ts":         time.Now().UnixMilli(),
				"request_id": "h1",
			}
			if err := writeJSON(ping); err != nil {
				fmt.Println("ping", err)
				return
			}
		}
	}
}
