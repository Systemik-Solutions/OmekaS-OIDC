#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Oidc\Controller\OidcController;
use Omeka\Entity\User;

final class EmailOwnerRepository
{
    public $owner;
    public $lookups = [];

    public function findOneBy(array $criteria)
    {
        $this->lookups[] = $criteria;
        return $this->owner;
    }
}

final class EmailOwnerEntityManager
{
    private $repository;

    public function __construct(EmailOwnerRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getRepository(string $entityClass): EmailOwnerRepository
    {
        if ($entityClass !== User::class) {
            throw new RuntimeException("Unexpected repository: {$entityClass}");
        }
        return $this->repository;
    }
}

function userWithId(int $id): User
{
    $user = new User();
    $idProperty = new ReflectionProperty(User::class, 'id');
    $idProperty->setAccessible(true);
    $idProperty->setValue($user, $id);
    return $user;
}

$repository = new EmailOwnerRepository();
$controllerReflection = new ReflectionClass(OidcController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$entityManager = $controllerReflection->getProperty('entityManager');
$entityManager->setAccessible(true);
$entityManager->setValue($controller, new EmailOwnerEntityManager($repository));
$collisionCheck = $controllerReflection->getMethod('claimedEmailBelongsToAnotherUser');
$collisionCheck->setAccessible(true);

$owner = userWithId(10);
$repository->owner = $owner;

$cases = [
    [['email' => 'person@example.org'], null, true, 'new OIDC user colliding with a local account'],
    [['email' => 'person@example.org'], userWithId(10), false, 'existing OIDC user retaining its own email'],
    [['email' => 'person@example.org'], userWithId(11), true, 'existing OIDC user changing to another account email'],
];

foreach ($cases as [$claims, $user, $expected, $description]) {
    $actual = $collisionCheck->invoke($controller, $claims, $user);
    if ($actual !== $expected) {
        fwrite(STDERR, "Unexpected collision result for {$description}.\n");
        exit(1);
    }
}

$repository->owner = null;
if ($collisionCheck->invoke($controller, ['email' => 'unused@example.org'], null) !== false) {
    fwrite(STDERR, "An unused email address was reported as a collision.\n");
    exit(1);
}

$lookupCount = count($repository->lookups);
foreach ([[], ['email' => ''], ['email' => ['invalid']]] as $claims) {
    if ($collisionCheck->invoke($controller, $claims, null) !== false) {
        fwrite(STDERR, "A missing or invalid email claim was reported as a collision.\n");
        exit(1);
    }
}
if (count($repository->lookups) !== $lookupCount) {
    fwrite(STDERR, "Missing or invalid email claims should not query the user repository.\n");
    exit(1);
}

echo "Email collision tests passed.\n";
