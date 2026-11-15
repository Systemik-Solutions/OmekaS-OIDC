<?php
namespace Oidc\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Oidc\View\Helper\OidcLoginLink;

class OidcLoginLinkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new OidcLoginLink($container->get('Omeka\Settings'));
    }
}
