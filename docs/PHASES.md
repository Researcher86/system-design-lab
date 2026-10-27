# System Design Lab — How It Was Built

This is the build journal for the project. Each phase states its goal, the
tasks that made it real, a definition of done, and the tests that hold it. The
work is done in the container; a phase is marked complete only after
`make test`, `make analyse` and `make format-check` pass there, and after CI
reproduces the same result.

Status legend: ✅ complete, 🚧 in progress, ⏳ planned.

---

## 1. Where this project fits

```text
engineering-lab/                     # workspace, not a git repository
├── book/                            # git: Researcher86/engineering-lab-book
│   ├── README.md                    # series table of contents
│   └── system-design-lab/           # seven book chapters (RU)
├── system-design-lab/               # git: Researcher86/system-design-lab — this project
│   ├── src/                         # mechanisms + harness
│   ├── experiments/                 # runnable experiments
│   ├── benchmarks/                  # repeated measurements
│   └── docs/                        # engineering documentation (EN)
├── algorithms-lab/                  # complexity of individual structures
└── php-systems-lab/                 # the same mechanisms inside servers
```

### Roles

| Component | Role |
|---|---|
| `book/system-design-lab` | Russian narrative: seven chapters, each explaining a mechanism on this code |
| `system-design-lab` | Executable source of truth: code, experiments, measurements, engineering docs in English |
| `book/php-systems-lab/tools/check_book.py` | Verifies that code blocks in the book match pinned source |
| `algorithms-lab` | The sibling lab that measures data structures before they become a system |

### Contract with the ecosystem

1. **One chapter = one mechanism.** The order is fixed: 01 Rate Limiter,
   02 URL Shortener, 03 Notification Service, 04 Job Queue, 05 Distributed
   Cache, 06 Webhook Delivery, 07 Search Engine.
2. **One product story.** A multi-tenant short-link platform connects the
   mechanisms in one deterministic MVP scenario.
3. **The book is written from the code.** Implementation, tests and a
   measurement come first; the chapter quotes real files and real output.
4. **Links in the book are pinned.** Every link targets the `v1.0.0` tag or a
   full SHA, never a branch.
5. **Languages.** Code, comments, README and `docs/` are English. Book chapters
   are Russian.
6. **One command per experiment.** `make experiment ARGS="rate:burst"` is the
   interface the book can quote.
7. **The book is checked mechanically.** The checker learns seven chapters for
   this project without weakening the existing 26-chapter and 7-chapter checks.

---

## 2. Principles

1. **Time is one instrument, counters are another.** Admitted requests, lease
   expirations, retries, remappings and postings scanned prove behaviour;
   wall-clock time is secondary.
2. **Failure is a first-class input.** A node dies, a worker crashes after
   sending, an endpoint returns 500, a queue grows faster than it drains.
3. **Baseline first.** Every mechanism has a naive alternative: fixed windows
   beside token buckets, modulo routing beside a ring, single-shard search
   beside a cluster.
4. **Reproducibility is part of the result.** Seed, PHP version, OPcache/JIT,
   Xdebug mode, memory limit and commit travel with every number.
5. **Isolation.** Time and memory are measured in a controlled process; Xdebug
   is off; warm-up and repetitions are mandatory.
6. **Documentation is part of the definition of done.** No report and no
   conclusion means the topic is not finished.
7. **Minimal dependencies.** No runtime dependencies; Composer is for
   development tools only.

### Non-goals

- A production-ready library, a network server or Kubernetes manifests.
- A real database, a real message broker or real email/SMS providers.
- Exactly-once delivery. The lab models at-least-once delivery and idempotency
  because that is what the systems it studies actually provide.
- A UI. The output is a terminal report and JSON measurements.

---

## 3. Stack and standards

| Aspect | Standard | Note |
|---|---|---|
| PHP | `^8.5`, CLI, 64-bit | `php:8.5-cli` in Docker |
| Composer | `researcher86/system-design-lab`, PSR-4 `SystemDesignLab\` → `src/`, `SystemDesignLab\Tests\` → `tests/` | Same shape as the siblings |
| Runtime | No dependencies; `ext-pcntl`, `ext-posix` | Deterministic clocks, child-process memory tests |
| Tests | PHPUnit `^11.5` | `phpunit.xml`, cache directory |
| Static analysis | PHPStan `^2`, level 8 | Paths: src, bin, tests, experiments, benchmarks |
| Style | PHP-CS-Fixer `^3` | `@PHP84Migration`, `@PSR12`, strict types |
| Docker | `php:8.5-cli` + `pcntl`, `posix`, Composer, diagnostic tools | Xdebug installed, off by default |
| Orchestration | `docker-compose.yml`, service `php`, container `system-design-lab` | Project mounted at `/app` |
| Make | `up/down/build/shell/htop/install/test/analyse/format/format-check/check/experiment/benchmark/scenario` | CI calls the same targets |
| CI | GitHub Actions: build, install, test, analyse, format-check, down | No performance thresholds |
| Release | `v1.0.0` after chapters and integration | Book links target it |

### 3.1 Docker is the only supported environment

No host PHP, Composer, PHPUnit or extensions are required:

```text
host
  └── make <target>
        └── docker compose exec php <command>
              └── php:8.5-cli + pcntl + posix + Composer
