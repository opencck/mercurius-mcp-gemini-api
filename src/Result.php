<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

/** What one run of the binary left: its exit code (null — killed at the timeout) and its two streams, cut at a limit. */
final class Result {
    public function __construct(
        public readonly ?int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}
}
