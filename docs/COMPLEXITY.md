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
| Search | index O(tokens); query O(postings + ranking) | postings scanned, terms, shard queries | a broad term scans a large fraction of the index; shard-local statistics change the ranking |

Chapter 02 makes the shortener row concrete. At 20,000 links in a 238,328-code
space, random and hashed generators recorded 878 and 881 failed draws against
a birthday estimate of 839; the sequential control recorded none. In a
two-character space the 20-attempt retry budget stopped random allocation at
2,770 of 3,844 codes (72.1%), so capacity is an upper bound, not a practical
fill target. Idempotency turned 50,000 submissions into 7,172 rows, but the
tenant + URL index cost 146.3 bytes/link on top of the code index — the
duplicate suppression is bought with memory, not assumed.

Chapter 03 makes the notification row concrete. Ten thousand events became
30,000 channel intents with three channels, so fan-out cost is events ×
channels. Deduplication suppressed 10,000 duplicate intents inside a 60,000 ms
TTL but delivered them again after it expired; the store held one key per
event and channel, not one per attempt. Digest mode replaced 20,000 immediate
messages with 2,000 daily messages, moving the cost from message count to
retained events. Quiet hours deferred 100 of 240 events, with an average
5.4-hour delay: the message is delayed, not removed.

Chapter 04 makes the queue row concrete. A 1,000-job retry run with a
three-attempt budget spent 2,199 lease attempts, 1,399 reported failures and
1,199 retries to produce 800 completions and 200 dead letters; the retry delay
doubled from 100 ms and totalled 166,500 ms of simulated time. With four
workers and a 500 ms visibility timeout, crashing on every third lease took
1,444 leases and 481 expirations for 963 completions: an expired lease is a
redelivery and consumes an attempt, so a crash is not a free retry. Five
priority levels of 200 jobs were leased without a single inversion, but the
lowest level waited behind every higher one — the heap is correct, not fair.
A producer of 100 jobs per tick against a consumer of 80 left 2,000 jobs at
depth and the oldest job 19,000 ms old after 100 ticks, so depth alone would
understate the latency.

Chapter 05 makes the cache row concrete. Moving from eight to nine nodes
moved 89.0% of keys under modulo hashing but only 10.7% under a consistent
ring and 10.9% under rendezvous hashing, against an expected 1/9 = 11.1%.
The ring paid O(log V) lookup over 3,000 virtual-node positions; rendezvous
paid an O(nodes) scan and was 7.2x slower per lookup in the development
benchmark. At a fixed total capacity of 5,000 entries, four shards of 1,250
and eight shards of 625 kept the aggregate hit ratio at 79.5% and 79.4%, while
RF=2 fell to 73.4% because the second copy consumed unique capacity. When a
node failed with data loss, RF=1 fell from 81.0% to 55.2% and RF=2 stayed at
73.5% by serving 4,779 replica hits; after recovery, 214 reads repaired the
empty primary. With one virtual node per physical node, the busiest node held
25.1% of keys and one node held none, which is why the ring's balance is a
memory-for-variance trade-off rather than a free property.

Chapter 06 makes the webhook row concrete. Ten thousand events and 50
subscriptions produced 220,003 deliveries because 25, 17 and 24 subscriptions
wanted the three event types; fan-out is events × interested subscriptions,
not events × registered subscriptions. A four-attempt retry run spent 2,200
attempts and 190,000 ms of scheduled delay to deliver 900 events and
dead-letter 100. When the first response was lost after the side effect, the
endpoint saw 20,000 attempts but performed 10,000 effects: the stable delivery
id is the idempotency key. During a 5,000 ms outage, six attempts per delivery
without a breaker produced 600 attempts and 100 dead letters before recovery;
a breaker produced 113 attempts, 14 of them during the outage, deferred 994
due checks without spending an attempt, and delivered 99 of 100. HMAC signing
and verification are O(payload); the breaker is O(1) per check; the worker's
pending scan is O(pending) per tick, which is the simplification this chapter
accepts.

Chapter 07 makes the search row concrete. At 5,000 documents and about 80 body
terms each, the index held 412,539 tokens, 274,611 term-document pairs and
22,528 dictionary terms. The corpus took 8.1 MiB of PHP allocator memory and
the index added 25.1 MiB, or 5,273.8 bytes per document. A rare topic term had
document frequency 17; the most common vocabulary term was present in 2,000 of
2,000 documents. The rare query scanned 20.3 postings on average and returned
10 results from a candidate set of 20.3, so 49.2% of candidates survived into
top-k. The common query scanned 772.1 postings and only 1.3% of its candidates
survived: a broad term pays to score almost the whole index for the same ten
answers. Fan-out kept the total postings scanned at 384.0 and candidates at
380.3 for one, two, four and eight shards, but each query became two, four or
eight shard requests. Shard-local BM25 statistics also changed the order:
recall@10 against the global index fell from 100% at one shard to 79.5%/84.0%
at two, 67.4%/75.2% at four and 64.8%/69.3% at eight. The prefix dictionary
held 14,864 terms in one index but 26,953 entries summed over four shards, and
the first prefix query rebuilt one sorted list in the single index and four in
the cluster; the second query rebuilt none. The merged prefix result was exact
(999 distinct terms on both sides), while the sharded ranking was not.

## What is not claimed

- No universal time complexity for PHP arrays or the engine allocator.
- No exactly-once delivery guarantee.
- No claim that a cache reduces origin load to zero: misses, cold starts and
  failures still reach the repository.
- No claim that a queue absorbs unlimited load: it moves the failure from
  latency to backlog and age.
- No claim that a search index is small: the inverted index trades write and
  storage cost for read speed.
- No claim that sharded BM25 ranks like a global index: the shard experiment
  measured the drift, and the lab does not add a global-statistics phase.
- No claim that the search cluster is distributed: shards live in one process;
  there is no network, replication, segment merge or failure handling.
- No claim that the simple analyzer is a production analyzer: it lowercases
  and splits on non-alphanumerics, with no stemming, stop words or positions.
