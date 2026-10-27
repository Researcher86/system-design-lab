# Architecture — Harness, Modules and the Platform

This document maps the pieces that every chapter shares. The chapter-specific
implementations are added one at a time; the harness below is what makes their
measurements comparable.

## The flow

```text
experiments/NN-topic/*.php          benchmarks/*.php
        │                                  │
        │ returns an Experiment            │ returns a list of Benchmark
        ▼                                  ▼
  ExperimentRegistry                BenchmarkRunner
        │                                  │
        ▼                                  ▼
  ExperimentRunner                  Timings + Environment
        │                                  │
        ▼                                  ▼
  Output (text / JSON)              Formatter (text / JSON / CSV)
        │                                  │
        └──────────────┬───────────────────┘
                       ▼
              docs/BENCHMARKS.md
              book chapter (pinned output)
```

## Module map

| Module | Responsibility | Depends on |
|---|---|---|
| Foundation | Random, clock, hashes, Zipf, formatting | nothing |
| Instrumentation | Counters, stopwatch, memory meter, Measure | Foundation |
| Experiment | Descriptor, discovery, option validation, output, runner | Instrumentation |
| Benchmark | Descriptor, repetitions, timings, environment, formatters | Instrumentation |
| Cli | `--name=value` parsing shared by both entry points | nothing |
| RateLimiter | Admission control policies and their counters | Foundation, Instrumentation |
| Shortener | Base62 identifiers, link construction and storage | Foundation, Instrumentation |
| Notification | Preferences, deduplication, quiet hours, digest batching | Foundation, Instrumentation |
| Queue | Delayed/priority jobs, leases, retries, dead letters | Foundation, Instrumentation |
| DistributedCache | Rings, LRU nodes, replication, failure fallback | Foundation, Instrumentation |
| Webhook | Subscriptions, signatures, circuit breaker, delivery worker | Foundation, Queue |
| Search | Tokenizer, inverted index, BM25, shards | Foundation, Instrumentation |
| Integration | One deterministic short-link platform scenario | all chapter modules |

## Experiment contract

An experiment is a PHP file under `experiments/*/*.php` that returns an
`Experiment` object. The file must not perform the experiment at include time:
the registry loads every descriptor to build the list, so allocating a large
dataset there would make `make experiment` slow and surprising.

```php
return new Experiment(
    name: 'demo:smoke',
    description: 'Checks the harness end to end.',
    supports: ['elements', 'seed'],
    run: static function (Options $options, Output $output): void {
        // Print prose, record named measurements.
    },
);
```

The name is stable and namespaced (`topic:question`). The `supports` list is
the complete set of options the closure reads; the runner refuses anything
else. Measurements are scalars and are what JSON output carries.

## Benchmark contract

A benchmark file under `benchmarks/` returns a list of `Benchmark` objects.
The iteration count belongs to the benchmark, because one operation may need
thousands of calls to rise above timer noise while another needs one. The
runner adds warm-up and repetitions on top.

A benchmark reports the environment with every result:

- PHP version, OS, kernel, architecture and CPU count;
- `memory_limit`, cgroup memory limit;
- OPcache and JIT state;
- Xdebug mode;
- allocator;
- git commit.

None of these fields is optional. A number without them describes a machine,
not a mechanism.

## Deterministic time

Every mechanism that cares about time takes an explicit `nowMs` or a `Clock`.
Experiments use `VirtualClock` to advance through a lease expiry, a retry
backoff or a quiet-hours boundary. This makes failure sequences reproducible
and keeps wall-clock noise out of correctness tests.

## The integration composition

The scenario is a composition root, not a framework:

```text
ShortLinkPlatformScenario
  ├── RateLimiter\TokenBucket          per tenant
  ├── Shortener\UrlShortener           links and codes
  ├── DistributedCache\CacheCluster    3 nodes, 2 replicas
  ├── Search\SearchCluster             index over link titles/tags
  ├── Notification\NotificationService  plans for users
  ├── Webhook\WebhookDispatcher         outbound events
  └── Queue\JobQueue                    one queue for both job types
```

Each chapter can be read and tested alone. The scenario shows why the
boundaries matter: a rejected request never reaches the shortener, a cache
miss falls through to the repository, a failed webhook becomes a retry and
then a dead letter, and a failed cache node changes which replica answers.
