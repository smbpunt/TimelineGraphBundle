# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Symfony bundle `smbpunt/timeline-graph-bundle`, dev only: a "Timeline Graph" profiler panel that draws and replays how a request flows through the app's services. Features, configuration and limitations: see `README.md`. Everything is in English (code, comments, UI, README, commits).

- PHP `>=8.4`, Symfony `^8.0`, PHPUnit `^13.2`
- Namespace: `Smbpunt\TimelineGraphBundle\` → `src/`, `Smbpunt\TimelineGraphBundle\Tests\` → `tests/`
- Hosted on GitHub (`smbpunt/TimelineGraphBundle`), use `gh`.

## QA commands (Makefile)

| Command           | Action                                     |
|-------------------|--------------------------------------------|
| `make qa`         | Runs everything: cs + stan + rector + test |
| `make test`       | PHPUnit                                    |
| `make cs`         | PHP-CS-Fixer dry run (`check --diff`)      |
| `make cs-fix`     | PHP-CS-Fixer applies fixes                 |
| `make stan`       | PHPStan level 6                            |
| `make rector`     | Rector dry run                             |
| `make rector-fix` | Rector applies changes                     |

CS-Fixer rules: `@PHP8x4Migration`(`:risky`), `@Symfony`, `@Symfony:risky`, `@PHPUnit11x0Migration:risky`.
Symfony conventions first, then PHP (PER-CS) when they don't conflict: no
`declare(strict_types=1)` (removed by `@Symfony:risky`, `SafeDeclareStrictTypesRector` excluded from Rector).

Tests that hook methods need the `opentelemetry` extension; they are skipped without it.

## Structure

- `src/TimelineGraphBundle.php` — extends `AbstractBundle`: configuration, services (debug only), compiler pass, hooks installed in `boot()`.
- `config/services.php` — services declared explicitly, prefixed `.timeline_graph.`.
- `templates/Collector/flow.html.twig` and `public/` — profiler panel (no-build JS, elkjs loaded from jsDelivr).
- `tests/` — PHPUnit; `tests/Functional/App/` is a minimal app for the functional test.

## How it works

- At compile time, `CollectTracedClassesPass` lists the traced services (`include` / `exclude`) and their public methods, including those inherited from a traced parent class. The bundle's own classes are never traced.
- In `TimelineGraphBundle::boot()`, `HookRegistrar` hooks each of them with the `opentelemetry` extension. A hook is installed once per thread (FrankenPHP worker mode).
- `FlowRecorder` keeps the call stack and the list of calls, `FlowBuilder` turns them into the `Flow` / `Node` / `Call` model, `FlowDataCollector` stores it in the profile.
