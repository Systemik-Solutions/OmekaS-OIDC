<?php
namespace Oidc\Service;

use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Interop\Container\ContainerInterface;
use Laminas\Http\Request as HttpRequest;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Oidc\Exception\NotConfiguredException;
use Oidc\Settings as S;

class OidcClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings = $container->get('Omeka\Settings');

        $discoveryUrl = $settings->get(S::IDP_DISCOVERY_URL, S::DEFAULTS[S::IDP_DISCOVERY_URL]);
        $clientId     = $settings->get(S::CLIENT_ID);
        $clientSecret = $settings->get(S::CLIENT_SECRET);

        if (!$discoveryUrl || !$clientId || !$clientSecret) {
            throw new NotConfiguredException(
                'OIDC module is not fully configured. Set discovery URL, client ID, and client secret.'
            );
        }

        $redirectUri = self::resolveRedirectUri($container);

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

    private static function resolveRedirectUri(ContainerInterface $container): string
    {
        $request = $container->get('Request');
        if (!$request instanceof HttpRequest) {
            throw new NotConfiguredException('OIDC client requires an HTTP request context.');
        }
        $uri = $request->getUri();
        $port = $uri->getPort();
        $authority = $uri->getHost() . (($port && !in_array($port, [80, 443], true)) ? ':' . $port : '');
        $base = rtrim($request->getBaseUrl(), '/');
        return sprintf('%s://%s%s/oidc/callback', $uri->getScheme(), $authority, $base);
    }
}
