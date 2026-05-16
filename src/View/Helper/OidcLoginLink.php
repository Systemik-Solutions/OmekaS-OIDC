<?php
namespace Oidc\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class OidcLoginLink extends AbstractHelper
{
    public function __invoke(array $options = []): string
    {
        $view = $this->getView();
        $url = $view->url('oidc', ['action' => 'login']);
        return $view->partial('common/oidc-login-link', [
            'label' => $options['label'] ?? 'Log in with CILogon',
            'url' => $url,
            'showLocalLink' => $options['showLocalLink'] ?? false,
        ]);
    }
}