```

Rules:

1. Every project command goes through `make`.
2. `make` never calls host PHP.
3. CI calls the same targets in the same image.
4. PHP is pinned by the image, Composer by the `composer:2` image.
5. Xdebug is installed but `xdebug.mode=off` by default: the lab measures time.
6. The repository is mounted at `/app`, so `var/` survives container restarts
   but stays out of git.
7. CI runs `make down` in an `always()` step.

Container configuration:

```text
memory_limit=512M
opcache.enable_cli=0
date.timezone=UTC
xdebug.mode=off
```

**Docker on macOS is a virtualized Linux machine.** Absolute timings are
noisier and are not comparable with a native Linux host. Relative comparisons,
operation counters and memory measurements remain useful. Numbers quoted in
the book are taken in one fixed environment and always include the environment
block.

---

## 4. Repository shape

```text
system-design-lab/
├── bin/
│   ├── experiment              # run one experiment by name
│   └── benchmark               # run a benchmark suite
├── src/
│   ├── Foundation/             # random, clock, hash, Zipf, formatting
│   ├── Instrumentation/        # counters, stopwatch, memory meter
│   ├── Experiment/             # descriptor, registry, runner, options, output
│   ├── Benchmark/              # runner, timings, environment, formatters
│   ├── Cli/                    # argument parsing
│   ├── RateLimiter/            # token bucket, fixed window, sliding log, counter
│   ├── Shortener/              # base62, id generators, links, repository
│   ├── Notification/           # preferences, dedup, quiet hours, digests
│   ├── Queue/                  # delayed/priority jobs, leases, retries, DLQ
│   ├── DistributedCache/       # ring, rendezvous, LRU nodes, cluster
│   ├── Webhook/                # subscriptions, signing, circuit breaker, worker
│   ├── Search/                 # tokenizer, inverted index, BM25, shards
│   └── Integration/            # the MVP short-link platform
├── experiments/
│   ├── 00-demo/
│   ├── 01-rate-limiter/
│   ├── 02-shortener/
│   ├── 03-notification/
│   ├── 04-queue/
│   ├── 05-distributed-cache/
│   ├── 06-webhook/
│   ├── 07-search/
│   └── 09-integration/
├── benchmarks/
├── tests/                      # mirrors src/, plus documentation checks
├── docs/
├── var/                        # generated results (gitignored)
├── .github/workflows/ci.yml
├── Dockerfile
├── docker-compose.yml
├── Makefile
├── composer.json
├── phpunit.xml
├── phpstan.neon
└── .php-cs-fixer.dist.php
```

Experiment files are discovered under `experiments/*/*.php`. Each returns an
`Experiment` descriptor; there is no hand-maintained registry.

---

## 5. Chapter map

| # | Book chapter | Module | Core code | Experiments | Benchmark suite |
|---|---|---|---|---|---|
| 01 | Rate Limiter | RateLimiter | TokenBucket, FixedWindowCounter, SlidingWindowLog, SlidingWindowCounter | rate:burst, rate:accuracy, rate:memory | rate-limiter |
| 02 | URL Shortener | Shortener | Base62, SequentialIdGenerator, RandomIdGenerator, UrlShortener, InMemoryLinkRepository | shorten:collisions, shorten:space, shorten:idempotency, shorten:memory | url-shortener |
| 03 | Notification Service | Notification | Preferences, DedupStore, QuietHours, DigestBuffer, NotificationService | notify:fanout, notify:dedup, notify:digest, notify:quiet-hours | notification |
| 04 | Job Queue | Queue | JobQueue, Job, Lease, ExponentialBackoff, ProcessedJobStore | queue:retry, queue:leases, queue:priority, queue:backlog | job-queue |
| 05 | Distributed Cache | DistributedCache | HashRing, RendezvousHash, ModuloHash, LruStore, CacheCluster | cache:remap, cache:hit-ratio, cache:failure, cache:balance | distributed-cache |
| 06 | Webhook Delivery | Webhook | Subscription, Signer, CircuitBreaker, WebhookDispatcher, WebhookWorker | webhook:retry, webhook:duplicates, webhook:fanout, webhook:signature | webhook |
| 07 | Search Engine | Search | Tokenizer, InvertedIndex, Bm25Ranker, SearchCluster | search:index, search:ranking, search:prefix, search:shards | search-engine |
| — | MVP | Integration | ShortLinkPlatformScenario | scenario:short-link-platform | — |

---

## 6. Phase 0 — Repository and quality gates ✅

### Goal

A reproducible environment in which every later experiment runs identically,
with no PHP, Composer or extensions installed on the host.

### Tasks

- [x] `Dockerfile` on `php:8.5-cli` with `pcntl`, `posix`, Composer, Xdebug
      3.5.3 (off by default), `strace`, `time`, `procps`, `htop`.
- [x] PHP config: `memory_limit=512M`, `opcache.enable_cli=0`, UTC.
- [x] `docker-compose.yml` with one `php` service and the project mounted at
      `/app`.
- [x] `Makefile` wrappers for every supported command.
- [x] `composer.json` for `researcher86/system-design-lab`, PSR-4
      `SystemDesignLab\`, PHPUnit, PHPStan level 8, PHP-CS-Fixer.
- [x] `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`.
- [x] `.gitignore`, `.dockerignore`, `.editorconfig`.
- [x] GitHub Actions workflow that runs the same make targets in Docker.
- [x] First foundation classes and their tests.
- [x] Public remote `Researcher86/system-design-lab`; CI green on `master`.

### Definition of Done

```bash
make build
make install
make test
make analyse
make format-check
```

All green in the container and reproduced by CI.

---

## 7. Phase 1 — Foundation and measurement harness ✅

### Goal

One way to run an experiment and one way to measure, before the first
mechanism.

### Foundation

- `DeterministicRandom` — seeded PRNG based on hashing a counter.
- `ZipfDistribution` — deterministic skewed traffic.
- `Hashers` — stable 64-bit-ish hashes for routing and jitter.
- `Clock`, `SystemClock`, `VirtualClock` — explicit time for simulations.
- `ByteFormatter`, `ProjectRoot`.

### Instrumentation

- `Counters` — named operation counts.
- `Stopwatch` — `hrtime(true)`.
- `MemoryMeter` — usage, real usage, peak, baseline.
- `Measure` — the facade an experiment uses.

### Experiment harness

- `Experiment` — readonly descriptor: name, description, supports, closure.
- `Registry` — discovers `experiments/*/*.php`, refuses duplicate names,
  sorts.
- `ExperimentRunner` — validates options, runs the closure, records the result.
- `Options` — declared options and typed getters; unknown options are errors.
- `Output` — prose, tables, measurements; quiet mode for JSON.
- `bin/experiment` — list, run by name, text or JSON.

### Benchmark harness

- `Benchmark` — readonly descriptor: name, iterations, closure.
- `BenchmarkRunner` — warm-up + repetitions, min/max/mean/median/p95/MAD,
  ops/sec from the median, PHP memory delta.
- `Environment` — PHP, OS, CPU, cgroup limits, memory_limit, OPcache, JIT,
  Xdebug mode, allocator, git commit.
- Formatters — text, JSON, CSV.
- `bin/benchmark <suite>`.

### Definition of Done

- `make experiment` prints the list; `make experiment ARGS="demo:smoke"` works.
- `make benchmark ARGS="demo"` prints a table with the environment block.
- `--format=json` is valid; `--output` writes a file.
- `make test analyse format-check` is green.

---

## 8. Phases 2–8 — Chapters 01–07

Every chapter follows the same definition of done:

1. Implementation plus a naive baseline or oracle.
2. Unit, differential and property tests; invariants.
3. Three to five experiments under `experiments/NN-topic/`.
4. A benchmark suite in `benchmarks/`.
5. A results table in `docs/BENCHMARKS.md` and conclusions.
6. Decision entries for non-trivial trade-offs.
7. Book material: code excerpts with file and line ranges, experiment
   commands, real output, two or three failure modes.
8. `make test analyse format-check` green; this document updated.
9. One logical commit.

### Phase 2 — Chapter 01: Rate Limiter — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/RateLimiter/` contains `TokenBucket`, `FixedWindowCounter`,
`SlidingWindowLog`, `SlidingWindowCounter`, `UnlimitedLimiter`, the shared
`RateLimiter` contract and the `Decision` result. Time is an explicit
`nowMs`; token buckets use integer nano-token units to avoid float drift.

