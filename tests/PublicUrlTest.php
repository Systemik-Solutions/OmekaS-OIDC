#!/usr/bin/env php
<?php
require dirname(__DIR__) . '/src/Stdlib/PublicUrl.php';

use Oidc\Stdlib\PublicUrl;

$normalizationCases = [
    ['https://OMEKA.example.org/omeka/', 'https://omeka.example.org/omeka'],
    ['https://omeka.example.org:443/omeka', 'https://omeka.example.org/omeka'],
    ['http://omeka.example.org:8080/omeka/', 'http://omeka.example.org:8080/omeka'],
    ['https://[2001:db8::1]/omeka', 'https://[2001:db8::1]/omeka'],
    ['https://user@omeka.example.org/omeka', null],
    ['https://omeka.example.org/omeka?next=evil', null],
    ['https://omeka.example.org/omeka#fragment', null],
    ['https://evil.example.org\\@omeka.example.org/', null],
    ["https://omeka.example.org/\npath", null],
    ['//omeka.example.org/omeka', null],
    ['javascript://omeka.example.org/omeka', null],
];

foreach ($normalizationCases as [$input, $expected]) {
    $actual = PublicUrl::normalize($input);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Unexpected base URL normalization for %s\nExpected: %s\nActual: %s\n",
            var_export($input, true),
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

if (PublicUrl::appendPath('https://omeka.example.org/omeka/', '/oidc/callback')
    !== 'https://omeka.example.org/omeka/oidc/callback'
) {
    fwrite(STDERR, "Failed to build the callback URL from the pinned base URL.\n");
    exit(1);
}

foreach (['//evil.example.org/', '/\\evil', "/items\nLocation: https://evil.example.org/"] as $path) {
    if (PublicUrl::appendPath('https://omeka.example.org/omeka', $path) !== null) {
        fwrite(STDERR, sprintf("Expected unsafe appended path: %s\n", var_export($path, true)));
        exit(1);
    }
}

echo "Public URL tests passed.\n";
