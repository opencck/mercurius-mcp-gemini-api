<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi\Test;

use Mercurius\McpGeminiApi\Binary;
use Mercurius\McpGeminiApi\Result;

/** Stands in for the CLI: remembers the run, leaves the given files in its folder and answers as told. */
final class FakeBinary implements Binary {
    /** @var list<list<string>> */
    public array $runs = [];
    /** @var list<?string> */
    public array $stdins = [];
    /** @var list<array<string, string>> the files given to each run: name => content */
    public array $given = [];
    public ?string $dir = null;

    /** @param array<string, string> $files name => content, left in the folder of the run */
    public function __construct(private readonly Result $result, private readonly array $files = []) {}

    public function run(array $args, string $dir, ?string $stdin = null): Result {
        $this->runs[] = $args;
        $this->stdins[] = $stdin;
        $this->dir = $dir;
        $given = [];
        $paths = glob($dir . '/in/*');
        foreach ($paths === false ? [] : $paths as $path) {
            $given[basename($path)] = (string) file_get_contents($path);
        }
        $this->given[] = $given;
        foreach ($this->files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
        return $this->result;
    }
}
