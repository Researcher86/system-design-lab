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

## 2026-10-27 — Id generators see the retry attempt

`IdGenerator::next()` takes a zero-based attempt number. Sequential and random
generators ignore it; the hash generator mixes it into the input. A hash of
tenant + URL alone is deterministic, so retrying after a collision would return
the same taken code every time and exhaust the budget with no chance of
success. The cost is a slightly wider interface that exposes an allocation
detail; the benefit is that every generator can actually recover from a
collision and the retry budget means the same thing for all of them.

## 2026-10-27 — Deduplication is per channel

The dedup key is tenant + user + channel + event. A user with email and push
has two independent delivery promises for one event, so suppressing the second
channel because the first was sent would silently drop a message. The cost is
one key and one claim per channel; the benefit is that retries and duplicate
events are suppressed exactly where the duplicate would be delivered. The
dedup map retains one expiry per key, and a duplicate does not extend it.

## 2026-10-27 — Quiet hours defer, digest batches

A non-urgent notification that arrives in a quiet window is scheduled for the
first allowed instant, not discarded. Digest mode is a separate opt-in that
holds non-urgent notifications until the next day. Urgent notifications bypass
both because urgency is about latency; they still pass through deduplication
because urgency is not a reason to send the same message twice. The trade-off
is that deferral and digest both need storage and a clock boundary, while
immediate delivery needs neither.

## 2026-10-27 — Idempotency is a second index

The shortener looks up tenant + URL before allocating a code. This makes a
repeated submission return the existing link instead of creating a second row,
but it requires a second index over the same objects. The measured cost is
146.3 bytes/link on top of the code index at 20,000 links. The alternative —
deduplicating by scanning or accepting duplicate rows — moves the cost to
latency or storage. The lab keeps the explicit index and reports it; a
production store would move both indexes to a database and measure pages, not
hash buckets.

## 2026-10-27 — Delayed jobs live in a separate heap

A job that is not due yet must not sit at the front of the ready heap. If both
states shared one heap, a high-priority job scheduled for tomorrow would block
every lower-priority job that is due now. `JobQueue` therefore keeps a ready
heap ordered by priority and FIFO sequence and a delayed heap ordered by
availability; `lease()` promotes due jobs first. The cost is a promotion step
and a second heap; the benefit is that priority means priority among work that
can actually run, not among all known work.

## 2026-10-27 — An expired lease consumes an attempt

A crashed worker and a failed worker both spend one attempt. When a visibility
timeout expires, the job returns to the ready heap immediately and its attempt
counter is not rolled back; if it was the last attempt, the job is
dead-lettered. The alternative — treating a crash as a free retry — turns a
poison job that kills its worker into an infinite redelivery loop. The cost is
that a worker which is merely slow can cause a duplicate execution, which is
exactly why the chapter models at-least-once delivery and idempotent handlers
instead of promising exactly-once.

## 2026-10-27 — Duplicate enqueue is bounded by a TTL

`JobQueue` refuses a job id that is currently active or was completed inside
the `ProcessedJobStore` TTL. This catches the common retry-at-the-producer
duplicate without a permanent seen-set that grows with every job ever run. The
trade-off is explicit: after the TTL a replay is accepted again, and the store
must be cleaned. A production queue would put deduplication in the broker or a
database with the same TTL question.

## 2026-10-27 — Priority is strict, fairness is not modeled

The ready heap breaks ties by arrival sequence and otherwise orders strictly by
priority; the experiment records zero inversions. Strict priority can starve
the tail under a continuous high-priority stream, and the lab says so instead
of hiding it. Aging, weighted fair queueing and per-tenant quotas are the
usual remedies; they are listed as stretch work, not implemented as if the
strict heap were fair.

## 2026-10-27 — Consistent hashing is compared with two baselines

`ModuloHash` is the naive placement: O(1), even for independent keys, and
almost completely remapped when the node count changes. `HashRing` is the
consistent-hash implementation with virtual nodes; `RendezvousHash` is the
ring-free alternative with an O(nodes) lookup. Keeping all three makes the
remap experiment a differential test rather than a claim. The ring stores 150
tokens per node by default: more tokens smooth the arcs, while fewer tokens
save memory and make the partition sizes lumpier.

