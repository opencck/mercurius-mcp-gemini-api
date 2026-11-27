**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-mcp-gemini-api

An MCP server over stdio for Google's media models by a Gemini API key: images, speech, music, video, transcription,
file analysis and background research agents. It is a module of [Mercurius](https://github.com/opencck/mercurius),
built on [AMPHP](https://amphp.org). The server makes no requests to Google itself: each tool call is one run of the
official [gemini-api CLI](https://github.com/google-gemini/gemini-api-cli) of a pinned version.

The CLI works by an API key only; every call is paid by the Gemini API price list. It has nothing to do with a Google
subscription.

## Tools

| Tool | What it does | Hint |
|------|--------------|------|
| `image` | Makes an image from a description, returns the file | not destructive |
| `speech` | Speaks a text verbatim, returns a WAV file | not destructive |
| `music` | Makes a piece of music, returns the file | none: the client asks its owner |
| `video_start` | Starts making a video, returns the id of the operation | none: the client asks its owner |
| `video_result` | Waits up to a minute for a started video, returns the file | read-only |
| `transcribe` | Transcribes audio or video files into text | read-only |
| `analyze` | Answers a question about files or a YouTube video | read-only |
| `research_start` | Starts a background research agent (Deep Research), returns the id of the run | none: the client asks its owner |
| `research_status` | Reads the state and the report of a run | read-only |

Files go in as `files: [{name, content_base64}]` (up to 10 files and 14 MB in one call) and come back as embedded
resources (up to 20 MB each).

## Install

```bash
composer install --no-dev
```

After the install `bin/fetch-binary` downloads the release named in `binary.lock.json` for Linux `x86_64` or `arm64`,
checks its sha256 and puts the binary into `vendor/gemini-api/`. On another system, or with
`GEMINI_API_SKIP_FETCH=1`, nothing is downloaded; point `GEMINI_API_BIN` at a binary of your own then.

## Run

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

| Variable | What it is |
|----------|------------|
| `GEMINI_API_KEY` | The key of the Gemini API (Google AI Studio). Without it the server starts and tells each call what is missing |
| `GEMINI_API_BIN` | Another binary than the pinned one; several words are a command with its first arguments |
| `GEMINI_API_TIMEOUT` | Seconds one run of the binary may take: 100 by default, 900 at most |
| `GEMINI_API_PROXY` | The way out of the binary: a proxy address (`http://`, `socks5h://`) or the word `direct`. Empty: a proxy of the entry (below), else the door of a Mercurius installation `http://vless-proxy:8080` when it listens (it leads to the VLESS route of the installation or to its proxy), else directly |
| `HTTPS_PROXY`, `HTTP_PROXY`, `NO_PROXY` | A proxy of the entry, passed on to the binary |

In Mercurius the server comes with the delivery; the key is a secret of the agent put into the entry of `.mcp.json`
as `{GEMINI_API_KEY}`.

## Safety

- The key lives in the environment of the process, never in arguments; the binary gets the key, a home inside the
  folder of the call and the search path, and nothing else of the server's environment.
- Text written by a model cannot become a flag: a prompt goes after `--` or through stdin, short values are checked
  against a pattern, a YouTube link must be a link to one YouTube video.
- Each call works in a temporary folder of its own, removed after the answer; a file name given by the agent is
  reduced to its last part and safe characters.
- The version of the binary and the sha256 of its archive are pinned.

## Checks

```bash
composer check   # PHPUnit, PHPStan (max), Psalm (level 1); no network, a fake binary stands in
```

The server was checked against a fake binary: there was no live key at the time of writing. See `BRIEF.md` for what
is left to check.

## License

MIT
