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
