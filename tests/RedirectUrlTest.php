#!/usr/bin/env php
<?php
require dirname(__DIR__) . '/src/Stdlib/RedirectUrl.php';

use Oidc\Stdlib\RedirectUrl;

$origin = ['https', 'omeka.example.org', 443, '/omeka'];
$cases = [
    ['/items?x=1#details', 'https://omeka.example.org/omeka/items?x=1#details'],
    ['https://OMEKA.example.org/items?x=1#details', 'https://omeka.example.org/items?x=1#details'],
    ['https://omeka.example.org:443/', 'https://omeka.example.org/'],
    ['javascript://omeka.example.org/%0Aalert(document.domain)', null],
    ['https://evil.example.org\\@omeka.example.org/', null],
    ['https://user@omeka.example.org/', null],
    ['http://omeka.example.org/', null],
    ['https://omeka.example.org:444/', null],
    ['https://evil.example.org/', null],
    ['//evil.example.org/', null],
    ["https://omeka.example.org/\npath", null],
];

foreach ($cases as [$input, $expected]) {
    $actual = RedirectUrl::normalizeForOrigin($input, ...$origin);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Failed for %s\nExpected: %s\nActual: %s\n",
            var_export($input, true),
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

foreach (['', 'home', '/items', 'https://omeka.example.org/items'] as $input) {
    if (!RedirectUrl::hasSafeSyntax($input)) {
        fwrite(STDERR, sprintf("Expected safe configuration syntax: %s\n", var_export($input, true)));
        exit(1);
    }
}

foreach ([
    'javascript://omeka.example.org/alert(1)',
    '//evil.example.org/',
    'https://user@omeka.example.org/',
    'https://evil.example.org\\@omeka.example.org/',
    "/items\r\nLocation: https://evil.example.org/",
] as $input) {
    if (RedirectUrl::hasSafeSyntax($input)) {
        fwrite(STDERR, sprintf("Expected unsafe configuration syntax: %s\n", var_export($input, true)));
        exit(1);
    }
}

echo "RedirectUrl tests passed.\n";
