<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

/**
 * What the server is started with: the key and the binary. The key comes from the environment of the process, never
 * from arguments: arguments are seen in the list of processes.
 */
final class Options {
    public const TIMEOUT_DEFAULT = 100;
    public const TIMEOUT_MAX = 900;

    /**
     * @param list<string> $command the binary and the arguments before the command of the CLI
     * @param positive-int $timeout seconds one call of the binary may take
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $key,
        public readonly array $command,
        public readonly int $timeout = self::TIMEOUT_DEFAULT,
    ) {}

    /**
     * @param array<string, string> $env
     * @param string $root the folder of the install
     */
    public static function from(#[\SensitiveParameter] array $env, string $root): self {
        $own = trim($env['GEMINI_API_BIN'] ?? '');
        $command = $own === '' ? [$root . '/vendor/gemini-api/gemini-api'] : array_values(array_filter(explode(' ', $own), static fn(string $p): bool => $p !== ''));
        $timeout = (int) ($env['GEMINI_API_TIMEOUT'] ?? self::TIMEOUT_DEFAULT);
        return new self(trim($env['GEMINI_API_KEY'] ?? ''), $command, max(1, min(self::TIMEOUT_MAX, $timeout)));
    }

    /** Whether the entry carries a key: without one the server starts and tells each call what is missing. */
    public function configured(): bool {
        return $this->key !== '';
    }
}
