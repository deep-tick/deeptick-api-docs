import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.WebSocket;
import java.time.Duration;
import java.util.concurrent.CompletionStage;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * DeepTick WebSocket 示例。Java 11+：java WebSocketExample.java
 * 收到 welcome 后订阅，并每 30 秒发送 ping。
 */
public class WebSocketExample {
    static final String TOKEN = System.getenv().getOrDefault("DEEPTICK_TOKEN", "your_api_key");
    static final String[] SUBSCRIBE = {
            "{\"type\":\"subscribe\",\"topics\":[\"quote.CR.BTCUSDT\"],\"request_id\":\"q1\"}",
            "{\"type\":\"subscribe\",\"topics\":[\"depth.CR.BTCUSDT\"],\"request_id\":\"d1\"}",
            "{\"type\":\"subscribe\",\"topics\":[\"candle.CR.BTCUSDT.1m\"],\"request_id\":\"c1\"}",
            "{\"type\":\"subscribe\",\"topics\":[\"trade.CR.BTCUSDT\"],\"request_id\":\"t1\"}"
    };

    public static void main(String[] args) throws Exception {
        CountDownLatch done = new CountDownLatch(1);
        StringBuilder buf = new StringBuilder();
        ScheduledExecutorService timer = Executors.newSingleThreadScheduledExecutor();
        HttpClient client = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(10)).build();
        WebSocket.Listener listener = new WebSocket.Listener() {
            @Override
            public void onOpen(WebSocket ws) {
                System.out.println("connected");
                timer.scheduleAtFixedRate(() -> {
                    long ts = System.currentTimeMillis();
                    ws.sendText("{\"type\":\"ping\",\"ts\":" + ts + ",\"request_id\":\"h1\"}", true);
                }, 30, 30, TimeUnit.SECONDS);
                ws.request(1);
            }

            @Override
            public CompletionStage<?> onText(WebSocket ws, CharSequence data, boolean last) {
                buf.append(data);
                if (last) {
                    String msg = buf.toString();
                    buf.setLength(0);
                    System.out.println(msg);
                    if (msg.contains("\"type\":\"welcome\"")) {
                        for (String sub : SUBSCRIBE) {
                            ws.sendText(sub, true);
                        }
                    }
                }
                ws.request(1);
                return null;
            }

            @Override
            public CompletionStage<?> onClose(WebSocket ws, int status, String reason) {
                System.out.println("closed " + status + " " + reason);
                timer.shutdown();
                done.countDown();
                return null;
            }

            @Override
            public void onError(WebSocket ws, Throwable error) {
                error.printStackTrace();
                timer.shutdown();
                done.countDown();
            }
        };
        client.newWebSocketBuilder()
                .header("deeptoken", TOKEN)
                .connectTimeout(Duration.ofSeconds(15))
                .buildAsync(URI.create("wss://api.deeptick.cc/ws/v1"), listener)
                .join();
        done.await();
    }
}