**Expected complexity:** all four policies do O(1) work per attempt in the
common case; the sliding log uses O(limit) memory per tracked key, the other
three use O(1) per key.

**Experiments:** `rate:burst` at a window boundary, `rate:accuracy` against an
exact oracle, `rate:memory` at 10,000 keys.

**Result:** 74 tests pass; the fixed window admitted 40 of 60 boundary
requests and reached 20 in a 1,000 ms interval, while the exact log and the
token bucket stayed at 10. The accuracy run recorded 151 false allows and 151
false denies for the fixed window, and 165/166 for the two-window estimate.
The sliding log used 553.8 bytes/key versus 145.7–161.7 for the others. The
development benchmark put the sliding-log allow path at 13.313 ms for 20,000
attempts and the token bucket at 10.789 ms.

**Book:** the boundary double-spend, the difference between an exact log and
an estimate, and why a limiter needs a defined contract for clock and key
scope. Deferred to the book phase.

### Phase 3 — Chapter 02: URL Shortener — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/Shortener/` contains `Base62`, `SequentialIdGenerator`,
`RandomIdGenerator`, `HashIdGenerator`, `UrlShortener`,
`InMemoryLinkRepository`, `ShortLink`, `ShortenResult`, `DuplicateCode` and
`CodeSpaceExhausted`. The generator contract includes the retry attempt:
random and sequential generators ignore it, while the hash generator mixes it
into the input so a collision can actually be escaped. The service checks
tenant + URL before allocating a code, validates custom aliases, and bounds
the collision retry budget.

