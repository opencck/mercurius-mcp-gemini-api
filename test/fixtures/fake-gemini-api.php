<?php

declare(strict_types=1);

// Stands in for the gemini-api binary in the test of the process runner: writes what it was started with.
$args = array_slice($argv, 1);
if (in_array('sleep', $args, true)) {
    sleep(30);
}
if (in_array('fail', $args, true)) {
    fwrite(STDERR, json_encode(['error_type' => 'authentication_error', 'message' => 'API key not valid', 'hints' => ['Check GEMINI_API_KEY']], JSON_THROW_ON_ERROR) . "\n");
    exit(3);
}
echo json_encode([
    'args' => $args,
    'key' => getenv('GEMINI_API_KEY'),
    'home' => getenv('HOME'),
    'leak' => getenv('SERVER_ONLY_SECRET'),
    'cwd' => getcwd(),
    'stdin' => stream_get_contents(STDIN),
], JSON_THROW_ON_ERROR), "\n";
