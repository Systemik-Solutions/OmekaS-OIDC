#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Oidc\Controller\OidcController;
use Oidc\Settings as S;
use Omeka\Settings\Settings;

final class PinnedUrlSettings extends Settings
{
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($id, $default = null)
    {
        return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
    }
}

$controllerReflection = new ReflectionClass(OidcController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$settings = $controllerReflection->getProperty('settings');
$settings->setAccessible(true);
$settings->setValue($controller, new PinnedUrlSettings([
    S::BASE_URL => 'https://public.example.org/omeka',
]));

$validate = $controllerReflection->getMethod('validateLocalUrl');
$validate->setAccessible(true);
$absolute = $controllerReflection->getMethod('absoluteUrl');
$absolute->setAccessible(true);

$cases = [
    ['/items?query=test', 'https://public.example.org/omeka/items?query=test'],
    ['https://public.example.org/items/1', 'https://public.example.org/items/1'],
    ['https://attacker.example/items/1', null],
    ['http://public.example.org/items/1', null],
];
foreach ($cases as [$input, $expected]) {
    $actual = $validate->invoke($controller, $input);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Unexpected pinned-origin redirect result for %s\nExpected: %s\nActual: %s\n",
            var_export($input, true),
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

if ($absolute->invoke($controller, '/') !== 'https://public.example.org/omeka/') {
    fwrite(STDERR, "Absolute URL was not built from the pinned public base URL.\n");
    exit(1);
}

echo "Pinned base URL tests passed.\n";
