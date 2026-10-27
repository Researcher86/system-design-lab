# Decisions

Dated technical decisions and their trade-offs. A decision is recorded when it
changes how a chapter behaves or what the book may claim.

## 2026-10-27 — One repository, seven mechanisms

The lab is one repository with one harness and one integration scenario.
Seven repositories would duplicate the container, the counters and the
benchmark runners, and the book would need seven remotes. The cost is a larger
repository; the benefit is that the platform scenario can compose the modules.

## 2026-10-27 — Explicit time, not wall-clock time

Every mechanism that has a lease, a retry or a window takes `nowMs` or a
`Clock`. Tests and simulations advance a `VirtualClock`. This makes failure
sequences deterministic. The trade-off is that a real scheduler must supply
the clock and the simulation does not prove anything about sleep accuracy.

## 2026-10-27 — At-least-once, not exactly-once

The queue and webhook chapters model at-least-once delivery with leases,
retries, dead letters and idempotency keys. Exactly-once delivery across a
process boundary is not promised; the lab shows the duplicate and the
suppression instead.

## 2026-10-27 — PHP allocator memory is not RSS

`memory_get_usage(false)` measures the Zend allocator, not resident set size.
Tables label it as PHP memory. A number that looks like "bytes per link" is
the allocator's view and excludes native buffers and the operating system's
page cache.

## 2026-10-27 — The scenario is a composition root

The MVP constructs modules directly instead of introducing a service container
or a framework. The goal is to make the wiring visible in one file. A
production system would need configuration, lifecycle and observability that
this lab does not model.

## 2026-10-27 — Counters before stopwatches

Each chapter reports deterministic counters beside timings. The CI gate never
asserts a time threshold: a virtualized host would make the build flaky. Time
is a measurement for the book, not a correctness test.

## 2026-10-27 — Search is in-process, not a distributed search cluster

The search chapter implements an inverted index, BM25 and shard fan-out in one
process. It does not implement replication, segment merges, a real analyzer or
a network protocol. The shard experiment measures fan-out cost, not cluster
coordination.
