[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文件由模型翻译，未经人工审校；如有出入，以英文版为准。

# mercurius-mcp-gemini-api

基于 stdio 的 MCP 服务器，通过 Gemini API 密钥使用 Google 的媒体模型：图像、语音、音乐、视频、转写、文件分析和后台研究代理。
它是 [Mercurius](https://github.com/opencck/mercurius) 的一个模块，基于 [AMPHP](https://amphp.org) 构建。
服务器本身不向 Google 发请求：每次工具调用都是对固定版本的官方
[gemini-api CLI](https://github.com/google-gemini/gemini-api-cli) 的一次运行。

该 CLI 只能使用 API 密钥；每次调用按 Gemini API 价目表计费。它与 Google 订阅无关。

## 工具

| 工具 | 作用 | 提示 |
|------|------|------|
| `image` | 根据描述生成图像，返回文件 | 非破坏性 |
| `speech` | 逐字朗读文本，返回 WAV 文件 | 非破坏性 |
| `music` | 生成一段音乐，返回文件 | 无：客户端询问其所有者 |
| `video_start` | 开始生成视频，返回操作 id | 无：客户端询问其所有者 |
| `video_result` | 等待已开始的视频最多一分钟，返回文件 | 只读 |
| `transcribe` | 将音频或视频文件转写为文本 | 只读 |
| `analyze` | 回答关于文件或 YouTube 视频的问题 | 只读 |
| `research_start` | 启动后台研究代理（Deep Research），返回运行 id | 无：客户端询问其所有者 |
| `research_status` | 读取运行的状态和报告 | 只读 |

文件以 `files: [{name, content_base64}]` 传入（一次调用最多 10 个文件、14 MB），并以内嵌资源返回（每个最多 20 MB）。

## 安装

```bash
composer install --no-dev
```

安装后，`bin/fetch-binary` 会下载 `binary.lock.json` 中指定的 Linux `x86_64` 或 `arm64` 发行包，校验其 sha256，
并把二进制文件放入 `vendor/gemini-api/`。在其他系统上，或设置了 `GEMINI_API_SKIP_FETCH=1` 时，不会下载；
此时请用 `GEMINI_API_BIN` 指向你自己的二进制文件。

## 运行

```json
{
  "mcpServers": {
    "gemini-api": {
      "command": "php",
      "args": ["/path/to/bin/gemini-api-mcp"],
      "env": { "GEMINI_API_KEY": "..." }
    }
  }
}
```

| 变量 | 含义 |
|------|------|
| `GEMINI_API_KEY` | Gemini API 密钥（Google AI Studio）。没有它服务器仍会启动，并在每次调用时说明缺少什么 |
| `GEMINI_API_BIN` | 用于替代固定版本的其他二进制文件；多个词表示带有前置参数的命令 |
| `GEMINI_API_TIMEOUT` | 二进制文件单次运行允许的秒数：默认 100，最多 900 |
| `GEMINI_API_PROXY` | 二进制文件的出口：代理地址（`http://`、`socks5h://`）或单词 `direct`。留空：使用条目中的代理（见下），否则在 Mercurius 安装的门 `http://vless-proxy:8080` 可连接时使用它（它通向安装的 VLESS 路由或其代理），否则直连 |
| `HTTPS_PROXY`、`HTTP_PROXY`、`NO_PROXY` | 条目中的代理，传递给二进制文件 |

在 Mercurius 中，服务器随发行包提供；密钥是代理的一个机密，以 `{GEMINI_API_KEY}` 的形式填入 `.mcp.json` 的条目。

## 安全

- 密钥保存在进程的环境中，绝不出现在参数里；二进制文件只得到密钥、位于调用目录内的主目录和搜索路径，
  服务器环境中的其他内容不会传给它。
- 模型写出的文本不可能变成命令行标志：提示词放在 `--` 之后或通过 stdin 传入，短值按模式校验，
  YouTube 链接必须指向单个 YouTube 视频。
- 每次调用在自己的临时目录中进行，应答后即删除；代理给出的文件名只保留最后一段和安全字符。
- 二进制文件的版本及其压缩包的 sha256 均已固定。

## 检查

```bash
composer check   # PHPUnit、PHPStan（max）、Psalm（级别 1）；不需要网络，使用替身二进制
```

服务器是用替身二进制检查的：编写时没有可用的真实密钥。尚待检查的内容见 `BRIEF.md`。

## 许可证

MIT