**Expected complexity:** base62 encode/decode is O(length); a code lookup is
expected O(1); shortening is expected O(1) plus the retry count. The
repository keeps a second index for tenant + URL idempotency, so memory is
roughly two index entries per link.

**Experiments:** `shorten:collisions` (random and hashed draws against the
birthday bound), `shorten:space` (capacity and a real exhaustion run at two
characters), `shorten:idempotency` (Zipf submissions collapse to rows) and
`shorten:memory` (the object, the code index and the tenant + URL index).

**Result:** 143 tests pass. At 20,000 links in a 238,328-code space (8.4%
fill), sequential ids had 0 collisions, random 878 and hashed 881, against a
birthday estimate of 839. A 5,000-draw run into a 3,844-code space exhausted
sequential ids at draw 3,845, random at 2,771 (2,223 collisions) and hashed at
2,795 (2,200 collisions): the retry budget stops random allocation well before
the theoretical capacity. The idempotency run turned 50,000 submissions into
7,172 rows and 42,828 hits, avoiding 7.0x row amplification. Twenty thousand
links cost 303.7 bytes/link as objects, 329.5 with the code index and 475.8
with both indexes; the tenant + URL index alone is 146.3 bytes/link. The
development benchmark put base62 encode at 30.732 ms, decode at 31.771 ms,
sequential ids at 5.219 ms, random ids at 32.856 ms and hashed ids at 24.482 ms
for 100,000 operations.

**Book:** why seven characters are enough capacity but random allocation still
needs collision handling, why idempotency is a second index rather than a free
property, and why the retry budget is part of the service contract. Deferred to
the book phase.

### Phase 4 — Chapter 03: Notification Service — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/Notification/` contains `Channel`, `UserPreferences`,
`PreferenceStore`/`InMemoryPreferenceStore`, `QuietHours`,
`DedupStore`/`InMemoryDedupStore`, `DigestBuffer`, `Notification`,
`NotificationService`, `NotificationResult`, `Delivery`, `DeliveryStatus` and
`Digest`. The service resolves preferences, claims a dedup key per channel,
defers non-urgent messages in quiet hours, batches them in digest mode, and
returns a plan. Urgent messages bypass quiet hours and digest, not dedup.

**Expected complexity:** O(channels) per event for planning;
O(1) expected per preference and dedup lookup; digest storage O(events) until
the day boundary. Quiet hours are O(1); a deferred message does not sleep.

**Experiments:** `notify:fanout` at one, two and three channels;
`notify:dedup` inside and after a TTL; `notify:digest` against immediate
delivery; `notify:quiet-hours` across a 24-hour window.

**Result:** 176 tests pass. Fan-out turned 10,000 events into 10,000, 20,000
and 30,000 channel intents as each channel was enabled. Deduplication sent
20,000 unique messages, suppressed 10,000 duplicate intents inside the TTL and
delivered 10,000 again after it expired, retaining 20,000 keys (one per event
and channel). Digest mode replaced 20,000 immediate messages with 2,000 daily
messages, 10 items per message. Quiet hours 22:00–08:00 deferred 100 of 240
events, with an average deferred delay of 5.4 hours and a maximum of 10 hours.
The development benchmark put preference lookup at 9.257 ms, dedup new key at
21.381 ms, dedup duplicate at 14.713 ms, quiet-hours check at 10.086 ms,
digest add at 4.533 ms, one-channel planning at 26.207 ms and three-channel
planning at 53.702 ms for the stated iteration counts.

**Book:** one event is not one message; dedup is per channel; quiet hours
defer instead of dropping; digest trades latency for message count; urgent is
not a licence to duplicate. Deferred to the book phase.

### Phase 5 — Chapter 04: Job Queue — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/Queue/` contains `Job`, `Lease`, `ExponentialBackoff`,
`ProcessedJobStore`, `FailureStatus`, `FailureOutcome` and `JobQueue`. The
queue keeps three heaps — ready by priority and FIFO, delayed by availability,
and arrivals for backlog age — plus a lease map and an active-job set. A
delayed job is not in the ready heap, so a high-priority job that is not due
yet cannot block a lower-priority job that is due now. `lease()` releases
expired leases, promotes due jobs and extracts the highest-priority ready job.
Delivery is at-least-once; a stale completion or failure is rejected; a job
that exhausts its attempts leaves through the dead-letter list.

**Expected complexity:** enqueue, lease, retry and promotion are O(log n) on
the heaps; the expiration sweep is O(active leases) per call and the arrival
heap is cleaned lazily. Memory is O(ready + delayed + leased + retained
processed ids). The idempotency store retains one completed id per job until
its TTL.

**Experiments:** `queue:retry` over a mixed failing stream,
`queue:leases` with crashing workers, `queue:priority` across five levels and
`queue:backlog` with a producer faster than the consumer.

