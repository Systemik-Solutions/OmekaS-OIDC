#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Oidc\Controller\OidcController;

$controllerReflection = new ReflectionClass(OidcController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$createView = $controllerReflection->getMethod('createCallbackView');
$createView->setAccessible(true);
$target = 'https://omeka.example.org/omeka/items';
$view = $createView->invoke($controller, $target);

if (!$view->terminate()) {
    fwrite(STDERR, "OIDC callback view must be terminal.\n");
    exit(1);
}
if ($view->getTemplate() !== 'oidc/oidc/callback') {
    fwrite(STDERR, "OIDC callback view uses the wrong template.\n");
    exit(1);
}
if ($view->getVariable('target') !== $target) {
    fwrite(STDERR, "OIDC callback view lost its redirect target.\n");
    exit(1);
}

$headers = $controller->getResponse()->getHeaders();
foreach ([
    'Cache-Control' => 'no-store',
    'Referrer-Policy' => 'no-referrer',
] as $name => $expected) {
    $header = $headers->get($name);
    if (!$header || $header->getFieldValue() !== $expected) {
        fwrite(STDERR, "OIDC callback response is missing {$name}: {$expected}.\n");
        exit(1);
    }
}

echo "Callback view tests passed.\n";
