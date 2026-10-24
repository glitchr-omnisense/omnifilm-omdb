<?php

namespace Omnifilm\Omdb;

use Omnifilm\Config;
use Omnifilm\Http\JsonClient;
use Omnifilm\ProviderFactory;
use Omnifilm\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** "omdb": options api_key (required), api ("https://www.omdbapi.com/"). */
final class OmdbFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifilm.factory_name' => 'omdb',
            'omnifilm.factory_title' => 'OMDb',
            'omnifilm.required_options' => ['api_key'],
            'api' => 'https://www.omdbapi.com/',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new OmdbProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'OMDb', ['Accept' => 'application/json']), (string) $c->string('api_key'), (string) $c->string('api'));
    }
}
