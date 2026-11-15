<?php
namespace Oidc\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Oidc\Settings as S;
use Omeka\Settings\Settings;

class OidcLoginLink extends AbstractHelper
{
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function __invoke(array $options = []): string
    {
        $view = $this->getView();
        $url = $view->url('oidc', ['action' => 'login']);
        return $view->partial('common/oidc-login-link', [
            'label' => $options['label'] ?? $this->label(),
            'url' => $url,
            'showLocalLink' => $options['showLocalLink'] ?? false,
        ]);
    }

    /**
     * The configured button label, falling back to the default for a label
     * that was saved empty.
     */
    public function label(): string
    {
        $label = $this->settings->get(S::LOGIN_LABEL, S::DEFAULTS[S::LOGIN_LABEL]);
        return is_string($label) && trim($label) !== '' ? $label : S::DEFAULTS[S::LOGIN_LABEL];
    }
}
