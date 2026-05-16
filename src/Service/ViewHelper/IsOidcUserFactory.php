<?php
namespace Oidc\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Oidc\View\Helper\IsOidcUser;

class IsOidcUserFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new IsOidcUser(
            $container->get('Omeka\AuthenticationService'),
            $container->get('Omeka\Settings\User')
        );
    }
}
