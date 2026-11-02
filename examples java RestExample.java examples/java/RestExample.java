import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * DeepTick REST 示例。Java 11+：java RestExample.java
 * 环境变量 DEEPTICK_TOKEN 为控制台复制的 API Key。
 */
public class RestExample {
    static final String BASE = "https://api.deeptick.cc";
    static final String TOKEN = System.getenv().getOrDefault("DEEPTICK_TOKEN", "your_api_key");
    static final String SYMBOL = "BTCUSDT.CR";

    public static void main(String[] args) throws Exception {
        HttpClient client = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(10)).build();
        get(client, "/api/v1/time", Map.of(), false);
        get(client, "/api/v1/quote", Map.of("symbols", SYMBOL), true);
        get(client, "/api/v1/orderbook", Map.of("symbol", SYMBOL), true);
        get(client, "/api/v1/trades", map("symbol", SYMBOL, "limit", "5"), true);
        get(client, "/api/v1/candles", map("symbols", SYMBOL, "interval", "1m", "limit", "5"), true);
        get(client, "/api/v1/symbols", map("market", "CR", "page", "1", "page_size", "5"), true);
    }

    static Map<String, String> map(String... kv) {
        Map<String, String> out = new LinkedHashMap<>();
        for (int i = 0; i < kv.length; i += 2) out.put(kv[i], kv[i + 1]);
        return out;
    }

    static void get(HttpClient client, String path, Map<String, String> params, boolean auth) throws Exception {
        StringBuilder url = new StringBuilder(BASE).append(path);
        if (!params.isEmpty()) {
            url.append('?');
            boolean first = true;
            for (var entry : params.entrySet()) {
                if (!first) url.append('&');
                first = false;
                url.append(URLEncoder.encode(entry.getKey(), StandardCharsets.UTF_8));
                url.append('=');
                url.append(URLEncoder.encode(entry.getValue(), StandardCharsets.UTF_8));
            }
        }
        HttpRequest.Builder builder = HttpRequest.newBuilder(URI.create(url.toString()))
                .timeout(Duration.ofSeconds(15))
                .GET();
        if (auth) builder.header("deeptoken", TOKEN);
        HttpResponse<String> resp = client.send(builder.build(), HttpResponse.BodyHandlers.ofString());
        System.out.println("\n== " + path + " HTTP " + resp.statusCode());
        System.out.println(resp.body());
    }
}
