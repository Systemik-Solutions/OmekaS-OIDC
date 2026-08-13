<?php
namespace Oidc\Service;

use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Oidc\Exception\NotConfiguredException;
use Oidc\Settings as S;
use Oidc\Stdlib\PublicUrl;

class OidcClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings = $container->get('Omeka\Settings');

        $discoveryUrl = $settings->get(S::IDP_DISCOVERY_URL, S::DEFAULTS[S::IDP_DISCOVERY_URL]);
        $baseUrl      = $settings->get(S::BASE_URL);
        $clientId     = $settings->get(S::CLIENT_ID);
        $clientSecret = $settings->get(S::CLIENT_SECRET);

        $baseUrl = is_string($baseUrl) ? PublicUrl::normalize($baseUrl) : null;
        if (!$discoveryUrl || !$baseUrl || !$clientId || !$clientSecret) {
            throw new NotConfiguredException(
                'OIDC module is not fully configured. Set public base URL, discovery URL, client ID, and client secret.'
            );
        }

        $redirectUri = PublicUrl::appendPath($baseUrl, '/oidc/callback');
        if ($redirectUri === null) {
            throw new NotConfiguredException('OIDC public base URL is invalid.');
        }

        $issuer = (new IssuerBuilder())->build($discoveryUrl);

        $metadata = ClientMetadata::fromArray([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uris' => [$redirectUri],
            'token_endpoint_auth_method' => 'client_secret_basic',
            'response_types' => ['code'],
        ]);

        return (new ClientBuilder())
            ->setIssuer($issuer)
            ->setClientMetadata($metadata)
            ->build();
    }
}