**Result:** 220 tests pass. Retry run: 1,000 jobs with a three-attempt budget,
every fifth job failing permanently, produced 800 completions and 200 dead
letters through 2,199 lease attempts, 1,399 failures and 1,199 retries; the
total retry delay was 166,500 ms, the replayed completed id was rejected and
the processed store held 800 ids. Lease run: 1,000 jobs, four workers, a
500 ms visibility timeout and a crash every third lease produced 963
completions and 37 dead letters through 1,444 leases, 481 expirations and 444
redeliveries; peak concurrency stayed at four and 90,625 ms of simulated time
passed. Priority run: five levels of 200 jobs were leased strictly by
descending priority, with average waits from 995 ms for the highest level to
8,995 ms for the lowest and zero inversions. Backlog run: producing 100 and
completing 80 jobs per 1,000 ms tick over 100 ticks left 2,000 jobs at depth
and the oldest job at 19,000 ms, with 10,000 produced and 8,000 consumed. The
development benchmark put enqueue-ready at 12.494 ms for 10,000 operations,
enqueue-delayed at 7.653 ms, the enqueue-lease-complete round trip at 30.512 ms
for 20,000, enqueue-lease-fail at 15.678 ms, promote-due at 10.706 ms and
oldest-pending at 12.993 ms for 100,000.

**Book:** why a lease is not a lock, why an expired lease consumes an attempt,
why the delayed heap is separate from the ready heap, why priority without
aging can starve the tail, and why a backlog is measured by age as well as
depth. Deferred to the book phase.

### Phase 6 — Chapter 05: Distributed Cache — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/DistributedCache/` contains the `HashStrategy` contract,
`ModuloHash`, `HashRing`, `RendezvousHash`, `LruStore`, `CacheSource`,
`CacheLookup` and `CacheCluster`. The ring places 150 virtual nodes per
physical node on a 60-bit space, resolves token collisions by probing and
finds the first token clockwise with a binary search. The cluster reads the
primary first, then replicas; it skips failed nodes, serves a replica hit
while the primary is down, and repairs the primary on a later read. A failed
node loses its data by default, recovery starts empty, and writes during a
failure may have fewer copies than the replication factor.

**Expected complexity:** modulo lookup is O(1); ring lookup is O(log V) with V
virtual nodes and O(V) memory; rendezvous lookup is O(nodes × replicas) with
no ring; LRU get/put is O(1) expected because a hit reinserts at the array end.
A cluster read adds O(replicas), and read repair adds one O(1) write.

**Experiments:** `cache:remap` on one membership change, `cache:hit-ratio`
for single, sharded and replicated LRUs at the same total capacity,
`cache:failure` at RF=1 and RF=2, and `cache:balance` for key and traffic
distribution.

**Result:** 273 tests pass. Remapping 20,000 keys from 8 to 9 nodes moved
17,810 keys under modulo hashing (89.0%, expected 88.9%), 2,141 under the ring
(10.7%, expected 11.1%) and 2,190 under rendezvous hashing (10.9%). At a total
capacity of 5,000 entries and 100,000 Zipf requests over 20,000 keys, a single
LRU reached a 79.5% hit ratio, four shards of 1,250 reached 79.5%, eight
shards of 625 reached 79.4%, and four replicated shards at RF=2 reached 73.4%:
at this homogeneity, partitioning barely changed the aggregate hit ratio,
while the second copy cost unique capacity. In the failure run (four nodes,
1,250 entries each, 50,000 warm-up requests and 20,000 requests per phase),
RF=1 fell from 81.0% before the failure to 55.2% during it and recovered to
79.0%; RF=2 moved from 74.0% to 73.5% and back to 73.9%, served 4,779 replica
hits during the failure and performed 214 read repairs after recovery. Both
lost the 1,250 entries held by `cache-0`. With 100,000 requests across eight
nodes, modulo hashing put 20.3% of traffic on its busiest node, the ring with
one token per node 27.7% (with one node receiving no traffic at all), the ring
with 150 virtual nodes 19.2%, and rendezvous 20.1%. The development benchmark
put modulo locate at 38.404 ms for 100,000 lookups, ring locate at 79.945 ms,
ring RF=3 locate at 90.098 ms, rendezvous locate at 578.122 ms, an LRU hit at
18.522 ms, an evicting LRU put at 54.539 ms for 20,000, a cluster primary hit
at 133.203 ms and a replicated cluster put at 29.045 ms for 20,000.

**Book:** why modulo rehashing moves almost everything, what consistent
hashing actually bounds, why virtual nodes are a balance/memory trade-off, why
replication costs hit ratio at a fixed budget and buys availability during a
failure, why an LRU is per node rather than global, and why read repair is a
cache repopulation strategy rather than a consistency guarantee. Deferred to
the book phase.

