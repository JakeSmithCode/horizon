<p align="center"><img width="373" height="60" src="/art/logo.svg" alt="Laravel Horizon"></p>

<p align="center">
<a href="https://github.com/laravel/horizon/actions"><img src="https://github.com/laravel/horizon/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/dt/laravel/horizon" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/v/laravel/horizon" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/horizon"><img src="https://img.shields.io/packagist/l/laravel/horizon" alt="License"></a>
</p>

## Introduction

Horizon provides a beautiful dashboard and code-driven configuration for your Laravel powered Redis queues. Horizon allows you to easily monitor key metrics of your queue system such as job throughput, runtime, and job failures.

All of your worker configuration is stored in a single, simple configuration file, allowing your configuration to stay in source control where your entire team can collaborate.

<p align="center">
<img src="https://laravel.com/img/docs/horizon-example.png">
</p>

## Official Documentation

Documentation for Horizon can be found on the [Laravel website](https://laravel.com/docs/horizon).

## Fork Enhancements

This fork is a **drop-in superset** of `laravel/horizon`. It changes no
configuration defaults, routes, Redis data structures, or public API
signatures, so it can be installed in any repository already running Horizon
without modification. It adds three read-only observability commands:

| Command | Description |
| --- | --- |
| `php artisan horizon:stats` | Print the current dashboard statistics (status, processes, throughput, recent/failed jobs, wait times) to the terminal. Add `--json` for machine-readable output. |
| `php artisan horizon:diagnose` | Run health checks (Redis reachability, required extensions, master/worker status, long waits, recent failures) and exit `0` healthy, `1` warnings, `2` critical — ideal for container probes and CI smoke checks. Add `--json` for structured output. |
| `php artisan horizon:prometheus` | Export Horizon metrics in Prometheus / OpenMetrics text format for scraping. Use `--file=` to write to a node_exporter textfile collector, or `--namespace=` to change the metric prefix. |

All three only read from Horizon's existing repositories, so they are safe to
run against a live production installation.

### Health dashboard panel

A new **Health** screen is available in the Horizon dashboard (and via the
`GET /horizon/api/health` endpoint). It surfaces the same checks as
`horizon:diagnose` — Redis reachability, required PHP extensions, master and
worker status, queue wait times, and recent failure rate — so you can see at a
glance whether the installation needs attention. The endpoint reuses Horizon's
existing authorization gate and is purely additive.

### Job failure-rate alerting

Horizon already notifies on long queue waits. This fork adds an opt-in
notification when the number of recently failed jobs exceeds a threshold,
reusing the existing Slack / SMS / mail notification routing. It is **disabled
by default**; enable it by setting a threshold:

```php
// config/horizon.php
'failure_threshold' => env('HORIZON_FAILURE_THRESHOLD'), // e.g. 25
```

When unset (the default, and for any existing published config), no behavior
changes.

### Asymmetric auto-scaling (`balanceMaxScaleDown`)

Supervisors gain an optional `balanceMaxScaleDown` setting that caps how many
processes may be removed during a single scaling pass, independently of
`balanceMaxShift` (which still governs scale-up). This lets a supervisor scale
up quickly under load while scaling down gradually to avoid flapping:

```php
// config/horizon.php — under a supervisor's options
'balanceMaxShift' => 5,        // add up to 5 workers per scale
'balanceMaxScaleDown' => 1,    // remove at most 1 worker per scale
```

When omitted, it defaults to the value of `balanceMaxShift`, exactly preserving
today's behavior.

## Contributing

Thank you for considering contributing to Horizon! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/horizon/security/policy) on how to report security vulnerabilities.

## License

Laravel Horizon is open-sourced software licensed under the [MIT license](LICENSE.md).
