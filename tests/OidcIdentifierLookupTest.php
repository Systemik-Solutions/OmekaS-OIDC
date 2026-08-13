#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Oidc\Controller\OidcController;

final class RecordingConnection
{
    public $sql;
    public $params;

    public function fetchOne(string $sql, array $params)
    {
        $this->sql = $sql;
        $this->params = $params;
        return false;
    }
}

final class RecordingEntityManager
{
    private $connection;

    public function __construct(RecordingConnection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): RecordingConnection
    {
        return $this->connection;
    }
}

$connection = new RecordingConnection();
$controllerReflection = new ReflectionClass(OidcController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();

$entityManager = $controllerReflection->getProperty('entityManager');
$entityManager->setAccessible(true);
$entityManager->setValue($controller, new RecordingEntityManager($connection));

$lookup = $controllerReflection->getMethod('findUserBySubIss');
$lookup->setAccessible(true);
$sub = '00u1abcDEF ';
$iss = 'https://IdP.example/Issuer';
$result = $lookup->invoke($controller, $sub, $iss);

if ($result !== null) {
    fwrite(STDERR, "Expected a missing test user.\n");
    exit(1);
}

foreach ([
    'BINARY s_sub.value = :sub_val',
    'BINARY s_iss.value = :iss_val',
] as $requiredSql) {
    if (!is_string($connection->sql) || !str_contains($connection->sql, $requiredSql)) {
        fwrite(STDERR, "OIDC identifier lookup is missing binary comparison: {$requiredSql}\n");
        exit(1);
    }
}

if (($connection->params['sub_val'] ?? null) !== json_encode($sub)
    || ($connection->params['iss_val'] ?? null) !== json_encode($iss)
) {
    fwrite(STDERR, "OIDC identifiers were altered before lookup.\n");
    exit(1);
}

echo "OIDC identifier lookup tests passed.\n";