### Phase 7 — Chapter 06: Webhook Delivery — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/Webhook/` contains `WebhookEvent`, `Subscription`, `Signer`,
`CircuitState`, `CircuitBreaker`, `WebhookRequest`, `WebhookResponse`,
`WebhookTransport`, `WebhookDispatcher`, `WebhookDelivery` and
`WebhookWorker`. The dispatcher filters subscriptions by tenant and event
type and creates one delivery per match. The worker signs the exact payload
with an HMAC over `<timestamp>.<body>`, sends through a transport boundary,
retries with exponential backoff, rejects duplicate enqueues inside a
processed-id TTL and moves exhausted deliveries to a dead-letter list. The
circuit breaker is per endpoint; while open it defers deliveries without
consuming an attempt, and a half-open probe consumes one.

**Expected complexity:** dispatch is O(subscriptions) per event; signing and
verification are O(payload); one delivery attempt is O(1) plus the transport.
The worker scans pending deliveries in `deliverDue()`, so a tick is O(pending)
rather than heap-ordered; the lab keeps the state visible instead of hiding a
scheduler. The processed-id store is O(delivered ids) inside its TTL.

**Experiments:** `webhook:retry` for attempts and dead letters,
`webhook:duplicates` for a lost response and endpoint idempotency,
`webhook:fanout` for event-type filtering, `webhook:signature` for HMAC
rejection cases and `webhook:circuit` for an outage that outlasts the retry
budget with and without a breaker.

**Result:** 330 tests pass. The retry run (1,000 events, four attempts, 100 ms
base) delivered 900 and dead-lettered 100 through 2,200 attempts, 1,300
failures and 1,200 retries, with 190,000 ms of scheduled delay over 700 ms of
simulated time; 900 completed ids were retained. The duplicate run produced
10,000 requests and 10,000 effects with a clean response, but 20,000 requests
and still 10,000 effects when the first response was lost: the endpoint
suppressed 10,000 duplicates by the stable key, the worker retried 10,000
times, and all 10,000 replayed events were rejected by the processed-id
store. Fan-out over 10,000 events and 50 subscriptions with three event types
created 220,003 deliveries (22.0 per event): 83,350 for `link.created` from 25
interested subscriptions, 56,661 for `link.clicked` from 17, and 79,992 for
`link.deleted` from 24. The signature experiment accepted all 10,000 valid
signatures and rejected all 10,000 checks in each of five invalid scenarios:
tampered body, wrong secret, stale timestamp, future timestamp and malformed
header. In the circuit run (100 events, a 5,000 ms outage, six attempts),
without a breaker all 600 attempts hit the down endpoint and all 100
deliveries dead-lettered by 3,100 ms; with a breaker there were 113 attempts,
only 14 of them during the outage, 10 opens, 994 deferrals, 99 deliveries, one
dead letter and recovery at 5,000 ms. The development benchmark put signing at
199.366 ms for 100,000 operations, verification at 228.754 ms, one-subscription
dispatch at 15.113 ms for 20,000, ten-subscription dispatch at 44.474 ms for
10,000, a closed breaker allow at 11.490 ms, an open breaker rejection at
12.747 ms and a successful worker delivery at 88.376 ms for 20,000.

**Book:** why the signature covers the exact bytes and the timestamp, why
timestamp validation is not replay protection by itself, why fan-out is
events × interested subscriptions, why a retry is another attempt of the same
delivery, why an open circuit defers without spending an attempt, why a
breaker can save a delivery that the retry budget alone would kill, and why
the receiver needs an idempotency key. Deferred to the book phase.

### Phase 8 — Chapter 07: Search Engine — code and measurements complete

**Status (2026-10-27):** implementation, tests, experiments and benchmark
suite are complete.

**Code:** `src/Search/` contains `SearchDocument`, `Tokenizer`, `PrefixIndex`,
`InvertedIndex`, `Bm25Ranker`, `SearchHit`, `RankedResults`, `SearchResponse`
and `SearchCluster`. `Corpus` is deterministic experiment input, not part of
the engine. The tokenizer lowercases and splits on non-alphanumerics; the index
stores term-to-document frequencies, document lengths and a lazy sorted
prefix dictionary. `SearchCluster` routes a document by
`Hashers::index($document->id, $shardCount)`, tokenizes a query once, asks
every shard for its top-k, merges by score and document id, and merges prefix
ranges by term. Counters record documents indexed, shard queries, postings
scanned, candidates scored, results returned and prefix rebuilds.

**Expected complexity:** indexing is O(tokens) per document. A query scans the
postings of every query term in every shard and sorts candidates; the
shard-local top-k is O(candidates log candidates) and the merge is
O(shards × top-k log(shards × top-k)). Prefix completion rebuilds a sorted
dictionary in O(T log T) on the first query after a write, then costs
O(log T + returned terms) per shard. Fan-out multiplies the number of shard
requests, not necessarily the total postings.

**Experiments:** `search:index` for tokens, postings, dictionary and PHP
memory; `search:ranking` for rare, common and mixed queries against raw-TF and
BM25 `b=0` baselines; `search:prefix` for lazy rebuilds and exact merged
completions; `search:shards` for fan-out cost and ranking drift against a
single global index.

