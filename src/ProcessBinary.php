<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\Future;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;

use function Amp\async;

/**
 * Runs the CLI as a child process. The child gets a short environment of its own — the key, a home inside the folder
 * of the run and the search path — so nothing else of the server's environment reaches it. At the timeout the child
 * is killed.
 */
final class ProcessBinary implements Binary {
    /** The answers of the CLI are small documents; a longer stream is cut. */
    private const STREAM_MAX = 2 * 1024 * 1024;

    public function __construct(private readonly Options $options) {}

    public function run(array $args, string $dir, ?string $stdin = null): Result {
        $command = $this->options->command;
        if ($command === [] || (count($command) === 1 && !is_file($command[0]))) {
            throw new BinaryFailure('The gemini-api binary is not installed: the install step did not fetch it');
        }
        $path = getenv('PATH');
        $env = [
            'GEMINI_API_KEY' => $this->options->key,
            'HOME' => $dir,
            'XDG_CONFIG_HOME' => $dir,
            'PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin',
            'NO_COLOR' => '1',
        ];
        // the way out of the CLI is the one the server found at its start
        $env += $this->options->proxy;
        $root = getenv('SYSTEMROOT');
        if (is_string($root) && $root !== '') {
            $env['SYSTEMROOT'] = $root;
        }
        try {
            $process = Process::start([...$command, ...$args], $dir, $env);
        } catch (ProcessException $e) {
            throw new BinaryFailure('The gemini-api binary did not start: ' . $e->getMessage());
        }
        $out = self::reader($process->getStdout());
        $err = self::reader($process->getStderr());
        try {
            if ($stdin !== null) {
                $process->getStdin()->write($stdin);
            }
            $process->getStdin()->end();
        } catch (\Throwable) {
            // the child closed its stdin: it tells why on stderr
        }
        try {
            $exit = $process->join(new TimeoutCancellation((float) $this->options->timeout));
        } catch (CancelledException) {
            $process->kill();
            $exit = null;
        }
        return new Result($exit, self::settled($out), self::settled($err));
    }

    /**
     * What a stream held; a stream a killed child's helper keeps open is not waited for.
     *
     * @param Future<string> $stream
     */
    private static function settled(Future $stream): string {
        try {
            return $stream->await(new TimeoutCancellation(5.0));
        } catch (CancelledException) {
            return '';
        }
    }

    /** @return Future<string> */
    private static function reader(ReadableStream $stream): Future {
        return async(static fn(): string => self::drain($stream));
    }

    /** Reads a stream to its end and keeps its first bytes. */
    private static function drain(ReadableStream $stream): string {
        $kept = '';
        try {
            while (($chunk = $stream->read()) !== null) {
                if (strlen($kept) < self::STREAM_MAX) {
                    $kept .= substr($chunk, 0, self::STREAM_MAX - strlen($kept));
                }
            }
        } catch (\Throwable) {
            // the stream went with the child
        }
        return $kept;
    }
}
