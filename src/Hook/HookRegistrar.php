<?php

namespace Smbpunt\TimelineGraphBundle\Hook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Smbpunt\TimelineGraphBundle\Model\Node;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Twig\Environment;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Installs opentelemetry extension hooks on traced methods and exit points.
 *
 * Hooks live as long as the thread (FrankenPHP worker mode): each one is installed only once,
 * and they write to the recorder of the last booted kernel.
 */
final class HookRegistrar
{
    /** @var array<string, true> hooks already installed in this thread, by "class::method" */
    private static array $hooked = [];

    private static ?FlowRecorder $active = null;

    /** @var \WeakMap<object, true>|null responses already read once */
    private static ?\WeakMap $readResponses = null;

    /**
     * @param array<string, list<string>> $tracedMethods traced methods, by class
     */
    public function __construct(
        private readonly FlowRecorder $recorder,
        private readonly array $tracedMethods,
    ) {
    }

    public static function isAvailable(): bool
    {
        return \extension_loaded('opentelemetry') && \function_exists('OpenTelemetry\Instrumentation\hook');
    }

    public function register(): void
    {
        self::$active = $this->recorder;

        if (!self::isAvailable()) {
            return;
        }

        foreach ($this->tracedMethods as $hookedClass => $methods) {
            foreach ($methods as $method) {
                // A parent class hook also fires when a child overrides the method:
                // only the hook of the class declaring the executed code ($class) records the call.
                $owns = static fn (?string $class): bool => 0 === strcasecmp((string) $class, $hookedClass);
                $this->hook(
                    $hookedClass,
                    $method,
                    static function (?object $object, array $params, ?string $class, string $function) use ($owns): void {
                        if ($owns($class)) {
                            self::$active?->enter(null !== $object ? $object::class : (string) $class, $function);
                        }
                    },
                    static function (?object $object, array $params, mixed $return, ?\Throwable $exception, ?string $class) use ($owns): void {
                        if ($owns($class)) {
                            self::$active?->leave($exception);
                        }
                    },
                );
            }
        }

        $this->registerExitPoints();
    }

    /**
     * Exit points: each becomes a box, and calls made while it runs start from it.
     */
    private function registerExitPoints(): void
    {
        $database = static function (?object $connection, array $params, ?string $class, string $function): void {
            self::$active?->enterExit(Node::TYPE_DATABASE, self::databaseLabel($connection), $function);
        };
        $this->hook(Connection::class, 'executeQuery', $database);
        $this->hook(Connection::class, 'executeStatement', $database);

        // Prepared statements, including the ORM's INSERT and UPDATE.
        $statement = static function (?object $statement, array $params, ?string $class, string $function): void {
            self::$active?->enterExit(Node::TYPE_DATABASE, self::databaseLabel(self::statementConnection($statement)), $function);
        };
        $this->hook(Statement::class, 'executeQuery', $statement);
        $this->hook(Statement::class, 'executeStatement', $statement);

        // Relative URL: the host is taken from the returned response.
        $this->hook(
            HttpClientInterface::class,
            'request',
            static function (?object $client, array $params, ?string $class, string $function): void {
                self::$active?->enterExit(Node::TYPE_HTTP, self::host($params), $function);
            },
            static function (?object $client, array $params, mixed $return, ?\Throwable $exception): void {
                self::$active?->leave($exception, \is_object($return) ? self::responseHost($return) : null);
            },
        );

        // request() does not wait for the server: the wait happens on the first read of the response.
        // The following reads of the same response are not recorded.
        $response = static function (?object $response, array $params, ?string $class, string $function): void {
            self::$readResponses ??= new \WeakMap();
            if (null !== $response && isset(self::$readResponses[$response])) {
                self::$active?->enterUnrecorded();

                return;
            }

            if (null !== $response) {
                self::$readResponses[$response] = true;
            }
            self::$active?->enterExit(Node::TYPE_HTTP, self::responseHost($response), $function);
        };
        foreach (['getStatusCode', 'getHeaders', 'getContent', 'toArray'] as $method) {
            $this->hook(ResponseInterface::class, $method, $response);
        }

        $this->hook('Symfony\Component\Ldap\Adapter\QueryInterface', 'execute', static function (?object $query, array $params, ?string $class, string $function): void {
            self::$active?->enterExit(Node::TYPE_LDAP, 'LDAP', $function);
        });

        $this->hook(Environment::class, 'render', static function (?object $twig, array $params, ?string $class, string $function): void {
            self::$active?->enterExit(Node::TYPE_TEMPLATE, 'Twig', $function, self::templateName($params[0] ?? null));
        });
    }

    private function hook(string $class, string $method, \Closure $pre, ?\Closure $post = null): void
    {
        $key = strtolower($class.'::'.$method);
        if (isset(self::$hooked[$key])) {
            return;
        }

        self::$hooked[$key] = true;
        hook($class, $method, $pre, $post ?? static function (?object $object, array $params, mixed $return, ?\Throwable $exception): void {
            self::$active?->leave($exception);
        });
    }

    /**
     * Connection of a Doctrine\DBAL\Statement, kept in a protected property.
     */
    private static function statementConnection(?object $statement): ?object
    {
        if (null === $statement || !property_exists($statement, 'conn')) {
            return null;
        }

        $connection = new \ReflectionProperty($statement, 'conn')->getValue($statement);

        return \is_object($connection) ? $connection : null;
    }

    private static function databaseLabel(?object $connection): string
    {
        $driver = '';
        if (null !== $connection && method_exists($connection, 'getParams')) {
            $params = $connection->getParams();
            $driver = \is_array($params) && \is_string($params['driver'] ?? null) ? $params['driver'] : '';
        }

        return match (true) {
            str_contains($driver, 'pgsql') => 'PostgreSQL',
            str_contains($driver, 'mysql') => 'MySQL',
            str_contains($driver, 'sqlite') => 'SQLite',
            str_contains($driver, 'oci') => 'Oracle',
            str_contains($driver, 'sqlsrv') => 'SQL Server',
            default => 'Database',
        };
    }

    /**
     * Called host, or null for a relative URL (client with base_uri): a decorated client further down will provide it.
     *
     * @param array<mixed> $params
     */
    private static function host(array $params): ?string
    {
        $url = $params[1] ?? null;
        $options = $params[2] ?? [];
        $baseUri = \is_array($options) ? ($options['base_uri'] ?? null) : null;

        foreach ([$url, $baseUri] as $candidate) {
            $host = \is_string($candidate) ? parse_url($candidate, \PHP_URL_HOST) : null;
            if (\is_string($host) && '' !== $host) {
                return $host;
            }
        }

        return null;
    }

    private static function responseHost(?object $response): ?string
    {
        $url = null !== $response && method_exists($response, 'getInfo') ? $response->getInfo('url') : null;
        $host = \is_string($url) ? parse_url($url, \PHP_URL_HOST) : null;

        return \is_string($host) && '' !== $host ? $host : null;
    }

    private static function templateName(mixed $template): ?string
    {
        if (\is_string($template)) {
            return $template;
        }

        if (\is_object($template) && method_exists($template, 'getTemplateName')) {
            $name = $template->getTemplateName();

            return \is_string($name) ? $name : null;
        }

        return null;
    }
}