**Result:** 385 tests and 19,011 assertions pass. The index experiment held
5,000 documents, 412,539 tokens, 274,611 postings and 22,528 dictionary terms;
the corpus used 8.1 MiB and the index added 25.1 MiB (5,273.8 bytes per
document) of PHP allocator memory. A rare topic term had document frequency 17
and scanned 20.3 postings on average; the common `word00001` was in 2,000 of
2,000 documents and scanned 772.1 postings, with only 1.3% of candidates
surviving into top-10. The prefix dictionary held 14,864 terms in one index but
26,953 entries summed over four shards; the first query rebuilt one list
versus four, the second rebuilt none, and both merged to the same 999 terms
and ten completions. Splitting the same 2,000 documents into two, four and
eight shards kept postings and candidates at 384.0 and 380.3 per query, but
recall@10 against the global index fell from 100% to 79.5%/84.0%,
67.4%/75.2% and 64.8%/69.3% for the rare and mixed shapes. The development
benchmark put 100,000 tokenizations at 47.631 ms, a rare BM25 query at
166.622 ms for 20,000, a common BM25 query at 3,279.210 ms for 2,000, prefix
completion at 150.727 ms for 100,000 and a four-shard rare query at 137.961 ms
for 10,000.

**Book:** why an inverted index stores postings rather than documents, why
BM25's IDF and length normalization change the order, why a broad term is
expensive before any ranking happens, why the prefix dictionary is rebuilt
lazily and what a write invalidates, why sharding keeps total postings but
multiplies shard requests, and why shard-local statistics make scores
incomparable. The book must state that there is no stemming, stop-word list,
phrase search, update, delete, replication, network or segment merge.
Deferred to the book phase.

---

## 9. Phase 9 — Integration scenario

One deterministic flow through the whole platform:

```text
requests → rate limiter → shortener → cache / search
         → notification / webhook → job queue → retries
         → cache node failure and recovery → report
```

`make scenario` is the only command needed. The scenario writes a text report
and a JSON result to `var/results/`.

### Phase 9 — Integration scenario — code and measurements complete

**Status (2026-10-27):** the scenario, its report writer, its deterministic
webhook transport and the integration tests are complete.

**Code:** `src/Integration/ShortLinkPlatformScenario.php` composes the seven
chapters. `ScenarioWebhookTransport` verifies the HMAC signature, performs one
side effect per idempotency key, reports a lost response on part of the first
attempts, keeps one endpoint permanently down and counts every attempt.
`ScenarioReport` writes the same measurements as text and JSON; the experiment
also registers them with the harness.

**Flow:** a per-tenant token bucket admits a 900-request stream over 10,000 ms.
Every fifth admitted request creates a link; the rest resolve a catalog code
through a three-node, replication-factor-2 cache and fall back to the
repository on a miss. A create indexes the link, calls the notification
service twice with the same event key, dispatches webhooks, and enqueues one
job per notification channel and per webhook delivery in one queue. The queue
drains with three workers; notification jobs fail deterministically every
seventh job, webhook jobs retry through HMAC-signed requests and the `/down`
endpoint exhausts its attempts. `cache-1` fails at 60% of the request stream
and recovers at 80%; lost entries are served by replicas and repaired.

**Invariants:** the report checks 35 relationships before it is written:
request conservation, admitted work split into creates and resolves, cache
reads versus resolves and misses versus repository fallbacks, one cache
failure and recovery, search documents versus catalog plus creates, two
notification calls per create with three suppressed duplicates, webhook
fan-out, one HTTP attempt per webhook lease, queue conservation
(`completed + dead = enqueued`, `failed = retried + dead`), consumer counts,
processed ids and a fully drained queue. The experiment exits non-zero if any
invariant fails.

**Result:** 396 tests and 19,062 assertions pass. The default run admitted 807
of 900 requests (93 denials) and produced 161 creates and 646 resolves. The
cache served 629 hits and 17 misses (97.4%); failing `cache-1` lost 269
entries, the replicas served 33 hits and repaired 19 primaries, and the queue
finished at 10,626 ms of simulated time. Search indexed 461 documents (300
catalog plus 161 created), answered 10 queries and scanned 461 postings. The
notification replay suppressed 483 channel deliveries; 414 jobs were delivered
and 69 dead-lettered. Webhooks created 161 events and 214 deliveries, made 402
signed attempts with zero invalid signatures, produced 161 effects and
suppressed 29 duplicate effects; the down endpoint dead-lettered all 53 of its
deliveries. The queue enqueued 697 jobs, leased 1,092 times, completed 575,
retried 395 and dead-lettered 122. All 35 invariants passed.

**Book:** the scenario is the composition example that closes the book: it
shows the same at-least-once contract at the queue, the notification
deduplication key and the webhook receiver, and it shows why a cache failure
is a read-path event rather than a data-loss event when replication works.
Deferred to the book phase.

---

## 10. Phase 10 — Release

- `v1.0.0` annotated tag after all seven chapters and the scenario.
- GitHub release notes with chapters, scenario, checks and environment.
- README and this journal updated with the tag and final numbers.

