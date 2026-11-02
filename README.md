# TimelineGraphBundle

Like the Symfony profiler's Timeline, but as a graph: see how a request flows through your services, then replay it step by step.

- One box per service, grouped by feature, one arrow per caller → callee with the methods called.
- Database, HTTP, LDAP and Twig calls shown as their own boxes.
- Failing paths highlighted in red.
- Replay player with step forward / back and breakpoints.

Dev only: nothing is registered when `kernel.debug` is off.

## Requirements

- PHP 8.4+ with the [`opentelemetry`](https://pecl.php.net/package/opentelemetry) extension
- Symfony 8 with the WebProfilerBundle

## Installation

```sh
pecl install opentelemetry   # or: install-php-extensions opentelemetry
composer require --dev smbpunt/timeline-graph-bundle
```

Symfony Flex registers the bundle for `dev` and `test`. Without Flex, add it to `config/bundles.php`:

```php
Smbpunt\TimelineGraphBundle\TimelineGraphBundle::class => ['dev' => true, 'test' => true],
```

## Usage

Open any request in the profiler and select the **Timeline Graph** panel.

- **Space**: play / pause
- **← →**: step back / forward
- **Click a box**: toggle a breakpoint (kept across requests)

## Configuration

Defaults:

```yaml
# config/packages/timeline_graph.yaml
when@dev:
    timeline_graph:
        include: ['App\']
        exclude: ['App\Kernel', '*\Entity\*', '*\Dto\*', '*\ValueObject\*', '*\Enum\*']
        group_depth: 2   # App\Catalog\… is grouped as "Catalog"
        max_calls: 500   # calls recorded per request
```

A pattern ending with `\` is a namespace prefix; `*` matches any characters.

## How it works

At compile time, the bundle lists the public methods of every matching service. At boot, it hooks each of them with the `opentelemetry` extension and records the calls of the request. The panel replays this recording in the browser: nothing runs again on the server.

Limitations:

- arguments and return values are not recorded;
- methods returning a generator are not traced;
- code that runs before the kernel boots (cache warmup) is not traced.

The graph layout uses [elkjs](https://github.com/kieler/elkjs), loaded from jsDelivr.

## Contributing

```sh
make qa   # php-cs-fixer, phpstan, rector, phpunit
```

## License

MIT, see [LICENSE](LICENSE).
