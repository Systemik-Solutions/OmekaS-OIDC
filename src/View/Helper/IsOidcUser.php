<?php
namespace Oidc\View\Helper;

use Laminas\Authentication\AuthenticationService;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Settings\UserSettings;

class IsOidcUser extends AbstractHelper
{
    private $auth;
    private $userSettings;

    public function __construct(AuthenticationService $auth, UserSettings $userSettings)
    {
        $this->auth = $auth;
        $this->userSettings = $userSettings;
    }

    public function __invoke(?int $userId = null): bool
    {
        if ($userId === null) {
            $identity = $this->auth->getIdentity();
            if (!$identity) {
                return false;
            }
            $userId = $identity->getId();
        }
        return (bool) $this->userSettings->get('oidc_managed', false, $userId);
    }
}
