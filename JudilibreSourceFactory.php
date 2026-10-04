<?php

namespace Omnilex\Judilibre;

use Omnilex\Auth\Piste;
use Omnilex\Config;
use Omnilex\Exception\InvalidConfigException;
use Omnilex\Source\SourceFactory;
use Omnilex\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Judilibre, through PISTE. The application signs with its PISTE OAuth
 * credentials, or with its PISTE API key (the KeyId header):
 *
 *   options:
 *     client_id: '...'           # with client_secret: OAuth, client credentials
 *     client_secret: '...'
 *     key_id: '...'              # or the application's API key, alone
 *     sandbox: false             # PISTE's sandbox, with the sandbox application's credentials
 *     recent: update             # the date recent() reads: update (created or updated in the base) or creation
 *     resolve_references: false  # labels in place of keys in the answers
 *     throttle: 0.5              # seconds between two calls (the quotas are per application, on the portal)
 *     base_uri, token_uri        # to override either
 */
final class JudilibreSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilex.factory_name' => 'judilibre',
            'omnilex.required_options' => [],
            'sandbox' => false,
            'recent' => 'update',
            'resolve_references' => false,
            'throttle' => 0.5,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $http = $this->http ?? HttpClient::create(['timeout' => 30]);
        $sandbox = (bool) $c['sandbox'];
        $headers = ['User-Agent' => self::userAgent($c)];
        $auth = null;
        if (null !== $c->get('key_id')) {
            $headers['KeyId'] = (string) $c['key_id'];
        } elseif (null !== $c->get('client_id') && null !== $c->get('client_secret')) {
            $auth = Piste::credentials($http, (string) $c['client_id'], (string) $c['client_secret'], $sandbox, $this->tokens, 'judilibre', $c->get('token_uri'));
        } else {
            throw new InvalidConfigException('The "judilibre" source needs: client_id and client_secret, or key_id.');
        }

        return new JudilibreSource(
            $http,
            (string) $c->get('base_uri', $sandbox ? JudilibreSource::SANDBOX_URI : JudilibreSource::BASE_URI),
            $auth,
            $headers,
            (float) $c['throttle'],
            (string) $c->get('recent', 'update'),
            (bool) $c['resolve_references'],
        );
    }
}