## 2026-10-27 — Replication is budgeted, not free

At a fixed total cache capacity, RF=2 stores fewer unique keys because every
copy occupies space. The measured cost was 6.1 hit-ratio points in the
homogeneous Zipf run; the measured benefit was a 73.5% hit ratio during a node
failure against 55.2% at RF=1. The lab reports both sides instead of claiming
replication is simply safer. A production cache would also need a repair
budget, a consistency model and a decision about whether cache data must
survive a node loss at all.

## 2026-10-27 — A cache failure loses cache data

`CacheCluster::failNode()` drops the node's entries by default and recovery
starts empty. This is the honest model for an in-memory cache: the origin is
still the source of truth, and the failure turns into misses and reloads. The
alternative — treating the cache as durable — would hide the reload cost and
make the failure experiment meaningless. `loseData: false` exists for a
network partition, where the entries are still there when the node returns.

## 2026-10-27 — Read repair is best-effort repopulation

When a read is served by a replica and the primary is reachable, the cluster
writes the value back to the primary. This shortens the window in which the
primary is cold after recovery, but it is not a consistency protocol: a read
that never happens does not repair anything, and concurrent writes can still
race. The code says "cache", not "replicated store", and the book must not
upgrade read repair into a guarantee.

## 2026-10-27 — LRU order lives in the PHP array

`LruStore` uses insertion order as the recency order: a hit unsets and
reinserts the key at the end, and eviction removes the first key. This is O(1)
expected per operation and one array entry per value, with no linked list and
no second index. The trade-off is that the implementation relies on PHP's
ordered hash map; a language without that property would need an explicit
list. The benchmark measures this implementation, not an abstract LRU.

## 2026-10-27 — Sign the timestamp and the exact payload bytes

`Signer` signs `<timestampMs>.<payload>` with HMAC-SHA256 and emits
`t=...,v1=...`. The payload is already serialized, so verification covers the
same bytes that were sent; the lab does not re-encode JSON after signing. The
timestamp is checked against a tolerance before the digest comparison, which
bounds replay without pretending to solve it. The cost is that every producer
and consumer must agree on the serialization; the benefit is that a signature
cannot be moved to a different body or a different time.

## 2026-10-27 — At-least-once plus idempotency, again

A delivery id is stable across attempts and is sent as the idempotency key.
The worker rejects a duplicate enqueue while the delivery is active or inside
the processed-id TTL, and the simulated receiver keys its side effect by the
same id. The lost-response experiment is the proof obligation: 20,000 HTTP
attempts produced 10,000 effects. The lab does not claim exactly-once
delivery; it claims that the receiver can make the duplicate harmless.

## 2026-10-27 — A circuit open defers without consuming an attempt

`WebhookWorker` asks the per-endpoint breaker before signing and sending. If
the breaker is open, the delivery is rescheduled for the end of the cooldown
and its attempt counter does not move. This is what lets a delivery survive an
outage that is longer than its retry budget: the no-circuit arm dead-lettered
all 100 events by 3,100 ms, while the circuit arm still delivered 99 after the
5,000 ms recovery. A half-open probe is a real send and does consume an
attempt, so a permanently failing endpoint still reaches the dead-letter list.

## 2026-10-27 — Webhook fan-out is filtered by tenant and event type

`WebhookDispatcher` creates one delivery per subscription whose tenant matches
and whose event-type list contains the event, with an empty list meaning all
types. A subscription is not a global broadcast: the measured fan-out was
22.0 deliveries per event across 50 subscriptions because only subsets wanted
each type. The filter is applied before signing or queueing, so an uninterested
subscription costs nothing downstream.

## 2026-10-27 — The worker owns its retry state, the queue supplies the policy

The webhook worker reuses `ExponentialBackoff` and `ProcessedJobStore` from the
queue chapter but not `JobQueue` itself. A queue lease has no concept of
"defer because the endpoint's circuit is open without spending an attempt",
and the breaker is per endpoint rather than per job. The webhook state machine
is small enough to keep explicit; the cost is a second retry implementation,
and the benefit is that the circuit semantics are not smuggled into the queue.
