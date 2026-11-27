<?php

declare(strict_types=1);

namespace Mercurius\McpGeminiApi;

use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;

use function Amp\Socket\connect;

/**
 * The way out of the CLI. GEMINI_API_PROXY names it: a proxy address, the word direct, or nothing. With nothing, a
 * proxy the entry was given (HTTPS_PROXY, HTTP_PROXY) serves; without one the door of a Mercurius installation is
 * tried — it listens when the installation has a VLESS route or a proxy of its own and leads there — and when the
 * door does not open the CLI goes directly.
 */
final class Route {
    /** The door of a Mercurius installation as the sandbox sees it. */
    public const DOOR = 'http://vless-proxy:8080';
    private const LOCAL = 'localhost,127.0.0.1,::1';
    private const PROBE_SECONDS = 2.0;

    /**
     * @param array<string, string> $env the environment of the server
     * @param (\Closure(string): bool)|null $opens whether a proxy address takes a connection; null — a real try
     * @return array<string, string> the proxy variables of the child; empty — directly
     */
    public static function of(array $env, ?\Closure $opens = null): array {
        $own = trim($env['GEMINI_API_PROXY'] ?? '');
        if (strtolower($own) === 'direct') {
            return [];
        }
        if ($own !== '' && strtolower($own) !== 'auto') {
            return self::through($own, $env);
        }
        $given = [];
        foreach (['HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY'] as $name) {
            $value = trim($env[$name] ?? '');
            if ($value !== '') {
                $given[$name] = $value;
            }
        }
        if (isset($given['HTTPS_PROXY']) || isset($given['HTTP_PROXY'])) {
            return $given;
        }
        $opens ??= self::opens(...);
        return $opens(self::DOOR) ? self::through(self::DOOR, $env) : [];
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private static function through(string $proxy, array $env): array {
        $local = trim($env['NO_PROXY'] ?? '');
        return ['HTTPS_PROXY' => $proxy, 'HTTP_PROXY' => $proxy, 'NO_PROXY' => $local === '' ? self::LOCAL : $local];
    }

    /** Whether something listens at the address of a proxy: a name that does not resolve or a closed port is a no. */
    private static function opens(string $proxy): bool {
        $host = parse_url($proxy, PHP_URL_HOST);
        $port = parse_url($proxy, PHP_URL_PORT);
        if (!is_string($host) || $host === '' || !is_int($port)) {
            return false;
        }
        try {
            connect('tcp://' . $host . ':' . $port, (new ConnectContext())->withConnectTimeout(self::PROBE_SECONDS), new TimeoutCancellation(self::PROBE_SECONDS * 2.0))->close();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