Status: ✅ complete (2026-10-27). The annotated tag `v1.0.0` points at
`aa115e1`; the GitHub release is
[`v1.0.0`](https://github.com/Researcher86/system-design-lab/releases/tag/v1.0.0).
Final benchmark timings were recorded in `0a2663a`; CI run `40497704699` for
that commit is green.

---

## 11. Phase 11 — Book

Seven Russian chapters, each with problem, design, code excerpts, experiment,
failure modes, verification, numbers, exercises and a link to the next
chapter. Code blocks are copied from the tagged source and checked
mechanically.

Status: ✅ complete (2026-10-27). The chapters live in
[`engineering-lab-book/system-design-lab`](https://github.com/Researcher86/engineering-lab-book/tree/master/system-design-lab),
commit `4c4d9c8`. Book CI run `40499796271` is green: 7 chapters, 88 code
blocks, 53 linked blocks matched exactly, 53 GitHub links resolved and 7 local
README links resolved.

---

## 12. Testing and quality

- **Unit:** boundaries, empty structures, one element, duplicates, removal.
- **Differential/property:** random operations against a reference model
  (exact sliding log, PHP array, naive cache, single-shard search).
- **Invariants:** code uniqueness, queue state conservation, cache replica
  distinctness, webhook signature validity, index updates.
- **Memory-limit tests:** separate process with a lowered limit where a
  bounded structure is claimed.
- **Benchmark smoke:** tiny sizes in CI; asserts that the harness runs, not
  that a time threshold was met.
- **DocumentationTest:** every doc is linked from the README, relative links
  resolve, backtick paths exist, documented experiment/benchmark commands
  exist.
- **CI:** `make build install check`, then `make down`.
- One phase equals one logical commit.

---

## 13. Reproducibility and statistics

- Seed, commit and environment travel with every result.
- Benchmarks use warm-up plus repetitions; report median, min/max, p95 and
  MAD; ops/sec comes from the median.
- Memory is measured in a separate process with a baseline,
  `memory_get_usage(false)` and peak; it is PHP allocator memory, not RSS.
- Xdebug is off; OPcache/JIT and `memory_limit` are recorded.
- Deterministic counters need one run, but the spread is still shown for
  benchmarks.
- Docker on macOS adds virtualization noise. Book numbers are taken in one
  fixed environment; every report includes the environment block and says
  that the numbers describe that machine.

---

## 14. Roadmap

| Phase | Content | Artifact |
|---|---|---|
| 0 | Repository, Docker, CI, harness | `make check` green, first commit |
| 1 | Foundation + experiment/benchmark harness | demo experiment + demo benchmark |
| 2 | Chapter 01: Rate Limiter | rate experiments + benchmark |
| 3 | Chapter 02: URL Shortener | shortener experiments + benchmark |
| 4 | Chapter 03: Notification Service | notification experiments + benchmark |
| 5 | Chapter 04: Job Queue | queue experiments + benchmark |
| 6 | Chapter 05: Distributed Cache | cache experiments + benchmark |
| 7 | Chapter 06: Webhook Delivery | webhook experiments + benchmark |
| 8 | Chapter 07: Search Engine | search experiments + benchmark |
| 9 | Integration | `make scenario`, report |
| 10 | Release | `v1.0.0`, release notes |
| 11 | Book | seven chapters, checker green |

---

## 15. Risks and mitigations

| Risk | Consequence | Mitigation |
|---|---|---|
| Book code drifts from the repository | The book lies | Pinned tag + checker in CI |
| Docker on macOS distorts timings | Wrong conclusions | Environment block, counters, fixed reference run |
| Exactly-once claims creep in | False guarantees | Model at-least-once and idempotency explicitly |
| Queue simulation hides infinite backlog | False comfort | Backlog experiment with producer > consumer |
| Cache hit ratio compared across different workloads | Misleading numbers | Same seeded Zipf stream for every policy |
| Scope creep (seven chapters + book) | The project never ends | One mechanism at a time, explicit stretch list |
| Docs go stale | README lies | DocumentationTest in CI |
| Book links to a missing tag | Book CI is red | Tag before switching links; SHA during writing |

---

## 16. MVP and stretch

### MVP

- Seven chapters with tests, experiments, benchmarks and docs.
- One integration scenario.
- Book with seven chapters and a checker.

### Stretch

- Distributed rate limiting with a shared counter.
- Snowflake-style time-ordered identifiers.
- Notification templates with localization.
- Job queue with dead-letter replay.
- Cache TTL, jitter and request coalescing.
- Webhook ordering and replay protection.
- Search phrase queries, stemming and segment merges.
- Charts and a static HTML report.

---

## 17. Resolved decisions

1. **One repository, not seven.** The chapters share the harness and the
   integration scenario.
2. **PHP 8.5 in Docker.** The same environment as the sibling labs.
3. **One product story.** A short-link platform is familiar enough to explain
   each mechanism without a second domain.
4. **Measured, not asserted.** Counters are the primary evidence; time is
   recorded with its environment.
5. **Book after the tag.** The code and numbers stabilize first.
