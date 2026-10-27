# Benchmarks: How They Are Run, and How to Read Them

This document is the contract the harness satisfies. The measured tables are
appended as each chapter lands.

## The rule

A number without its environment is an anecdote. Every report carries:

```text
Benchmark suite: rate-limiter
PHP 8.5.x on Linux ..., aarch64 x 12, memory_limit 512M, cgroup unlimited
OPcache off, JIT off, Xdebug off, allocator Zend MM
git <sha>
```

The fields are recorded because they explain why the same code produces a
different number somewhere else: OPcache and JIT change how the loop itself
runs, the allocator changes what `memory_get_usage()` means, and the cgroup
limit decides how much of the page cache was available.

## The two loops

- The **inner** loop runs the operation `iterations` times and is what gets
  timed. The count belongs to the benchmark: a cache hit needs many more
  iterations than building a thousand-link index.
- The **outer** loop repeats the whole measurement `repetitions` times, so the
  report has a distribution rather than one lucky number.

Warm-up repetitions run first and are discarded. `ops/sec` is derived from the
median, not the mean; `min` and `max` show how much the number is worth.

## Counters are a separate instrument

Time says how fast this machine ran. Counters say what the mechanism did:
requests admitted, retries scheduled, leases expired, keys remapped, postings
scanned. Complexity and correctness claims are backed by counters; benchmark
tables are backed by time and memory. Both are reported, never mixed into one
number.

## Commands

```bash
make experiment                                  # list every experiment
make experiment ARGS="demo:smoke"
make scenario                                    # integration: the short-link platform
make benchmark ARGS="demo"
```

Each chapter adds its experiments and benchmark suite. The names are stable
and the book quotes the same commands.

## Container numbers

The project runs in Docker on macOS during development. Docker Desktop is a
virtualized Linux machine: absolute timings are noisier and not comparable with
a native Linux host. Relative comparisons, operation counters and memory
measurements remain useful. Numbers quoted in the book are taken in one fixed
environment and always include the block above.

## Results

Results are appended after the chapters are implemented and measured.

### Chapter 01 — Rate limiter

Development run in Docker on macOS (aarch64, PHP 8.5.11, OPcache CLI off, JIT
off, Xdebug off, Zend MM). These are relative observations, not universal
constants.

`make experiment ARGS="rate:burst"` placed six bursts of ten requests at
999/1000/1999/2000/2999/3000 ms. The exact sliding log and the two-window
estimate admitted 30 of 60; the fixed window admitted 40. The fixed window's
largest count in any 1000 ms interval was 20, the other policies' 10.

`make experiment ARGS="rate:accuracy"` replayed 2,000 requests with limit 10
and window 1,000 ms:

| strategy | allowed | false allows | false denies |
|---|---:|---:|---:|
| sliding-log | 200 | 0 | 0 |
| fixed-window | 200 | 151 | 151 |
| sliding-counter | 199 | 165 | 166 |

`make experiment ARGS="rate:memory"` tracked 10,000 keys at limit 10:

| strategy | total | bytes/key |
|---|---:|---:|
| token-bucket | 1.5 MiB | 158.3 |
| fixed-window | 1.4 MiB | 145.7 |
| sliding-log | 5.3 MiB | 553.8 |
| sliding-counter | 1.5 MiB | 161.7 |

The sliding log is exact but stores accepted timestamps; the other policies
keep one record per key. The measurement is PHP allocator memory, not RSS.

`make benchmark ARGS="rate-limiter --repetitions=3"` medians (20,000
iterations): unlimited 4.786 ms, token-bucket allow 10.789 ms, fixed-window
allow 10.343 ms, sliding-log allow 13.313 ms, sliding-counter allow 10.840 ms.
The allow benchmarks create a state record per new key; the deny benchmarks
measure the steady-state rejection path.

### Chapter 02 — URL shortener

Development run in Docker on macOS (aarch64, PHP 8.5.11, OPcache CLI off, JIT
off, Xdebug off, Zend MM). These are relative observations, not universal
constants.

`make experiment ARGS="shorten:collisions"` shortened 20,000 unique links into
a three-character code space (62^3 = 238,328, 8.4% fill) with a retry budget
of 20:

| strategy | created | collisions | per link | fill | exhausted at |
|---|---:|---:|---:|---:|---:|
| sequential | 20,000 | 0 | 0.0000 | 8.4% | — |
| random | 20,000 | 878 | 0.0439 | 8.4% | — |
| hash | 20,000 | 881 | 0.0440 | 8.4% | — |

The birthday estimate for the same creation count is 839 failed draws. Random
and hashed identifiers are close to it; the sequential counter owns the whole
space and never collides.

`make experiment ARGS="shorten:space"` prints the capacity table. The draw
counts are arithmetic from the birthday approximation, not a run:

| length | capacity 62^n | draws at 1% collision | draws at 50% collision |
|---:|---:|---:|---:|
| 1 | 62 | 1 | 9 |
| 2 | 3,844 | 8 | 72 |
| 3 | 238,328 | 69 | 574 |
| 4 | 14,776,336 | 544 | 4,525 |
| 5 | 916,132,832 | 4,291 | 35,637 |
| 6 | 56,800,235,584 | 33,789 | 280,609 |
| 7 | 3,521,614,606,208 | 266,057 | 2,209,523 |

The same experiment then exhausts a two-character space for real with 5,000
attempts:

| strategy | created | fill | collisions | exhausted at |
|---|---:|---:|---:|---:|
| sequential | 3,844 | 100.0% | 0 | 3,845 |
| random | 2,770 | 72.1% | 2,223 | 2,771 |
| hash | 2,794 | 72.7% | 2,200 | 2,795 |

