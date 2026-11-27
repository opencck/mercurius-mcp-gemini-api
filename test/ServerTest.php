<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi\Test;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\McpGeminiApi\BinaryFailure;
use Mercurius\McpGeminiApi\Options;
use Mercurius\McpGeminiApi\ProcessBinary;
use Mercurius\McpGeminiApi\Result;
use Mercurius\McpGeminiApi\Route;
use Mercurius\McpGeminiApi\Server;

final class ServerTest extends AsyncTestCase {
    private const GLOBAL = ['--agent-mode', '--no-interactive', '--output-format', 'json'];

    /**
     * @param array<string, string> $files
     * @return array{Server, FakeBinary}
     */
    private static function server(?Result $result = null, array $files = [], string $key = 'AIza-secret'): array {
        $binary = new FakeBinary($result ?? new Result(0, '{}', ''), $files);
        return [new Server($binary, new Options($key, ['gemini-api'])), $binary];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<array-key, mixed>
     */
    private static function call(Server $server, string $tool, array $args): array {
        $reply = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], JSON_THROW_ON_ERROR));
        self::assertIsArray($reply);
        $result = $reply['result'] ?? null;
        self::assertIsArray($result);
        return $result;
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<array-key, mixed>
     */
    private static function part(array $result, int $index): array {
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $part = $content[$index] ?? null;
        self::assertIsArray($part);
        return $part;
    }

    /** @param array<array-key, mixed> $result */
    private static function text(array $result): string {
        $text = self::part($result, 0)['text'] ?? null;
        self::assertIsString($text);
        return $text;
    }

    public function testTheToolsSayWhichCallsAreCheapAndWhichAreAsked(): void {
        $hints = [];
        foreach (Server::tools() as $tool) {
            self::assertIsString($tool['name']);
            $encoded = json_encode($tool['annotations'] ?? [], JSON_THROW_ON_ERROR);
            $hints[$tool['name']] = match ($encoded) {
                '{"readOnlyHint":true}' => 'read',
                '{"destructiveHint":false}' => 'write',
                '[]' => 'asked',
                default => $encoded,
            };
        }

        self::assertSame([
            'image' => 'write', 'speech' => 'write', 'music' => 'asked', 'video_start' => 'asked', 'video_result' => 'read',
            'transcribe' => 'read', 'analyze' => 'read', 'research_start' => 'asked', 'research_status' => 'read',
        ], $hints, 'music, video and a research agent are asked for: the owner decided so');
    }

    public function testAnImageComesBackAsAFileAndTheFolderOfTheCallIsRemoved(): void {
        [$server, $binary] = self::server(null, ['gemini-image-1.png' => 'PNG-BYTES']);

        $result = self::call($server, 'image', ['prompt' => '--out=/etc/passwd a lighthouse', 'aspect_ratio' => '16:9', 'size' => '2K']);

        self::assertFalse($result['isError']);
        self::assertSame([...self::GLOBAL, 'image', '--aspect-ratio=16:9', '--image-size=2K', '--', '--out=/etc/passwd a lighthouse'], $binary->runs[0], 'the prompt comes after "--": it cannot be a flag');
        self::assertSame('gemini-image-1.png (1 KB)', self::text($result));
        $resource = self::part($result, 1)['resource'] ?? null;
        self::assertIsArray($resource);
        self::assertSame('image/png', $resource['mimeType']);
        self::assertSame('gemini-image-1.png', $resource['name']);
        self::assertSame(base64_encode('PNG-BYTES'), $resource['blob']);
        self::assertIsString($binary->dir);
        self::assertDirectoryDoesNotExist($binary->dir);
    }

    public function testSpeechReadsTheTextFromStdin(): void {
        [$server, $binary] = self::server(null, ['gemini-tts-1.wav' => 'RIFF']);

        $result = self::call($server, 'speech', ['text' => '-v evil', 'voice' => 'Puck', 'style' => 'whispered urgently']);

        self::assertFalse($result['isError']);
        self::assertSame([...self::GLOBAL, 'tts', '--stdin', '--voice=Puck', '--style=whispered urgently'], $binary->runs[0]);
        self::assertSame('-v evil', $binary->stdins[0]);
    }

    public function testAValueThatCouldBeAFlagIsRefusedBeforeTheBinaryRuns(): void {
        [$server, $binary] = self::server();

        foreach ([['image', ['prompt' => 'x', 'model' => '--api-key=stolen']], ['speech', ['text' => 'x', 'style' => '--out=/tmp/x']], ['video_result', ['id' => '--help']], ['research_start', ['input' => 'x', 'agent' => 'antigravity-preview-09-2026']]] as [$tool, $args]) {
            $result = self::call($server, $tool, $args);
            self::assertTrue($result['isError'], $tool);
        }
        self::assertSame([], $binary->runs);
    }

    public function testAVideoIsStartedAndFetchedLater(): void {
        [$server, $binary] = self::server(new Result(0, '{"id":"v1_Chd-abc_123","status":"in_progress"}', ''));

        $started = self::call($server, 'video_start', ['prompt' => 'a cat', 'resolution' => '720p']);

        self::assertFalse($started['isError']);
        self::assertSame([...self::GLOBAL, 'video', '--async', '--resolution=720p', '--', 'a cat'], $binary->runs[0]);
        self::assertStringContainsString('id: v1_Chd-abc_123', self::text($started));
        self::assertStringContainsString('video_result', self::text($started));

        [$server, $binary] = self::server(null, ['gemini-video-1.mp4' => 'MP4']);
        $fetched = self::call($server, 'video_result', ['id' => 'v1_Chd-abc_123']);

        self::assertSame([...self::GLOBAL, 'video', '--resume', 'v1_Chd-abc_123', '--poll-timeout', '60s'], $binary->runs[0]);
        $resource = self::part($fetched, 1)['resource'] ?? null;
        self::assertIsArray($resource);
        self::assertSame('video/mp4', $resource['mimeType']);
    }

    public function testFilesOfACallAreWrittenUnderSafeNamesAndTheTranscriptIsText(): void {
        [$server, $binary] = self::server(null, ['transcript-1.md' => "**A:** hello\n"]);

        $result = self::call($server, 'transcribe', ['files' => [['name' => '../../etc/talk one.mp3', 'content_base64' => base64_encode('MP3')]], 'speakers' => false]);

        self::assertFalse($result['isError']);
        self::assertSame("**A:** hello\n", self::text($result));
        self::assertSame(['0-talk_one.mp3' => 'MP3'], $binary->given[0]);
        $run = $binary->runs[0];
        self::assertSame([...self::GLOBAL, 'transcribe', '--format', 'md', '--no-speakers', '--input'], array_slice($run, 0, 9));
        self::assertStringEndsWith('/in/0-talk_one.mp3', $run[9]);
    }

    public function testAnalyzeTakesFilesOrOneYouTubeVideo(): void {
        [$server, $binary] = self::server(new Result(0, '{"model":"gemini-4-hydrogen","text":"A cat sleeps."}', ''));

        $result = self::call($server, 'analyze', ['question' => 'What happens?', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        self::assertSame('A cat sleeps.', self::text($result));
        self::assertSame([...self::GLOBAL, 'analyze', '--input', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', '--', 'What happens?'], $binary->runs[0]);
        self::assertTrue(self::call($server, 'analyze', ['youtube_url' => 'https://evil.example/watch?v=dQw4w9WgXcQ'])['isError']);
        self::assertTrue(self::call($server, 'analyze', ['youtube_url' => '--input=/etc/passwd'])['isError']);
        self::assertTrue(self::call($server, 'analyze', ['question' => 'nothing given'])['isError']);
        self::assertCount(1, $binary->runs);
    }

    public function testAResearchRunIsStartedInTheBackgroundAndItsReportIsRead(): void {
        [$server, $binary] = self::server(new Result(0, '{"id":"v1_run","status":"queued"}', ''));
        $started = self::call($server, 'research_start', ['input' => 'state of fusion']);

        self::assertSame([...self::GLOBAL, 'agent', 'run', '--background', '--agent', 'deep-research-preview-04-2026', '--', 'state of fusion'], $binary->runs[0]);
        self::assertStringContainsString('id: v1_run', self::text($started));

        [$server, $binary] = self::server(new Result(0, '{"id":"v1_run","status":"completed","steps":[{"content":[{"type":"text","text":"Part one."}]},{"content":[{"type":"text","text":"Part two."}]}],"usage":{"text":"not a report"}}', ''));
        $status = self::call($server, 'research_status', ['id' => 'v1_run']);

        self::assertSame([...self::GLOBAL, 'agent', 'status', 'v1_run'], $binary->runs[0]);
        self::assertSame("status: completed\nPart one.\n\nPart two.", self::text($status));
        self::assertFalse($status['isError']);
    }

    public function testAnErrorOfTheBinaryIsToldInItsWords(): void {
        [$server] = self::server(new Result(3, '', '{"error_type":"authentication_error","exit_code":3,"message":"API key not valid","hints":["Check GEMINI_API_KEY"]}'));

        $result = self::call($server, 'image', ['prompt' => 'x']);

        self::assertTrue($result['isError']);
        self::assertSame("authentication_error (exit 3): API key not valid\nCheck GEMINI_API_KEY", self::text($result));
    }

    public function testACallWithoutAFileAStoppedCallAndALargeFileAreErrorsOrSaidSo(): void {
        [$server] = self::server(new Result(0, '{"status":"incomplete"}', ''));
        self::assertTrue(self::call($server, 'music', ['prompt' => 'x'])['isError']);

        [$server] = self::server(new Result(null, '', ''));
        self::assertSame('The call took too long and was stopped.', self::text(self::call($server, 'music', ['prompt' => 'x'])));

        [$server] = self::server(null, ['gemini-video-1.mp4' => str_repeat('x', Server::FILE_MAX + 1)]);
        $large = self::call($server, 'video_result', ['id' => 'v1_abc']);
        self::assertStringContainsString('not returned', self::text($large));
        self::assertCount(1, (array) $large['content'], 'no bytes of a file too large for an answer');
    }

    public function testWithoutAKeyEveryCallSaysWhatIsMissing(): void {
        [$server, $binary] = self::server(null, [], '');

        $result = self::call($server, 'image', ['prompt' => 'x']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('GEMINI_API_KEY', self::text($result));
        self::assertSame([], $binary->runs);
        $list = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        self::assertIsArray($list);
        self::assertIsArray($list['result']);
        self::assertCount(9, (array) $list['result']['tools'], 'the trial start of an install lists the tools without a key');
    }

    public function testTheProcessGetsTheKeyAHomeOfItsOwnAndNothingElse(): void {
        putenv('SERVER_ONLY_SECRET=must-not-leak');
        $dir = sys_get_temp_dir() . '/gemini-api-mcp-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $binary = new ProcessBinary(new Options('AIza-secret', [PHP_BINARY, __DIR__ . '/fixtures/fake-gemini-api.php'], 20));

            $result = $binary->run(['tts', '--stdin'], $dir, 'hello');
            $failed = $binary->run(['fail'], $dir);
        } finally {
            putenv('SERVER_ONLY_SECRET');
            // on Windows the folder may still be held by the child that just ended
            @rmdir($dir);
        }

        self::assertSame(0, $result->exit);
        $seen = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($seen);
        self::assertSame(['tts', '--stdin'], $seen['args']);
        self::assertSame('AIza-secret', $seen['key']);
        self::assertSame('hello', $seen['stdin']);
        self::assertFalse($seen['leak'], 'the environment of the server does not reach the child');
        self::assertIsString($seen['cwd']);
        self::assertSame(realpath($dir), realpath($seen['cwd']));
        self::assertSame(3, $failed->exit);
        self::assertStringContainsString('API key not valid', $failed->stderr);
    }

    public function testACallThatTakesTooLongIsKilled(): void {
        $dir = sys_get_temp_dir() . '/gemini-api-mcp-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $started = microtime(true);
            $result = (new ProcessBinary(new Options('k', [PHP_BINARY, __DIR__ . '/fixtures/fake-gemini-api.php'], 1)))->run(['sleep'], $dir);
        } finally {
            // on Windows the folder may still be held by the child that just ended
            @rmdir($dir);
        }

        self::assertNull($result->exit);
        self::assertLessThan(10.0, microtime(true) - $started);
    }

    public function testAMissingBinaryIsNamed(): void {
        $this->expectException(BinaryFailure::class);
        $this->expectExceptionMessage('not installed');
        (new ProcessBinary(new Options('k', ['/nonexistent/gemini-api'])))->run(['image'], sys_get_temp_dir());
    }

    public function testTheWayOutIsTheDoorOfTheInstallationAProxyOrDirect(): void {
        $open = static fn(string $proxy): bool => true;
        $closed = static fn(string $proxy): bool => false;

        self::assertSame(Route::DOOR, Route::of([], $open)['HTTPS_PROXY'] ?? null, 'the door of the installation, when it listens');
        self::assertSame(Route::DOOR, Route::of([], $open)['HTTP_PROXY'] ?? null);
        self::assertSame([], Route::of([], $closed), 'no door — directly');
        self::assertSame('http://p:3128', Route::of(['HTTPS_PROXY' => 'http://p:3128'], $open)['HTTPS_PROXY'] ?? null, 'a proxy of the entry comes before the door');
        self::assertSame('socks5h://own:1080', Route::of(['GEMINI_API_PROXY' => 'socks5h://own:1080', 'HTTPS_PROXY' => 'http://p:3128'], $closed)['HTTPS_PROXY'] ?? null);
        self::assertSame([], Route::of(['GEMINI_API_PROXY' => 'Direct', 'HTTPS_PROXY' => 'http://p:3128'], $open), 'the word direct keeps the CLI off every proxy');
        self::assertSame([], Route::of(['GEMINI_API_PROXY' => 'direct']), 'and nothing is tried');
    }

    public function testTheWayOutReachesTheChild(): void {
        $dir = sys_get_temp_dir() . '/gemini-api-mcp-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $binary = new ProcessBinary(new Options('k', [PHP_BINARY, __DIR__ . '/fixtures/fake-gemini-api.php'], 20, ['HTTPS_PROXY' => Route::DOOR]));
            $seen = json_decode($binary->run(['tts', '--stdin'], $dir, 'hi')->stdout, true, 8, JSON_THROW_ON_ERROR);
        } finally {
            @rmdir($dir);
        }

        self::assertIsArray($seen);
        self::assertSame(Route::DOOR, $seen['proxy']);
    }

    public function testOptionsComeFromTheEnvironment(): void {
        $options = Options::from(['GEMINI_API_KEY' => ' AIza ', 'GEMINI_API_BIN' => 'php  /x/fake.php', 'GEMINI_API_TIMEOUT' => '5000'], '/install');
        $plain = Options::from([], '/install');

        self::assertSame('AIza', $options->key);
        self::assertSame(['php', '/x/fake.php'], $options->command);
        self::assertSame(Options::TIMEOUT_MAX, $options->timeout);
        self::assertSame(['/install/vendor/gemini-api/gemini-api'], $plain->command);
        self::assertFalse($plain->configured());
    }
}
