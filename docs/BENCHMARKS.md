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

