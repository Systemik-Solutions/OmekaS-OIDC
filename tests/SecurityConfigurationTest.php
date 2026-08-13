#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Oidc\Exception\InvalidConfigurationException;
use Oidc\Form\ConfigForm;
use Oidc\Service\Configurator;
use Oidc\Settings as S;
use Omeka\Settings\Settings;

final class InMemorySettings extends Settings
{
    private $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get($id, $default = null)
    {
        return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
    }

    public function set($id, $value)
    {
        if ($value === null) {
            unset($this->values[$id]);
        } else {
            $this->values[$id] = $value;
        }
    }
}

function baseConfiguration(array $overrides = []): array
{
    return array_merge([
        S::BASE_URL => 'https://omeka.example.org/omeka',
        S::SCOPES => ['openid', 'org.cilogon.userinfo'],
        S::ROLE_CLAIM => 'isMemberOf',
    ], $overrides);
}

function expectInvalidConfiguration(callable $callback, string $description): void
{
    try {
        $callback();
    } catch (InvalidConfigurationException $e) {
        return;
    }

    fwrite(STDERR, "Expected invalid configuration: {$description}\n");
    exit(1);
}

$settings = new InMemorySettings([S::CLIENT_SECRET => 'test-secret']);
$configurator = new Configurator($settings);

expectInvalidConfiguration(
    fn () => $configurator->applyValidated([
        S::SCOPES => ['openid'],
        S::ROLE_CLAIM => 'isMemberOf',
    ]),
    'missing public base URL'
);

expectInvalidConfiguration(
    fn () => $configurator->applyValidated(baseConfiguration([
        S::BASE_URL => 'https://user@omeka.example.org/omeka',
    ])),
    'public base URL containing user information'
);

$configurator->applyValidated(baseConfiguration([
    S::BASE_URL => 'https://OMEKA.example.org:443/omeka/',
]));
if ($settings->get(S::BASE_URL) !== 'https://omeka.example.org/omeka') {
    fwrite(STDERR, "Public base URL was not normalized before storage.\n");
    exit(1);
}

expectInvalidConfiguration(
    fn () => $configurator->applyValidated(baseConfiguration([
        S::ACCESS_GUARD_CLAIM => 'isMemberOf',
    ])),
    'claim set without value'
);

expectInvalidConfiguration(
    fn () => $configurator->applyValidated(baseConfiguration([
        S::ACCESS_GUARD_VALUE => 'omeka-instance-a',
    ])),
    'value set without claim'
);

$configurator->applyValidated(baseConfiguration([
    S::ACCESS_GUARD_CLAIM => 'isMemberOf',
    S::ACCESS_GUARD_VALUE => 'omeka-instance-a',
]));

// A partial programmatic update is valid when the other half is already
// stored, because Configurator validates the effective resulting pair.
$configurator->applyValidated(baseConfiguration([
    S::ACCESS_GUARD_VALUE => 'omeka-instance-b',
]));

expectInvalidConfiguration(
    fn () => $configurator->applyValidated(baseConfiguration([
        S::ACCESS_GUARD_CLAIM => null,
    ])),
    'clearing only the claim while a value remains stored'
);

function formData(?string $claim, ?string $value): array
{
    return [
        S::BASE_URL => 'https://omeka.example.org/omeka',
        S::IDP_DISCOVERY_URL => 'https://cilogon.org/.well-known/openid-configuration',
        S::CLIENT_ID => 'test-client',
        S::CLIENT_SECRET => '',
        S::SCOPES => 'openid org.cilogon.userinfo',
        S::ROLE_CLAIM => 'isMemberOf',
        S::ROLES_MAP => '',
        S::ROLE_DEFAULT => '',
        S::ACCESS_GUARD_CLAIM => $claim,
        S::ACCESS_GUARD_VALUE => $value,
        S::HIDE_LOCAL_LOGIN => '1',
        S::REDIRECT => 'home',
        S::CLAIMS_DISPLAY => '',
    ];
}

foreach ([
    ['isMemberOf', null, false],
    [null, 'omeka-instance-a', false],
    [null, null, true],
    ['isMemberOf', 'omeka-instance-a', true],
] as [$claim, $value, $expected]) {
    $form = new ConfigForm();
    $form->init();
    $form->setData(formData($claim, $value));
    $actual = $form->isValid();
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Unexpected form validity for claim=%s value=%s: expected %s, got %s\n",
            var_export($claim, true),
            var_export($value, true),
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

echo "Security configuration tests passed.\n";
