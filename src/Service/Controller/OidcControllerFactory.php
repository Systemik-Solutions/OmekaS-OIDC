<?php
namespace Oidc\Service\Controller;

use Facile\OpenIDClient\Client\ClientInterface;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;
use Oidc\Controller\OidcController;

class OidcControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $client = null;
        try {
            $candidate = $container->get('Oidc\Client');
            if ($candidate instanceof ClientInterface) {
                $client = $candidate;
            }
        } catch (\Throwable $e) {
            $client = null;
        }

        $sessionManager = SessionContainer::getDefaultManager();
        if (!$sessionManager instanceof SessionManager) {
            $sessionManager = new SessionManager();
            SessionContainer::setDefaultManager($sessionManager);
        }

        return new OidcController(
            $client,
            $container->get('Omeka\AuthenticationService'),
            $container->get('Omeka\EntityManager'),
            $container->get('Omeka\Settings'),
            $container->get('Omeka\Settings\User'),
            $sessionManager
        );
    }
}
