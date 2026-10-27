# Complexity and Cost — Theory, Counters and Failures

This document records what each mechanism is expected to cost and which
counter is used to check the claim. It is deliberately about operations, not
wall-clock time: a benchmark says how fast one machine ran, a counter says
what the code did.

## The rule

For every chapter, the table has three columns:

- **Expected** — the cost the design predicts;
- **Counter** — the observable that can falsify it;
- **Failure** — the condition under which the expected cost stops describing
  the system.

## Chapters

Filled in as the chapters land.

| Chapter | Expected | Counter | Failure |
|---|---|---|---|
| Rate limiter | token bucket O(1) per key; sliding log O(limit) memory per key | allowed/denied, timestamp entries | a sliding log at high limits is not O(1) memory |
| URL shortener | base62 encode/decode O(length); lookup expected O(1) | collisions, repository reads/writes | random identifiers collide as occupancy grows |
| Notification | O(channels) per accepted event; digest O(1) per user/channel | intents, suppressed duplicates, digest items | fan-out amplification is users × channels |
| Job queue | enqueue/lease O(log n); retry O(log n) | queue depth, retries, expired leases | a producer faster than the consumer grows an unbounded backlog |
| Distributed cache | ring lookup O(log V); rendezvous O(nodes) | hits, misses, remapped keys, fallbacks | one failed node is still a full cache miss for RF=1 keys |
| Webhook | O(subscriptions) fan-out; retry O(attempts) | attempts, circuit opens, dead letters | a retry storm consumes the same capacity as the original load |
| Search | index O(tokens); query O(postings + ranking) | postings scanned, terms, shard queries | a broad term scans a large fraction of the index |

## What is not claimed

- No universal time complexity for PHP arrays or the engine allocator.
- No exactly-once delivery guarantee.
- No claim that a cache reduces origin load to zero: misses, cold starts and
  failures still reach the repository.
- No claim that a queue absorbs unlimited load: it moves the failure from
  latency to backlog and age.
- No claim that a search index is small: the inverted index trades write and
  storage cost for read speed.
