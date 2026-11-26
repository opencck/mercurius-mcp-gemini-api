<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

/** One run of the gemini-api CLI. */
interface Binary {
    /**
     * @param list<string> $args the command of the CLI and its flags
     * @param string $dir the folder the run works in
     * @param string|null $stdin what the command reads, when it reads
     * @throws BinaryFailure when the binary cannot be started
     */
    public function run(array $args, string $dir, ?string $stdin = null): Result;
}
