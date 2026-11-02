<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorated HTTP client called with a relative URL: gets a token, then delegates with the full URL.
 */
final readonly class AuthenticatedClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private TokenProvider $tokens,
        private bool $fetchTokenOverHttp = false,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->fetchTokenOverHttp) {
            $this->client->request('POST', 'https://auth.example.org/token')->getContent();
        } else {
            $options['auth_bearer'] = $this->tokens->token();
        }

        return $this->client->request($method, 'https://api.example.org'.$url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
