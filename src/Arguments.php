<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

/**
 * The arguments of a tool call: any JSON, written by a model. Each value is checked where it is read, and a value that
 * becomes an argument of the CLI can never start a flag of its own.
 */
final class Arguments {
    /** @param array<array-key, mixed> $args */
    public function __construct(private readonly array $args) {}

    /** A needed text. */
    public function text(string $key, int $max): string {
        $value = $this->optional($key);
        if ($value === null) {
            throw new \InvalidArgumentException($key . ': a text is needed');
        }
        if (strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('%s: longer than %d bytes', $key, $max));
        }
        return $value;
    }

    public function optional(string $key): ?string {
        /** @psalm-suppress MixedAssignment */
        $value = $this->args[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function flag(string $key): ?bool {
        /** @psalm-suppress MixedAssignment */
        $value = $this->args[$key] ?? null;
        return is_bool($value) ? $value : null;
    }

    /** An id of an operation: letters, digits, `_` and `-`. */
    public function id(string $key): string {
        $value = $this->optional($key);
        if ($value === null || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_-]{3,511}$~', $value) !== 1) {
            throw new \InvalidArgumentException($key . ': an id returned by the start tool is needed');
        }
        return $value;
    }

    /**
     * One of the listed values; the first one when nothing is given.
     *
     * @param non-empty-list<string> $allowed
     */
    public function oneOf(string $key, array $allowed): string {
        $value = $this->optional($key);
        if ($value === null) {
            return $allowed[0];
        }
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('%s: one of %s', $key, implode(', ', $allowed)));
        }
        return $value;
    }

    /**
     * The given short values as flags of the CLI. A value is a word of letters, digits and `. : _ -` that does not
     * start with a dash, or (for a style) a line of text: it goes as one argument right after its flag.
     *
     * @param array<string, string> $map argument => flag
     * @return list<string>
     */
    public function flags(array $map): array {
        $out = [];
        foreach ($map as $key => $flag) {
            $value = $this->optional($key);
            if ($value === null) {
                continue;
            }
            $plain = $key === 'style' ? preg_match('~^[^\x00-\x1f-][^\x00-\x1f]{0,499}$~u', $value) : preg_match('~^[A-Za-z0-9][A-Za-z0-9.:_-]{0,63}$~', $value);
            if ($plain !== 1) {
                throw new \InvalidArgumentException($key . ': not a value this tool takes');
            }
            $out[] = $flag . '=' . $value;
        }
        return $out;
    }

    /**
     * @return list<array{name: string, content_base64: string}>
     */
    public function files(int $max): array {
        /** @psalm-suppress MixedAssignment */
        $files = $this->args['files'] ?? null;
        $out = [];
        /** @var mixed $file */
        foreach (is_array($files) ? $files : [] as $file) {
            /** @psalm-suppress MixedAssignment */
            $name = is_array($file) ? ($file['name'] ?? null) : null;
            /** @psalm-suppress MixedAssignment */
            $content = is_array($file) ? ($file['content_base64'] ?? null) : null;
            if (!is_string($name) || !is_string($content)) {
                throw new \InvalidArgumentException('files: each file is {name, content_base64}');
            }
            $out[] = ['name' => $name, 'content_base64' => $content];
        }
        if (count($out) > $max) {
            throw new \InvalidArgumentException(sprintf('files: up to %d files in one call', $max));
        }
        return $out;
    }
}