Sequential ids reach the last encodable value. Random and hashed ids stop
earlier because at high occupancy a 20-attempt retry budget keeps drawing
taken codes; the theoretical capacity is not a practical fill target for a
random allocator.

`make experiment ARGS="shorten:idempotency"` replayed 50,000 submissions over
5,000 URLs and 3 tenants with Zipf alpha 1.1:

| metric | value |
|---|---:|
| submissions | 50,000 |
| distinct tenant + URL rows | 7,172 |
| created links | 7,172 |
| idempotent hits | 42,828 |
| rows without the lookup | 50,000 |
| row amplification avoided | 7.0x |
| repository writes | 7,172 |
| repository URL reads | 50,000 |

The row count is bounded by tenant × distinct URLs, not by request volume.
Every submission still pays a URL-index read; the saved work is the second
allocation, row and write.

`make experiment ARGS="shorten:memory"` stored 20,000 seven-character links:

| layout | total | bytes/link |
|---|---:|---:|
| objects only | 5.8 MiB | 303.7 |
| code index | 6.3 MiB | 329.5 |
| code + URL index | 9.1 MiB | 475.8 |
| URL index only | 2.8 MiB | 146.3 |

The object is the floor, the code index adds one hash bucket per link, and the
second index is the price of idempotency. Titles and tags were empty in this
run; they would add allocator blocks per link. The measurement is PHP
allocator memory, not RSS.

`make benchmark ARGS="url-shortener --repetitions=3"` medians:

| benchmark | iterations | median ms | ops/sec |
|---|---:|---:|---:|
| base62 encode 7 | 100,000 | 30.732 | 3,253,946 |
| base62 decode 7 | 100,000 | 31.771 | 3,147,558 |
| sequential id | 100,000 | 5.219 | 19,159,382 |
| random id | 100,000 | 32.856 | 3,043,569 |
| hash id | 100,000 | 24.482 | 4,084,620 |
| shorten new link | 5,000 | 7.852 | 636,811 |
| shorten idempotent hit | 50,000 | 36.367 | 1,374,870 |
| resolve hit | 100,000 | 28.419 | 3,518,747 |
| resolve miss | 100,000 | 25.521 | 3,918,329 |

A new link pays for object construction, two index inserts and a write
counter; an idempotent hit pays for the URL lookup and returns the existing
object. The resolve benchmarks include a code lookup and a counter update.

### Chapter 03 — Notification service

Development run in Docker on macOS (aarch64, PHP 8.5.11, OPcache CLI off, JIT
off, Xdebug off, Zend MM). These are relative observations, not universal
constants.

`make experiment ARGS="notify:fanout"` held 10,000 events constant and enabled
one channel at a time:

| channels | events | channel intents | deliveries | intents/event |
|---:|---:|---:|---:|---:|
| 1 | 10,000 | 10,000 | 10,000 | 1.0 |
| 2 | 10,000 | 20,000 | 20,000 | 2.0 |
| 3 | 10,000 | 30,000 | 30,000 | 3.0 |

`make experiment ARGS="notify:dedup"` used 10,000 events, 5,000 duplicates, 2
channels and a 60,000 ms TTL:

| phase | attempts | intents | delivered | suppressed | suppressed share |
|---|---:|---:|---:|---:|---:|
| unique events | 10,000 | 20,000 | 20,000 | 0 | 0.0% |
| duplicates inside TTL | 5,000 | 10,000 | 0 | 10,000 | 100.0% |
| duplicates after TTL | 5,000 | 10,000 | 10,000 | 0 | 0.0% |

The store retained 20,000 keys — one per event and channel, not one per
attempt. A duplicate does not extend the TTL. At `--ttl-ms=0` the store
disables suppression and the same replay is delivered every time.

`make experiment ARGS="notify:digest"` planned the same 10,000 events for
1,000 users over two channels:

| mode | events | channel intents | messages | items/message | messages/event |
|---|---:|---:|---:|---:|---:|
| immediate | 10,000 | 20,000 | 20,000 | 1.0 | 2.00 |
| digest | 10,000 | 20,000 | 2,000 | 10.0 | 0.2000 |

Digest replaced 20,000 immediate messages with 2,000 daily messages, at the
cost of holding every event until the day boundary.

`make experiment ARGS="notify:quiet-hours"` spread 240 events across 24 UTC
hours with quiet hours 22:00–08:00. The per-hour table is in the experiment
output; the totals were 140 immediate deliveries and 100 deferred deliveries,
with an average deferred delay of 5.4 hours and a maximum of 10 hours. The
window is exclusive at the end: 08:00 is allowed.

`make benchmark ARGS="notification --repetitions=3"` medians:

| benchmark | iterations | median ms | ops/sec |
|---|---:|---:|---:|
| preference lookup | 100,000 | 9.257 | 10,802,149 |
| dedup new key | 100,000 | 21.381 | 4,677,113 |
| dedup duplicate | 100,000 | 14.713 | 6,796,826 |
| quiet hours contains | 100,000 | 10.086 | 9,915,102 |
| digest add | 20,000 | 4.533 | 4,412,089 |
| notify one channel | 20,000 | 26.207 | 763,142 |
| notify three channels | 20,000 | 53.702 | 372,426 |
| notify duplicate | 100,000 | 127.227 | 785,996 |

The one-channel and three-channel planning benchmarks differ by roughly the
fan-out factor. The duplicate path still pays the preference lookup and one
dedup claim per channel, so suppression is cheaper than planning but not free.

