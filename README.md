# System Design Lab

**[🧪 Engineering Lab](https://github.com/Researcher86/engineering-lab-book)** · System design · 7 chapters

> An executable laboratory for the mechanisms behind systems that survive
> load, failures and data growth: admission control, short identifiers,
> asynchronous fan-out, durable work, distributed reads, outbound delivery and
> search.

This repository is the code behind the seven chapters of
[System Design Lab](https://github.com/Researcher86/engineering-lab-book/tree/master/system-design-lab).
The Docker environment, the measurement harness and the seven chapters are
built in public; the build journal lives in [docs/PHASES.md](docs/PHASES.md).

---

## 30-second demo

Requires Docker. Nothing is installed on the host.

```bash
make build       # build php:8.5-cli with the project extensions
make install     # composer install inside the container
make test        # PHPUnit
make check       # PHPUnit + PHPStan level 8 + PHP-CS-Fixer
```

The harness is discoverable:

```bash
make experiment                              # list experiments
make experiment ARGS="demo:smoke"            # run the smoke test
make scenario                                # the one end-to-end flow
make benchmark ARGS="demo"                   # text report with environment
```

Chapters 01, 02 and 03 are runnable as well:

```bash
make experiment ARGS="rate:burst"
make experiment ARGS="rate:accuracy"
make experiment ARGS="rate:memory"
make benchmark ARGS="rate-limiter --repetitions=5"

make experiment ARGS="shorten:collisions"
make experiment ARGS="shorten:space"
make experiment ARGS="shorten:idempotency"
make experiment ARGS="shorten:memory"
make benchmark ARGS="url-shortener --repetitions=5"

make experiment ARGS="notify:fanout"
make experiment ARGS="notify:dedup"
make experiment ARGS="notify:digest"
make experiment ARGS="notify:quiet-hours"
make benchmark ARGS="notification --repetitions=5"
```

Open a shell in the same container with `make shell`; stop it with `make down`.

## What it does

| | |
|---|---|
| **Docker-first** | The only supported environment is the project container; `make` never calls host PHP |
| **PHP 8.5** | `php:8.5-cli`, strict types, no runtime framework |
| **Seven mechanisms** | Rate limiter, URL shortener, notification service, job queue, distributed cache, webhook delivery and search |
| **One product story** | A multi-tenant short-link platform uses every mechanism in one deterministic scenario |
| **Measured, not asserted** | Seeds, operation counters, memory and repeated timings travel with every result |
| **Book contract** | Every chapter quotes real output and links to code pinned at `v1.0.0` |

## Chapters

The order is fixed by the book. Implementation follows the same order.

| # | Chapter | What the code shows | Status |
|---|---|---|---|
| 01 | Rate Limiter | token bucket, fixed window, sliding log and sliding counter under the same request stream | ✅ code and measurements |
| 02 | URL Shortener | base62 identifiers, sequential, random and hashed generation, collisions and idempotency | ✅ code and measurements |
| 03 | Notification Service | preferences, deduplication, quiet hours, fan-out and digest batching | ✅ code and measurements |
| 04 | Job Queue | priority and delay, leases, visibility timeouts, retries and dead letters | ⏳ planned |
| 05 | Distributed Cache | consistent hashing, LRU nodes, replication and failure fallback | ⏳ planned |
| 06 | Webhook Delivery | HMAC signatures, retries, circuit breaking, idempotency and dead letters | ⏳ planned |
| 07 | Search Engine | inverted index, BM25 ranking, prefix lookup and shard fan-out | ⏳ planned |

## The MVP scenario

`make scenario` runs one deterministic flow through the whole platform:

```text
tenant requests
      ↓
rate limiter admits or rejects
      ↓
shortener creates / resolves links
      ↓
create: search index + notification + webhook
resolve: distributed cache, then repository on a miss
      ↓
job queue delivers notifications and webhooks with retries
      ↓
one cache node fails and comes back
      ↓
report: funnel, hit ratio, retries, dead letters and invariants
```

The scenario is one command, one seed and one report. It is not a benchmark;
the chapter benchmarks are separate.

## Documentation

- [docs/PHASES.md](docs/PHASES.md) — the build journal and full plan: phases, goals and definitions of done.
- [docs/DECISIONS.md](docs/DECISIONS.md) — dated technical decisions and trade-offs.
- [docs/BENCHMARKS.md](docs/BENCHMARKS.md) — how measurements are taken and read.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — the harness and module map.
- [docs/COMPLEXITY.md](docs/COMPLEXITY.md) — theory, counters and measured behavior.

## Project structure

```text
system-design-lab/
├── bin/                  # experiment and benchmark entry points
├── src/                  # implementations, instrumentation, harness
├── experiments/          # one runnable experiment per question
├── benchmarks/           # repeated measurements per topic
├── tests/                # mirrors src/, plus documentation checks
├── docs/                 # engineering documentation (English)
├── var/                  # generated results (gitignored)
├── Dockerfile
├── docker-compose.yml
├── Makefile
└── composer.json
```

## How this project relates

- **[Engineering Lab book](https://github.com/Researcher86/engineering-lab-book)** —
  the Russian narrative: one chapter per mechanism, code excerpts pinned to a
  tag, experiments quoted with their real output.
- **[Algorithms Lab](https://github.com/Researcher86/algorithms-lab)** — the
  cost of individual data structures measured in isolation.
- **System Design Lab** — the place where those structures become a system
  with tenants, failures, retries and an operational budget.

## License

MIT.
