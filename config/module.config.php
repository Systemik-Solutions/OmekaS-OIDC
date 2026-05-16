<?php
namespace Oidc;

return [
    'service_manager' => [
        'factories' => [
            'Oidc\Client' => Service\OidcClientFactory::class,
            'Omeka\AuthenticationService' => Service\AuthenticationServiceFactory::class,
            Service\Configurator::class => Service\ConfiguratorFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Oidc\Controller\OidcController' => Service\Controller\OidcControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'oidcLoginLink' => View\Helper\OidcLoginLink::class,
        ],
        'factories' => [
            'isOidcUser' => Service\ViewHelper\IsOidcUserFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'isOidcUser' => Service\ControllerPlugin\IsOidcUserFactory::class,
        ],
    ],
    'block_layouts' => [
        'invokables' => [
            'oidcLoginLink' => Site\BlockLayout\OidcLoginLink::class,
        ],
    ],
    'router' => [
        'routes' => [
            'oidc' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/oidc[/:action]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'Oidc\Controller',
                        'controller' => 'Oidc\Controller\OidcController',
                        'action' => 'login',
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
