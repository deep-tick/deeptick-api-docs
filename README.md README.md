# DeepTick 行情数据 API 文档

版本 v3.8（2026-10）。对客 REST / WebSocket 契约与官网控制台「API 文档」一致。

完整说明见 [Deeptick行情API文档.md](./Deeptick行情API文档.md)。

| 接入方式 | 地址 |
| --- | --- |
| REST | `https://api.deeptick.cc` |
| WebSocket | `wss://api.deeptick.cc/ws/v1` |
| 官网 | https://www.deeptick.cc |

认证使用控制台复制的 API Key，请求头或握手参数名为 `deeptoken`。

## 代码示例

Python、Java、Go、PHP、Node.js 的 REST 与 WebSocket 示例在 [examples](./examples/README.md)。把 API Key 放到环境变量 `DEEPTICK_TOKEN` 后即可运行。
