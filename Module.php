<?php
namespace Oidc;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Oidc\Exception\InvalidConfigurationException;
use Oidc\Form\ConfigForm;
use Oidc\Service\Configurator;
use Omeka\Entity\User;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    private const BASE_URL_WARNING = 'OIDC: Public base URL is not set. OIDC sign-in is disabled until it is configured in the module settings.';

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Make the new required public base URL discoverable to administrators
     * upgrading from 1.0.x. It cannot be migrated automatically because Omeka
     * has no canonical stored installation URL.
     */
    public function upgrade(
        $oldVersion,
        $newVersion,
        ServiceLocatorInterface $services
    ): void
    {
        if (version_compare($oldVersion, '1.1.0', '<')) {
            $this->warnIfBaseUrlMissing($services, true);
        }
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, ['Oidc\Controller\OidcController']);

        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_FINISH,
            [$this, 'injectLoginButton'],
            -100
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        foreach ([\Omeka\Controller\LoginController::class, 'Omeka\Controller\Login'] as $id) {
            $sharedEventManager->attach($id, MvcEvent::EVENT_DISPATCH, [$this, 'blockOidcPasswordReset'], 100);
            $sharedEventManager->attach($id, MvcEvent::EVENT_DISPATCH, [$this, 'redirectOidcLogout'], 100);
        }

        foreach ([\Omeka\Controller\Admin\UserController::class, 'Omeka\Controller\Admin\User'] as $id) {
            $sharedEventManager->attach($id, MvcEvent::EVENT_DISPATCH, [$this, 'blockOidcPasswordChange'], 100);
            $sharedEventManager->attach($id, 'view.details', [$this, 'renderUserClaimsBox']);
            $sharedEventManager->attach($id, 'view.show.after', [$this, 'renderUserClaimsList']);
            $sharedEventManager->attach($id, 'view.edit.after', [$this, 'hideOidcPasswordSection']);
        }
    }

    public function blockOidcPasswordReset(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch || $routeMatch->getParam('action') !== 'forgot-password') {
            return;
        }
        $request = $event->getRequest();
        if (!method_exists($request, 'isPost') || !$request->isPost()) {
            return;
        }
        $email = $request->getPost('email');
        if (!$email) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $em = $services->get('Omeka\EntityManager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            return;
        }
        $userSettings = $services->get('Omeka\Settings\User');
        if (!$userSettings->get('oidc_managed', false, $user->getId())) {
            return;
        }

        $controller = $event->getTarget();
        $controller->messenger()->addError(
            'This account is managed by your institutional identity provider. Please use the Sign In button.' // @translate
        );

        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $controller->url()->fromRoute('login'));
        $response->setStatusCode(302);
        $event->setResult($response);
        $event->stopPropagation(true);
        return $response;
    }

    public function blockOidcPasswordChange(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch || $routeMatch->getParam('action') !== 'edit') {
            return;
        }
        $request = $event->getRequest();
        if (!method_exists($request, 'isPost') || !$request->isPost()) {
            return;
        }

        $changePassword = $request->getPost('change-password');
        $newPassword = is_array($changePassword)
            ? ($changePassword['password-confirm']['password'] ?? '')
            : '';
        if (empty($newPassword)) {
            return;
        }

        $userId = $routeMatch->getParam('id');
        if (!$userId) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $userSettings = $services->get('Omeka\Settings\User');
        if (!$userSettings->get('oidc_managed', false, $userId)) {
            return;
        }

        $controller = $event->getTarget();
        $controller->messenger()->addError(
            'This account is managed by your institutional identity provider. The password cannot be changed here.' // @translate
        );

        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $request->getRequestUri());
        $response->setStatusCode(302);
        $event->setResult($response);
        $event->stopPropagation(true);
        return $response;
    }

    public function hideOidcPasswordSection(Event $event)
    {
        $view = $event->getTarget();
        $userId = null;
        if (isset($view->user)) {
            $userId = $view->user->id();
        } elseif (isset($view->resource)) {
            $userId = $view->resource->id();
        }
        if (!$userId) {
            return;
        }

        $userSettings = $this->getServiceLocator()->get('Omeka\Settings\User');
        if (!$userSettings->get('oidc_managed', false, $userId)) {
            return;
        }

        $heading = $view->translate('Password managed by identity provider');
        $message = $view->translate('This account is managed by your institutional identity provider. The password cannot be changed here.');
        echo '<script>(function(){'
            . 'var s=document.getElementById("change-password");'
            . 'if(!s){return;}'
            . 's.innerHTML='
            . json_encode(
                '<div style="background:#e8f4f8;border-left:4px solid #2196F3;padding:1em 1.25em;margin:1em 0;">'
                . '<strong>' . $heading . '</strong><br>'
                . $message
                . '</div>'
            ) . ';'
            . '})();</script>';
    }

    public function redirectOidcLogout(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch || $routeMatch->getParam('action') !== 'logout') {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $auth = $services->get('Omeka\AuthenticationService');
        $identity = $auth->getIdentity();
        if (!$identity) {
            return;
        }
        $userSettings = $services->get('Omeka\Settings\User');
        if (!$userSettings->get('oidc_managed', false, $identity->getId())) {
            return;
        }

        $controller = $event->getTarget();
        $url = $controller->url()->fromRoute('oidc', ['action' => 'logout']);

        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $url);
        $response->setStatusCode(302);
        $event->setResult($response);
        $event->stopPropagation(true);
        return $response;
    }

    public function injectLoginButton(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch || $routeMatch->getParam('action') !== 'login') {
            return;
        }
        $request = $event->getRequest();
        if (method_exists($request, 'isPost') && $request->isPost()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response instanceof \Laminas\Http\Response) {
            return;
        }
        $body = $response->getContent();
        if (!is_string($body) || stripos($body, '</form>') === false) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();
        $renderer = $services->get('ViewRenderer');
        $settings = $services->get('Omeka\Settings');

        $button = $renderer->oidcLoginLink();
        $injection = $button;

        // Keep the local form available while the required public base URL is
        // unset, otherwise an upgrade from 1.0.x can leave administrators
        // without any usable way to sign in and finish configuring OIDC.
        if ($settings->get(Settings::HIDE_LOCAL_LOGIN, Settings::DEFAULTS[Settings::HIDE_LOCAL_LOGIN])
            && $settings->get(Settings::BASE_URL)
        ) {
            $injection .= '<style>'
                . '.field:has(#email), .field:has(#password),'
                . 'input[type="submit"][name="submit"],'
                . '.forgot-password'
                . '{display:none !important;}'
                . '</style>';
        }

        $body = preg_replace('#</form>#i', '</form>' . $injection, $body, 1);
        $response->setContent($body);
    }

    public function renderUserClaimsBox(Event $event)
    {
        $entity = $event->getParam('entity');
        if (!$entity) {
            return;
        }
        $view = $event->getTarget();
        echo $view->partial('common/user-oidc-claims', ['entity' => $entity]);
    }

    public function renderUserClaimsList(Event $event)
    {
        $view = $event->getTarget();
        $userId = null;
        if (isset($view->user)) {
            $userId = $view->user->id();
        } elseif (isset($view->resource)) {
            $userId = $view->resource->id();
        }
        if (!$userId) {
            return;
        }
        echo $view->partial('common/user-oidc-claims-list', ['userId' => $userId]);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $stored = [];
        foreach (Settings::KEYS as $key) {
            $stored[$key] = $settings->get($key, Settings::DEFAULTS[$key] ?? null);
        }
        $form->setData(ConfigForm::settingsToFormData($stored));

        $this->warnIfBaseUrlMissing($services);

        return $renderer->formCollection($form, false);
    }

    /**
     * Surface the required setting in the admin UI and optionally the log.
     */
    private function warnIfBaseUrlMissing(
        ServiceLocatorInterface $services,
        bool $writeLog = false
    ): bool
    {
        $settings = $services->get('Omeka\Settings');
        if ($settings->get(Settings::BASE_URL)) {
            return false;
        }

        if ($writeLog) {
            $services->get('Omeka\Logger')->warn(self::BASE_URL_WARNING);
        }
        $services
            ->get('ControllerPluginManager')
            ->get('messenger')
            ->addWarning(self::BASE_URL_WARNING);
        return true;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }

        $data = ConfigForm::formDataToSettings($form->getData());

        try {
            $services->get(Configurator::class)->applyValidated($data);
        } catch (InvalidConfigurationException $e) {
            $controller->messenger()->addError($e->getMessage());
            return false;
        }

        $controller->messenger()->addSuccess('OIDC settings saved.'); // @translate
        return true;
    }
}
