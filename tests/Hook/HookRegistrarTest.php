<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Hook;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Hook\HookRegistrar;
use Smbpunt\TimelineGraphBundle\Model\Node;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked\AuthenticatedClient;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked\BaseGreeter;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked\FormalGreeter;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked\TokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[RequiresPhpExtension('opentelemetry')]
final class HookRegistrarTest extends TestCase
{
    // A method overridden by a child class counts once, and an inherited method is traced.
    public function testInheritedAndOverriddenMethodsAreRecordedOnce(): void
    {
        $greeter = new FormalGreeter();
        $recorder = $this->register([
            BaseGreeter::class => ['farewell', 'greet'],
            FormalGreeter::class => ['greet'],
        ]);

        self::assertSame('Hello madam Goodbye', $greeter->greet().' '.$greeter->farewell());
        self::assertSame([
            'request → '.FormalGreeter::class.'::greet',
            'request → '.FormalGreeter::class.'::farewell',
        ], $this->edges($recorder));
        self::assertSame(0, $recorder->getDepth());
    }

    // A prepared statement, like an ORM INSERT, goes to the database box.
    public function testPreparedStatementGoesToDatabase(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE product (name TEXT)');
        $statement = $connection->prepare('INSERT INTO product (name) VALUES (?)');
        $statement->bindValue(1, 'Ada');
        $recorder = $this->register([]);

        $statement->executeStatement();

        self::assertSame(['request → exit:'.Node::TYPE_DATABASE.':SQLite::executeStatement'], $this->edges($recorder));
    }

    // Reading the HTTP response, where the server wait happens, goes to the host box.
    public function testReadingHttpResponseGoesToHost(): void
    {
        $client = new MockHttpClient(new MockResponse('{"ok": true}'));
        $recorder = $this->register([]);

        $client->request('GET', 'https://api.example.org/products')->toArray();

        $host = 'exit:'.Node::TYPE_HTTP.':api.example.org';
        self::assertSame([
            'request → '.$host.'::request',
            'request → '.$host.'::toArray',
        ], $this->edges($recorder));
        self::assertSame(0, $recorder->getDepth());
    }

    // Reading the same response again is not a new call to the host.
    public function testOnlyFirstReadOfResponseIsRecorded(): void
    {
        $client = new MockHttpClient(new MockResponse('ok'));
        $recorder = $this->register([]);

        $response = $client->request('GET', 'https://api.example.org/products');
        $response->getStatusCode();
        $response->getHeaders();
        $response->getContent();

        $host = 'exit:'.Node::TYPE_HTTP.':api.example.org';
        self::assertSame([
            'request → '.$host.'::request',
            'request → '.$host.'::getStatusCode',
        ], $this->edges($recorder));
        self::assertSame(0, $recorder->getDepth());
    }

    // A client with a default base_uri goes to the host box, not to an unnamed HTTP box.
    public function testRelativeUrlTakesHostOfResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('ok'), 'https://api.example.org');
        $recorder = $this->register([]);

        $client->request('GET', '/products')->getContent();

        $host = 'exit:'.Node::TYPE_HTTP.':api.example.org';
        self::assertSame([
            'request → '.$host.'::request',
            'request → '.$host.'::getContent',
        ], $this->edges($recorder));
    }

    // A traced decorated client: the service it calls before delegating starts from the host box.
    public function testDecoratedClientCallsStartFromHost(): void
    {
        $client = new AuthenticatedClient(new MockHttpClient(new MockResponse('ok')), new TokenProvider());
        $recorder = $this->register([
            AuthenticatedClient::class => ['request'],
            TokenProvider::class => ['token'],
        ]);

        $client->request('GET', '/products')->getContent();

        $host = 'exit:'.Node::TYPE_HTTP.':api.example.org';
        self::assertSame([
            'request → '.AuthenticatedClient::class.'::request',
            AuthenticatedClient::class.' → '.$host.'::request',
            $host.' → '.TokenProvider::class.'::token',
            'request → '.$host.'::getContent',
        ], $this->edges($recorder));
        self::assertSame(0, $recorder->getDepth());
    }

    // A decorated client that gets a token from another host: both hosts are in the flow.
    public function testDecoratedClientKeepsRequestToAnotherHost(): void
    {
        $client = new AuthenticatedClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')), new TokenProvider(), true);
        $recorder = $this->register([AuthenticatedClient::class => ['request']]);

        $client->request('GET', '/products')->getContent();

        $api = 'exit:'.Node::TYPE_HTTP.':api.example.org';
        $auth = 'exit:'.Node::TYPE_HTTP.':auth.example.org';
        self::assertSame([
            'request → '.AuthenticatedClient::class.'::request',
            AuthenticatedClient::class.' → '.$api.'::request',
            $api.' → '.$auth.'::request',
            $api.' → '.$auth.'::getContent',
            'request → '.$api.'::getContent',
        ], $this->edges($recorder));
    }

    /**
     * @param array<string, list<string>> $tracedMethods
     */
    private function register(array $tracedMethods): FlowRecorder
    {
        $recorder = new FlowRecorder();
        new HookRegistrar($recorder, $tracedMethods)->register();

        return $recorder;
    }

    /**
     * @return list<string>
     */
    private function edges(FlowRecorder $recorder): array
    {
        return array_map(static fn (array $call): string => $call['from'].' → '.$call['to'].'::'.$call['method'], $recorder->getCalls());
    }
}
