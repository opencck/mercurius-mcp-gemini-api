<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\File;
use Amp\File\FilesystemException;

/**
 * The MCP side: JSON-RPC lines over stdin and stdout, one tool per ability of the gemini-api CLI. Each call runs the
 * CLI once in a folder of its own: files the agent gave go there, files the CLI made come back in the answer, and the
 * folder is removed. The server decides nothing about what a call may cost: the client that started it (Mercurius)
 * classifies each call by the hints below and asks its owner.
 */
final class Server {
    public const PROTOCOL = '2025-06-18';

    /** What one file of an answer may weigh: a client keeps files up to 20 MB. */
    public const FILE_MAX = 20_000_000;
    /** The CLI sends a local file inside the request: about 14 MB of media fit. */
    public const INPUT_MAX = 14_000_000;
    private const FILES_MAX = 10;
    private const TEXT_MAX = 100_000;
    private const PROMPT_MAX = 20_000;
    private const LINE_MAX = 48 * 1024 * 1024;

    private const MODEL = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9.-]{0,63}$', 'description' => 'Another model than the default one of this tool.'];
    private const FILES = [
        'type' => 'array',
        'maxItems' => self::FILES_MAX,
        'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'mime_type' => ['type' => 'string'], 'content_base64' => ['type' => 'string']], 'required' => ['name', 'content_base64']],
    ];
    public const MODELS_KEY = 'mercurius.models';
    /** the group of the CLI's catalog → the kinds of the contract with the tool of each */
    private const GROUPS = [
        'Gemini' => [['vision', 'analyze'], ['video', 'analyze']],
        'Gemma' => [['vision', 'analyze']],
        'Image' => [['image', 'image']],
        'Music' => [['music', 'music']],
        'Video' => [['film', 'video_start']],
    ];
    private const RESEARCH_AGENTS = ['deep-research-preview-04-2026', 'deep-research-max-preview-04-2026'];

    public function __construct(private readonly Binary $binary, private readonly Options $options) {}

    /**
     * The hints are the cost of a call: reading and understanding is read-only, a picture and speech are cheap and
     * not destructive, and music, video and a research agent carry no hint — a client asks its owner before them.
     *
     * @return list<array<string, mixed>>
     */
    public static function tools(): array {
        $prompt = ['type' => 'string', 'minLength' => 1, 'maxLength' => self::PROMPT_MAX];
        $id = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{4,512}$'];
        $object = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        return [
            ['name' => 'image', 'description' => 'Makes an image from a description with Google\'s image model and returns the file. Paid by the Gemini API key.', 'inputSchema' => $object(['prompt' => $prompt, 'aspect_ratio' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{1,2}$'], 'size' => ['type' => 'string', 'enum' => ['512', '1K', '2K', '4K']], 'model' => self::MODEL], ['prompt']), 'annotations' => ['destructiveHint' => false]],
            ['name' => 'speech', 'description' => 'Speaks a text verbatim with Google\'s speech model and returns a WAV file. Paid by the Gemini API key.', 'inputSchema' => $object(['text' => $prompt, 'voice' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,64}$', 'description' => 'A voice name such as Kore, Puck, Zephyr.'], 'style' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Delivery style, e.g. "whispered urgently".'], 'language' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$'], 'model' => self::MODEL], ['text']), 'annotations' => ['destructiveHint' => false]],
            ['name' => 'music', 'description' => 'Makes a piece of music from a description with Google\'s music model and returns the file. An expensive call, paid by the Gemini API key.', 'inputSchema' => $object(['prompt' => $prompt, 'model' => self::MODEL], ['prompt'])],
            ['name' => 'video_start', 'description' => 'Starts making a video from a description with Google\'s video model and returns the id of the operation; video_result fetches the file when it is ready. An expensive call, paid by the Gemini API key.', 'inputSchema' => $object(['prompt' => $prompt, 'aspect_ratio' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{1,2}$'], 'resolution' => ['type' => 'string', 'enum' => ['360p', '480p', '720p', '1080p', '4k']], 'model' => self::MODEL], ['prompt'])],
            ['name' => 'video_result', 'description' => 'Waits up to a minute for a video started by video_start and returns the file, or says that it is not ready yet.', 'inputSchema' => $object(['id' => $id], ['id']), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'transcribe', 'description' => 'Transcribes audio or video files into text with speakers and timestamps.', 'inputSchema' => $object(['files' => self::FILES, 'format' => ['type' => 'string', 'enum' => ['md', 'text', 'json', 'srt']], 'speakers' => ['type' => 'boolean'], 'timestamps' => ['type' => 'boolean'], 'model' => self::MODEL], ['files']), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'analyze', 'description' => 'Answers a question about files (images, audio, video, PDF, text) or a YouTube video.', 'inputSchema' => $object(['question' => $prompt, 'files' => self::FILES, 'youtube_url' => ['type' => 'string', 'maxLength' => 200], 'model' => self::MODEL], []), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'research_start', 'description' => 'Starts a background research agent of Google (Deep Research) on a question and returns the id of the run; research_status reads its state and report. A long and expensive run, paid by the Gemini API key.', 'inputSchema' => $object(['input' => $prompt, 'agent' => ['type' => 'string', 'enum' => self::RESEARCH_AGENTS]], ['input'])],
            ['name' => 'research_status', 'description' => 'Reads the state of a run started by research_start and, when it is done, its report.', 'inputSchema' => $object(['id' => $id], ['id']), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'models_list', 'description' => 'The models of the CLI\'s catalog (offline) with what each does and the tool that calls it.', 'inputSchema' => $object([], []), 'annotations' => ['readOnlyHint' => true]],
        ];
    }

    /** Reads requests until stdin ends; each answer is one line. */
    public function serve(ReadableStream $in, WritableStream $out): void {
        $buffer = '';
        while (($chunk = $in->read()) !== null) {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                $reply = $line === '' ? null : $this->handle($line);
                if ($reply !== null) {
                    $out->write(json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                }
            }
            if (strlen($buffer) > self::LINE_MAX) {
                $buffer = '';
            }
        }
    }

    /** @return array<string, mixed>|null the answer; null — a notification */
    public function handle(string $line): ?array {
        try {
            /** @var mixed $message */
            $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
        }
        if (!is_array($message) || !is_string($message['method'] ?? null)) {
            return null;
        }
        /** @var mixed $id */
        $id = $message['id'] ?? null;
        if ($id === null) {
            return null;
        }
        /** @psalm-suppress MixedAssignment the request is any JSON; each value is checked where it is used */
        $params = $message['params'] ?? null;
        $params = is_array($params) ? $params : [];
        $result = match ($message['method']) {
            // the models of the server are offered by the contract of Mercurius, in the place the protocol keeps for
            // additions: a client that does not know the key sees the tools only
            'initialize' => ['protocolVersion' => self::PROTOCOL, 'capabilities' => ['tools' => new \stdClass(), 'experimental' => [self::MODELS_KEY => ['version' => 1, 'catalog' => 'models_list']]], 'serverInfo' => ['name' => 'mercurius-mcp-gemini-api', 'version' => '1.0.0']],
            'tools/list' => ['tools' => self::tools()],
            'tools/call' => $this->call($params),
            'ping' => new \stdClass(),
            default => null,
        };
        return $result === null
            ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent?: array<string, mixed>}
     */
    private function call(array $params): array {
        if (!$this->options->configured()) {
            return self::answer('This entry carries no key: GEMINI_API_KEY is needed in its env', true);
        }
        /** @psalm-suppress MixedAssignment the arguments are any JSON; each is checked where it is used */
        $args = $params['arguments'] ?? null;
        $args = new Arguments(is_array($args) ? $args : []);
        $name = self::str($params, 'name') ?? '';
        $dir = sys_get_temp_dir() . '/gemini-api-mcp-' . bin2hex(random_bytes(8));
        try {
            File\createDirectoryRecursively($dir . '/in', 0700);
            try {
                return match ($name) {
                    'image' => $this->made($dir, ['image', ...$args->flags(['aspect_ratio' => '--aspect-ratio', 'size' => '--image-size', 'model' => '--model']), '--', $args->text('prompt', self::PROMPT_MAX)]),
                    'speech' => $this->made($dir, ['tts', '--stdin', ...$args->flags(['voice' => '--voice', 'style' => '--style', 'language' => '--language', 'model' => '--model'])], $args->text('text', self::PROMPT_MAX)),
                    'music' => $this->made($dir, ['music', ...$args->flags(['model' => '--model']), '--', $args->text('prompt', self::PROMPT_MAX)]),
                    'video_start' => $this->started($dir, ['video', '--async', ...$args->flags(['aspect_ratio' => '--aspect-ratio', 'resolution' => '--resolution', 'model' => '--model']), '--', $args->text('prompt', self::PROMPT_MAX)], 'video_result'),
                    'video_result' => $this->pending($this->made($dir, ['video', '--resume', $args->id('id'), '--poll-timeout', min(60, max(5, $this->options->timeout - 20)) . 's'])),
                    'transcribe' => $this->transcribed($dir, $args),
                    'analyze' => $this->analyzed($dir, $args),
                    'research_start' => $this->started($dir, ['agent', 'run', '--background', '--agent', $args->oneOf('agent', self::RESEARCH_AGENTS), '--', $args->text('input', self::PROMPT_MAX)], 'research_status'),
                    'research_status' => $this->status($dir, $args->id('id')),
                    'models_list' => $this->catalog($dir),
                    default => self::answer('Unknown tool', true),
                };
            } finally {
                self::remove($dir);
            }
        } catch (\InvalidArgumentException | BinaryFailure $e) {
            return self::answer($e->getMessage(), true);
        } catch (FilesystemException $e) {
            return self::answer('The folder of the call could not be used: ' . $e->getMessage(), true);
        }
    }

    /**
     * A command that makes files: they come back as embedded resources, with a line about each.
     *
     * @param list<string> $command
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function made(string $dir, array $command, ?string $stdin = null): array {
        $result = $this->run($dir, $command, $stdin);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        $content = [];
        $lines = [];
        foreach (self::outputs($dir) as $path) {
            $name = basename($path);
            $size = File\getSize($path);
            if ($size > self::FILE_MAX) {
                $lines[] = sprintf('%s is %d MB: larger than the %d MB a file of an answer may weigh, not returned', $name, intdiv($size, 1_000_000), intdiv(self::FILE_MAX, 1_000_000));
                continue;
            }
            $lines[] = sprintf('%s (%d KB)', $name, intdiv($size + 1023, 1024));
            $content[] = ['type' => 'resource', 'resource' => ['uri' => 'file:///' . $name, 'name' => $name, 'mimeType' => self::mime($name), 'blob' => base64_encode(File\read($path))]];
        }
        if ($lines === []) {
            return self::answer('The call ended without a file. ' . self::cut(trim($result->stdout), 2000), true);
        }
        return ['content' => [['type' => 'text', 'text' => implode("\n", $lines)], ...$content], 'isError' => false];
    }

    /**
     * A command that starts a long operation and answers with its id.
     *
     * @param list<string> $command
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent?: array<string, string>}
     */
    private function started(string $dir, array $command, string $next): array {
        $result = $this->run($dir, $command);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        $document = self::document($result->stdout);
        $id = self::str($document, 'id') ?? self::str($document, 'name') ?? self::str($document, 'response_id');
        if ($id === null || preg_match('~^[A-Za-z0-9_/-]{4,512}$~', $id) !== 1) {
            return self::answer('Started, but the answer names no id: ' . self::cut(trim($result->stdout), 2000), true);
        }
        $status = self::str($document, 'status') ?? 'started';
        // the id comes apart as well: a client reads the field rather than the text
        return self::answer(sprintf("id: %s\nstatus: %s\nRead the result later with %s.", basename($id), $status, $next), false) + ['structuredContent' => ['id' => basename($id), 'status' => $status]];
    }

    /**
     * The result of a video read before it is ready is no error: it says so, and a client asks again.
     *
     * @param array{content: list<array<string, mixed>>, isError: bool} $answer
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent?: array<string, bool>}
     */
    private function pending(array $answer): array {
        // an answer with a file, or one that says why a file is not returned, is the answer
        if (!$answer['isError']) {
            return $answer;
        }
        $text = '';
        foreach ($answer['content'] as $part) {
            /** @var mixed $said */
            $said = $part['text'] ?? null;
            $text .= is_string($said) ? $said : '';
        }
        // a failure of the CLI that is not a wait stays a failure
        if (preg_match('~not (yet )?(ready|done|complete)|pending|running|in.progress|poll|timed? ?out|without a file~i', $text) !== 1) {
            return $answer;
        }
        return ['content' => [['type' => 'text', 'text' => 'The video is not ready yet: read the result again later.']], 'isError' => false, 'structuredContent' => ['done' => false]];
    }

    /**
     * The models of the CLI's catalog (offline, no request) by the contract of Mercurius: each with its kind and the
     * tool that calls it. A model of speech is a voice when it makes speech, a transcription when it reads it.
     *
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent?: array<string, mixed>}
     */
    private function catalog(string $dir): array {
        $result = $this->run($dir, ['models']);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        /** @var mixed $decoded */
        $decoded = json_decode(trim($result->stdout), true, 64);
        /** @var mixed $rows */
        $rows = is_array($decoded) && array_is_list($decoded) ? $decoded : (is_array($decoded) ? ($decoded['data'] ?? $decoded['items'] ?? $decoded['results'] ?? []) : []);
        $models = [];
        /** @var mixed $row */
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::str($row, 'value');
            $group = self::str($row, 'group');
            if ($id === null || $group === null) {
                continue;
            }
            $kinds = self::GROUPS[$group] ?? ($group === 'Speech' ? [str_contains($id, 'transcribe') ? ['speech', 'transcribe'] : ['voice', 'speech']] : []);
            /** @var mixed $listed */
            $listed = $row['default_for'] ?? null;
            $defaultFor = is_array($listed) ? $listed : [];
            foreach ($kinds as [$kind, $tool]) {
                $model = ['id' => $id, 'kind' => $kind, 'tool' => $tool, 'title' => mb_substr(self::str($row, 'description') ?? $id, 0, 120),
                    'input' => match ($kind) {
                        'vision' => ['text', 'image', 'file'],
                        'video' => ['text', 'video'],
                        'speech' => ['audio', 'video'],
                        default => ['text'],
                    },
                    'default' => ($row['default'] ?? false) === true || in_array($kind === 'film' ? 'video' : $kind, $defaultFor, true)];
                if ($kind === 'film') {
                    $model['result_tool'] = 'video_result';
                }
                $models[] = $model;
            }
        }
        return ['content' => [['type' => 'text', 'text' => sprintf('%d models', count($models))]], 'isError' => false, 'structuredContent' => ['models' => $models]];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function status(string $dir, string $id): array {
        $result = $this->run($dir, ['agent', 'status', $id]);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        $document = self::document($result->stdout);
        $status = self::str($document, 'status') ?? 'unknown';
        return self::answer('status: ' . $status . "\n" . self::cut(self::texts($document), self::TEXT_MAX), in_array($status, ['failed', 'cancelled'], true));
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function transcribed(string $dir, Arguments $args): array {
        $inputs = $this->inputs($dir, $args);
        if ($inputs === []) {
            throw new \InvalidArgumentException('files: at least one audio or video file is needed');
        }
        $command = ['transcribe', '--format', $args->oneOf('format', ['md', 'text', 'json', 'srt']), ...$args->flags(['model' => '--model'])];
        if ($args->flag('speakers') === false) {
            $command[] = '--no-speakers';
        }
        if ($args->flag('timestamps') === false) {
            $command[] = '--no-timestamps';
        }
        foreach ($inputs as $input) {
            array_push($command, '--input', $input);
        }
        $result = $this->run($dir, $command);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        $texts = [];
        foreach (self::outputs($dir) as $path) {
            $texts[] = File\getSize($path) > self::FILE_MAX ? basename($path) . ': too large to return' : File\read($path);
        }
        return $texts === []
            ? self::answer('The call ended without a transcript. ' . self::cut(trim($result->stdout), 2000), true)
            : self::answer(self::cut(implode("\n\n", $texts), self::TEXT_MAX), false);
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function analyzed(string $dir, Arguments $args): array {
        $inputs = $this->inputs($dir, $args);
        $url = $args->optional('youtube_url');
        if ($url !== null) {
            if (preg_match('~^https://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/|live/)|youtu\.be/)[A-Za-z0-9_-]{6,20}([?&][A-Za-z0-9_=&.%-]*)?$~', $url) !== 1) {
                throw new \InvalidArgumentException('youtube_url: a link to one YouTube video is needed');
            }
            $inputs[] = $url;
        }
        if ($inputs === []) {
            throw new \InvalidArgumentException('files or youtube_url: something to analyze is needed');
        }
        $command = ['analyze', ...$args->flags(['model' => '--model'])];
        foreach ($inputs as $input) {
            array_push($command, '--input', $input);
        }
        $question = $args->optional('question');
        if ($question !== null) {
            array_push($command, '--', self::cut($question, self::PROMPT_MAX));
        }
        $result = $this->run($dir, $command);
        if ($result->exit !== 0) {
            return self::answer(self::failure($result), true);
        }
        $document = self::document($result->stdout);
        $text = self::str($document, 'text') ?? trim($result->stdout);
        return self::answer(self::cut($text, self::TEXT_MAX), $text === '');
    }

    /**
     * Writes the files of a call into its folder and returns their paths.
     *
     * @return list<string>
     */
    private function inputs(string $dir, Arguments $args): array {
        $paths = [];
        $total = 0;
        foreach ($args->files(self::FILES_MAX) as $index => $file) {
            $bytes = base64_decode($file['content_base64'], true);
            if ($bytes === false || $bytes === '') {
                throw new \InvalidArgumentException(sprintf('files: %s is not base64', $file['name']));
            }
            $total += strlen($bytes);
            if ($total > self::INPUT_MAX) {
                throw new \InvalidArgumentException(sprintf('files: more than %d MB in one call; give fewer or smaller files', intdiv(self::INPUT_MAX, 1_000_000)));
            }
            // the name is the agent's word: only its safe characters name a file here
            $name = preg_replace('~[^A-Za-z0-9._-]~', '_', basename(str_replace('\\', '/', $file['name']))) ?? 'file';
            $path = sprintf('%s/in/%d-%s', $dir, $index, ltrim($name, '.-') === '' ? 'file' : ltrim($name, '.-'));
            File\write($path, $bytes);
            $paths[] = $path;
        }
        return $paths;
    }

    /**
     * @param list<string> $command
     */
    private function run(string $dir, array $command, ?string $stdin = null): Result {
        // --agent-mode keeps stderr for one JSON document of the error; JSON on stdout is asked for by name
        return $this->binary->run(['--agent-mode', '--no-interactive', '--output-format', 'json', ...$command], $dir, $stdin);
    }

    /**
     * The files the CLI left in the folder of the call, not the ones given to it.
     *
     * @return list<string>
     */
    private static function outputs(string $dir): array {
        $paths = [];
        foreach (File\listFiles($dir) as $name) {
            $path = $dir . '/' . $name;
            // the CLI keeps its settings in dot folders of its home, which is this folder
            if ($name !== 'in' && !str_starts_with($name, '.') && File\isFile($path)) {
                $paths[] = $path;
            }
        }
        sort($paths);
        return array_slice($paths, 0, self::FILES_MAX);
    }

    /** What went wrong, in the words of the CLI: its error document names the kind, the message and the hints. */
    private static function failure(Result $result): string {
        if ($result->exit === null) {
            return 'The call took too long and was stopped.';
        }
        $document = self::document($result->stderr);
        $message = self::str($document, 'message') ?? trim($result->stderr);
        $kind = self::str($document, 'error_type') ?? match ($result->exit) {
            2 => 'validation_error',
            3 => 'authentication_error',
            default => 'error',
        };
        $hints = [];
        /** @var mixed $hint */
        foreach (is_array($document['hints'] ?? null) ? $document['hints'] : [] as $hint) {
            if (is_string($hint)) {
                $hints[] = $hint;
            }
        }
        return self::cut(trim(sprintf('%s (exit %d): %s%s', $kind, $result->exit, $message === '' ? 'no message' : $message, $hints === [] ? '' : "\n" . implode("\n", $hints))), 4000);
    }

    /**
     * The last JSON object of a stream, or nothing.
     *
     * @return array<array-key, mixed>
     */
    private static function document(string $stream): array {
        $stream = trim($stream);
        foreach ([$stream, (string) strrchr("\n" . $stream, "\n")] as $candidate) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode(trim($candidate), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Every text of a document of an interaction, in order: the report of an agent is the text of its steps.
     *
     * @param array<array-key, mixed> $node
     */
    private static function texts(array $node, int $depth = 0): string {
        $parts = [];
        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if ($key === 'text' && is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            } elseif (is_array($value) && $depth < 12 && $key !== 'usage') {
                $inner = self::texts($value, $depth + 1);
                if ($inner !== '') {
                    $parts[] = $inner;
                }
            }
        }
        return implode("\n\n", $parts);
    }

    private static function remove(string $dir): void {
        try {
            self::removeTree($dir, 0);
        } catch (FilesystemException) {
            // what could not be removed stays in the temporary folder of the system
        }
    }

    private static function removeTree(string $dir, int $depth): void {
        foreach (File\listFiles($dir) as $name) {
            $path = $dir . '/' . $name;
            if (File\isDirectory($path) && !File\isSymlink($path) && $depth < 8) {
                self::removeTree($path, $depth + 1);
            } else {
                File\deleteFile($path);
            }
        }
        File\deleteDirectory($dir);
    }

    private static function mime(string $name): string {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): ?string {
        /** @psalm-suppress MixedAssignment */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    private static function cut(string $text, int $max): string {
        return strlen($text) > $max ? mb_strcut($text, 0, $max, 'UTF-8') . "\n[cut]" : $text;
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private static function answer(string $text, bool $error): array {
        return ['content' => [['type' => 'text', 'text' => $text === '' ? '(empty)' : $text]], 'isError' => $error];
    }
}
