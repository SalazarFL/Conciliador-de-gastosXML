<?php

require_once __DIR__ . '/../app/core/App.php';

function assertAppBaseUri(string $expected, string $scriptName): void
{
    $previous = $_SERVER['SCRIPT_NAME'] ?? null;
    $_SERVER['SCRIPT_NAME'] = $scriptName;

    try {
        $reflection = new ReflectionClass(App::class);
        $app = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('detectBaseUri');
        $method->setAccessible(true);
        $actual = $method->invoke($app);
    } finally {
        if ($previous === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $previous;
        }
    }

    if ($actual !== $expected) {
        fwrite(
            STDERR,
            "FAIL: SCRIPT_NAME {$scriptName} esperaba {$expected}; obtuvo {$actual}.\n"
        );
        exit(1);
    }
}

assertAppBaseUri('/', '/index.php');
assertAppBaseUri('/', '\\index.php');
assertAppBaseUri('/xmlconcilia/public', '/xmlconcilia/public/index.php');
