<?php

declare(strict_types=1);

namespace SystemDesignLab\Integration;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SystemDesignLab\DistributedCache\CacheCluster;
use SystemDesignLab\DistributedCache\HashRing;
use SystemDesignLab\DistributedCache\LruStore;
use SystemDesignLab\Foundation\DeterministicRandom;
use SystemDesignLab\Foundation\Hashers;
use SystemDesignLab\Foundation\ZipfDistribution;
use SystemDesignLab\Notification\Delivery;
use SystemDesignLab\Notification\DigestBuffer;
use SystemDesignLab\Notification\InMemoryDedupStore;
use SystemDesignLab\Notification\InMemoryPreferenceStore;
use SystemDesignLab\Notification\Notification;
use SystemDesignLab\Notification\NotificationService;
use SystemDesignLab\Notification\UserPreferences;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\FailureStatus;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\Lease;
use SystemDesignLab\Queue\ProcessedJobStore;
use SystemDesignLab\RateLimiter\TokenBucket;
use SystemDesignLab\Search\Corpus;
use SystemDesignLab\Search\SearchCluster;
use SystemDesignLab\Search\SearchDocument;
use SystemDesignLab\Shortener\InMemoryLinkRepository;
use SystemDesignLab\Shortener\SequentialIdGenerator;
use SystemDesignLab\Shortener\ShortLink;
use SystemDesignLab\Shortener\UrlShortener;
use SystemDesignLab\Webhook\Signer;
use SystemDesignLab\Webhook\Subscription;
use SystemDesignLab\Webhook\WebhookDelivery;
use SystemDesignLab\Webhook\WebhookDispatcher;
use SystemDesignLab\Webhook\WebhookEvent;
use SystemDesignLab\Webhook\WebhookRequest;

/**
 * The MVP flow: one deterministic stream through the whole lab.
 *
 * The scenario is deliberately composition-heavy rather than another data
 * structure. It admits tenant traffic through a token bucket, creates and
 * resolves short links, reads a three-node cache with replication, indexes
 * links in a sharded search cluster, plans notifications and webhooks, and
 * turns both plans into queue jobs. A cache node fails at 60% of the request
 * stream and comes back at 80%; the queue retries transient failures and
 * dead-letters the permanently down webhook endpoint.
 *
 * All time is simulated: the request stream spans 10,000 ms and the queue is
 * drained with an explicit clock. A run with the same seed and options produces
 * the same report. The class is intentionally single-use.
 */
final class ShortLinkPlatformScenario
{
    private readonly int $linksPerTenant;

    private readonly int $catalogSize;

    private readonly DeterministicRandom $random;

    private readonly ZipfDistribution $zipf;

    private readonly InMemoryLinkRepository $repository;

    private readonly UrlShortener $shortener;

    private readonly SearchCluster $search;

    private readonly CacheCluster $cache;

    private readonly TokenBucket $rateLimiter;

    private readonly NotificationService $notifications;

    private readonly WebhookDispatcher $webhooks;

    private readonly Signer $signer;

    private readonly ScenarioWebhookTransport $transport;

    private readonly JobQueue $queue;

    /** @var list<string> */
    private array $catalogCodes = [];

    /** @var array<string, true> */
    private array $topics = [];

    /** @var array<string, Delivery> */
    private array $notificationPayloads = [];

    /** @var array<string, WebhookDelivery> */
    private array $webhookPayloads = [];

    private int $admitted = 0;

    private int $denied = 0;

    private int $creates = 0;

    private int $resolves = 0;

    private int $tenant0Creates = 0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private int $repositoryFallbacks = 0;

    private int $cacheFailures = 0;

    private int $cacheRecoveries = 0;

    private int $cacheLostEntries = 0;

    private int $notificationJobs = 0;

    private int $notificationDuplicates = 0;

    private int $notificationLeases = 0;

    private int $notificationFailures = 0;

    private int $notificationDelivered = 0;

    private int $notificationDeadLettered = 0;

    private int $webhookJobs = 0;

    private int $webhookLeases = 0;

    private int $webhookFailures = 0;

    private int $webhookDelivered = 0;

    private int $webhookDeadLettered = 0;

    private int $downDeliveries = 0;

    private int $downDeadLettered = 0;

    private int $peakQueueDepth = 0;

    public function __construct(
        private readonly string $seed = 'short-link-platform',
        private readonly int $requests = 900,
        private readonly int $tenants = 3,
        private readonly int $users = 20,
        private readonly int $links = 300,
        private readonly int $capacity = 20,
        private readonly float $refillPerSecond = 25.0,
        private readonly int $nodes = 3,
        private readonly int $replicas = 2,
        private readonly int $shards = 4,
        private readonly int $maxAttempts = 4,
        private readonly int $failEvery = 7,
        private readonly int $delayMs = 50,
        private readonly int $failMs = 100,
    ) {
        if (trim($this->seed) === '') {
            throw new InvalidArgumentException('Scenario seed cannot be empty');
        }

        if ($this->requests < 10) {
            throw new InvalidArgumentException('The scenario needs at least 10 requests');
        }

        if ($this->tenants < 1) {
            throw new InvalidArgumentException('The scenario needs at least one tenant');
        }

        if ($this->users < 1) {
            throw new InvalidArgumentException('The scenario needs at least one user');
        }

        if ($this->links < 1) {
            throw new InvalidArgumentException('The scenario needs at least one catalog link');
        }

        if ($this->capacity < 1) {
            throw new InvalidArgumentException('Token-bucket capacity must be at least 1');
        }

        if ($this->refillPerSecond < 0.0) {
            throw new InvalidArgumentException('Token-bucket refill cannot be negative');
        }

        if ($this->nodes < 2) {
            throw new InvalidArgumentException('The scenario needs at least two cache nodes');
        }

        if ($this->replicas < 1 || $this->replicas > $this->nodes) {
            throw new InvalidArgumentException('Cache replicas must be between 1 and the node count');
        }

        if ($this->shards < 1) {
            throw new InvalidArgumentException('The search cluster needs at least one shard');
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1');
        }

        if ($this->failEvery < 0) {
            throw new InvalidArgumentException('Failure cadence cannot be negative');
        }

        if ($this->delayMs < 1) {
            throw new InvalidArgumentException('Retry base delay must be at least 1 ms');
        }

        if ($this->failMs < 0) {
            throw new InvalidArgumentException('Webhook recovery time cannot be negative');
        }

        $this->linksPerTenant = max(1, intdiv($this->links, $this->tenants));
        $this->catalogSize = $this->linksPerTenant * $this->tenants;
        $this->random = new DeterministicRandom($this->seed . ':requests');
        $this->zipf = new ZipfDistribution($this->catalogSize, 1.1);
        $this->repository = new InMemoryLinkRepository();
        $this->shortener = new UrlShortener(
            new SequentialIdGenerator(),
            $this->repository,
            codeLength: 7,
            maxAttempts: 20,
        );
        $this->search = new SearchCluster($this->shards);
        $this->cache = $this->buildCache();
        $this->rateLimiter = new TokenBucket($this->capacity, $this->refillPerSecond);
        $this->notifications = new NotificationService(
            new InMemoryPreferenceStore(UserPreferences::all()),
            new InMemoryDedupStore(ttlMs: 60_000, cleanupInterval: 0),
            new DigestBuffer(),
        );
        $this->signer = new Signer();
        $this->webhooks = new WebhookDispatcher();
        $this->transport = new ScenarioWebhookTransport(
            $this->subscribeWebhooks(),
            $this->failMs,
            $this->signer,
        );
        $this->queue = new JobQueue(
            new ExponentialBackoff(
                baseMs: $this->delayMs,
                multiplier: 2.0,
                maxMs: max(10_000, $this->delayMs),
                jitterMs: 0,
            ),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );
    }

    /**
     * @return array<string, int|float|string>
     */
    public function options(): array
    {
        return [
            'seed' => $this->seed,
            'requests' => $this->requests,
            'tenants' => $this->tenants,
            'users' => $this->users,
            'links' => $this->links,
            'capacity' => $this->capacity,
            'refill-per-second' => $this->refillPerSecond,
            'nodes' => $this->nodes,
            'replicas' => $this->replicas,
            'shards' => $this->shards,
            'max-attempts' => $this->maxAttempts,
            'fail-every' => $this->failEvery,
            'delay-ms' => $this->delayMs,
            'fail-ms' => $this->failMs,
        ];
    }

    public function run(): ScenarioReport
    {
        $report = new ScenarioReport();
        $report->heading(
            'Multi-tenant short-link platform',
            sprintf(
                '%s requests, %d tenants, %d catalog links, seed "%s".',
                number_format($this->requests),
                $this->tenants,
                $this->catalogSize,
                $this->seed,
            ),
        );

        $this->primeCatalog();

        $failAt = max(1, intdiv($this->requests * 6, 10));
        $recoverAt = max($failAt + 1, intdiv($this->requests * 8, 10));
        $initialCache = $this->cache->counters()->toArray();
        $failureCache = $initialCache;
        $recoveryCache = $initialCache;

        for ($request = 0; $request < $this->requests; ++$request) {
            $now = intdiv($request * 10_000, $this->requests);

            if ($request === $failAt) {
                $failureCache = $this->cache->counters()->toArray();
                $this->cacheLostEntries = $this->cache->failNode('cache-1');
                ++$this->cacheFailures;
            }

            if ($request === $recoverAt) {
                $recoveryCache = $this->cache->counters()->toArray();
                $this->cache->recoverNode('cache-1');
                ++$this->cacheRecoveries;
            }

            $tenantId = $this->tenantId($request % $this->tenants);
            $decision = $this->rateLimiter->attempt($tenantId, $now);

            if (!$decision->allowed) {
                ++$this->denied;

                continue;
            }

            ++$this->admitted;

            if ($request % 5 === 0) {
                $this->createLink($tenantId, $now);
            } else {
                $this->resolveLink();
            }

            $this->peakQueueDepth = max(
                $this->peakQueueDepth,
                $this->queue->pending() + $this->queue->leaseCount(),
            );
        }

        $cacheBefore = self::hitRatio($initialCache, $failureCache);
        $cacheDuring = self::hitRatio($failureCache, $recoveryCache);
        $cacheAfter = self::hitRatio($recoveryCache, $this->cache->counters()->toArray());

        $drainedAt = $this->drainQueue();
        $search = $this->runSearch();

        $this->writeReport($report, $cacheBefore, $cacheDuring, $cacheAfter, $drainedAt, $search);

        return $report;
    }

    private function buildCache(): CacheCluster
    {
        $ring = new HashRing(150);
        $stores = [];
        $capacity = max(64, intdiv($this->catalogSize * 4, $this->nodes));

        for ($index = 0; $index < $this->nodes; ++$index) {
            $node = 'cache-' . $index;
            $ring->addNode($node);
            $stores[$node] = new LruStore($capacity);
        }

        return new CacheCluster($ring, $stores, $this->replicas);
    }

    /**
     * @return array<string, string> URL => secret
     */
    private function subscribeWebhooks(): array
    {
        $secrets = [];

        for ($index = 0; $index < $this->tenants; ++$index) {
            $tenantId = $this->tenantId($index);
            $url = 'https://hooks.example.test/' . $tenantId;
            $secret = 'secret-' . $tenantId;
            $this->webhooks->subscribe(new Subscription(
                'main-' . $tenantId,
                $tenantId,
                $url,
                $secret,
                ['link.created'],
            ));
            $secrets[$url] = $secret;
        }

        $tenant0 = $this->tenantId(0);
        $downUrl = 'https://hooks.example.test/' . $tenant0 . '/down';
        $downSecret = 'secret-down-' . $tenant0;
        $this->webhooks->subscribe(new Subscription(
            'down-' . $tenant0,
            $tenant0,
            $downUrl,
            $downSecret,
            ['link.created'],
        ));
        $secrets[$downUrl] = $downSecret;

        return $secrets;
    }

    private function primeCatalog(): void
    {
        for ($tenant = 0; $tenant < $this->tenants; ++$tenant) {
            $tenantId = $this->tenantId($tenant);

            for ($link = 0; $link < $this->linksPerTenant; ++$link) {
                $topic = Corpus::topicTerm(($tenant * 7 + $link) % 10);
                $longUrl = sprintf('https://%s.example.test/catalog/%d', $tenantId, $link);
                $title = sprintf('Catalog link %d of %s about %s', $link, $tenantId, $topic);
                $result = $this->shortener->shorten(
                    tenantId: $tenantId,
                    longUrl: $longUrl,
                    title: $title,
                    tags: [$topic, $link % 2 === 0 ? 'launch' : 'docs'],
                    createdAtMs: 0,
                );
                $this->topics[$topic] = true;
                $this->catalogCodes[] = $result->link->code;

                // Nine of ten catalog entries are warm; the rest exercise the
                // cache-aside path on their first resolve.
                $warm = Hashers::hash64($result->link->code) % 10 !== 0;
                $this->indexAndCache($result->link, $warm);
            }
        }
    }

    private function createLink(string $tenantId, int $now): void
    {
        $index = $this->creates;
        $topic = Corpus::topicTerm($index % 10);
        $longUrl = sprintf('https://%s.example.test/articles/%d', $tenantId, $index);
        $title = sprintf('Article %d by %s about %s', $index, $tenantId, $topic);
        $result = $this->shortener->shorten(
            tenantId: $tenantId,
            longUrl: $longUrl,
            title: $title,
            tags: [$topic, 'launch'],
            createdAtMs: $now,
        );
        $link = $result->link;
        ++$this->creates;
        $this->topics[$topic] = true;
        $this->indexAndCache($link, true);
        $this->notifyCreate($link, $now);
        $this->dispatchCreate($link, $now);

        if ($tenantId === $this->tenantId(0)) {
            ++$this->tenant0Creates;
        }
    }

    private function resolveLink(): void
    {
        $rank = $this->zipf->sample($this->random);
        $code = $this->catalogCodes[$rank - 1] ?? throw new LogicException('The catalog is empty');
        $key = 'link:' . $code;
        $lookup = $this->cache->get($key);

        if ($lookup->found) {
            if (!$lookup->value instanceof ShortLink) {
                throw new LogicException(sprintf('Cache key %s does not hold a short link', $key));
            }

            ++$this->cacheHits;
            ++$this->resolves;

            return;
        }

        ++$this->cacheMisses;
        $link = $this->shortener->resolve($code);

        if ($link === null) {
            throw new LogicException(sprintf('Catalog code %s is missing from the repository', $code));
        }

        $this->cache->put($key, $link);
        ++$this->repositoryFallbacks;
        ++$this->resolves;
    }

    private function indexAndCache(ShortLink $link, bool $warm): void
    {
        $documentId = 'link:' . $link->code;
        $this->search->addDocument(new SearchDocument(
            id: $documentId,
            title: $link->title,
            body: trim($link->title . ' ' . implode(' ', $link->tags)),
        ));

        if ($warm) {
            $this->cache->put($documentId, $link);
        }
    }

    private function notifyCreate(ShortLink $link, int $now): void
    {
        $notification = new Notification(
            eventKey: 'link.created:' . $link->code,
            tenantId: $link->tenantId,
            userId: sprintf('user-%02d', Hashers::hash64($link->code) % $this->users),
            title: 'Short link created',
            body: $link->url(),
            createdAtMs: $now,
        );
        $first = $this->notifications->notify($notification);
        $replay = $this->notifications->notify($notification);
        $this->notificationDuplicates += $replay->suppressedDuplicates;

        foreach ($first->deliveries as $delivery) {
            $jobId = 'notify:' . $link->code . ':' . $delivery->channel->value;
            $job = new Job(
                id: $jobId,
                type: 'notification',
                priority: 0,
                createdAtMs: $now,
                maxAttempts: $this->maxAttempts,
            );

            if (!$this->queue->enqueue($job, $now)) {
                throw new LogicException(sprintf('Notification job %s was rejected', $jobId));
            }

            $this->notificationPayloads[$jobId] = $delivery;
            ++$this->notificationJobs;
        }
    }

    private function dispatchCreate(ShortLink $link, int $now): void
    {
        $payload = json_encode([
            'event' => 'link.created',
            'code' => $link->code,
            'url' => $link->url(),
            'tenant' => $link->tenantId,
            'tags' => $link->tags,
        ], JSON_THROW_ON_ERROR);
        $event = new WebhookEvent(
            id: 'event-' . $link->code,
            tenantId: $link->tenantId,
            type: 'link.created',
            payload: $payload,
            createdAtMs: $now,
        );

        foreach ($this->webhooks->dispatch($event) as $delivery) {
            $jobId = 'webhook:' . $delivery->id;
            $job = new Job(
                id: $jobId,
                type: 'webhook',
                priority: 10,
                createdAtMs: $now,
                maxAttempts: $this->maxAttempts,
            );

            if (!$this->queue->enqueue($job, $now)) {
                throw new LogicException(sprintf('Webhook job %s was rejected', $jobId));
            }

            $this->webhookPayloads[$jobId] = $delivery;
            ++$this->webhookJobs;

            if ($delivery->subscription->id === 'down-' . $this->tenantId(0)) {
                ++$this->downDeliveries;
            }
        }
    }

    /**
     * @return array{
     *     queries: int,
     *     non_empty: int,
     *     postings: int,
     *     candidates: int,
     *     prefix_terms: int,
     *     rebuilds_first: int,
     *     rebuilds_second: int
     * }
     */
    private function runSearch(): array
    {
        $topics = array_keys($this->topics);
        sort($topics, SORT_STRING);
        $queries = 0;
        $nonEmpty = 0;
        $postings = 0;
        $candidates = 0;

        foreach ($topics as $topic) {
            $response = $this->search->search($topic, 10);
            ++$queries;
            $postings += $response->postingsScanned;
            $candidates += $response->candidatesScored;

            if ($response->results !== []) {
                ++$nonEmpty;
            }
        }

        $prefixTerms = $this->search->complete('topic', 5);
        $rebuildsFirst = $this->search->prefixRebuilds();
        $this->search->complete('topic', 5);
        $rebuildsSecond = $this->search->prefixRebuilds();

        return [
            'queries' => $queries,
            'non_empty' => $nonEmpty,
            'postings' => $postings,
            'candidates' => $candidates,
            'prefix_terms' => count($prefixTerms),
            'rebuilds_first' => $rebuildsFirst,
            'rebuilds_second' => $rebuildsSecond,
        ];
    }

    private function drainQueue(): int
    {
        $now = 10_000;
        $workers = ['worker-1', 'worker-2', 'worker-3'];
        $deadline = $now + max(120_000, $this->maxAttempts * max(10_000, $this->delayMs) + 60_000);

        while ($this->queue->pending() > 0 || $this->queue->leaseCount() > 0) {
            if ($now > $deadline) {
                throw new RuntimeException(sprintf(
                    'The queue still holds %d jobs after %d ms',
                    $this->queue->pending() + $this->queue->leaseCount(),
                    $deadline,
                ));
            }

            $this->queue->tick($now);

            foreach ($workers as $workerId) {
                $lease = $this->queue->lease($workerId, $now, 30_000);

                if ($lease === null) {
                    continue;
                }

                $this->processLease($lease, $now);
            }

            ++$now;
        }

        return $now;
    }

    private function processLease(Lease $lease, int $now): void
    {
        $job = $lease->job;

        if ($job->type === 'notification') {
            ++$this->notificationLeases;
            $delivery = $this->notificationPayloads[$job->id]
                ?? throw new LogicException(sprintf('Unknown notification job %s', $job->id));

            if ($this->shouldFail($job->id)) {
                ++$this->notificationFailures;
                $outcome = $this->queue->fail(
                    $lease,
                    $now,
                    sprintf('notification %s transport failed', $delivery->channel->value),
                );

                if ($outcome->status === FailureStatus::DeadLettered) {
                    ++$this->notificationDeadLettered;
                }

                return;
            }

            $this->queue->complete($lease, $now);
            ++$this->notificationDelivered;

            return;
        }

        if ($job->type === 'webhook') {
            ++$this->webhookLeases;
            $delivery = $this->webhookPayloads[$job->id]
                ?? throw new LogicException(sprintf('Unknown webhook job %s', $job->id));
            $body = $delivery->event->payload;
            $request = new WebhookRequest(
                url: $delivery->subscription->url,
                body: $body,
                signature: $this->signer->sign($delivery->subscription->secret, $body, $now),
                idempotencyKey: $delivery->idempotencyKey(),
                attempt: $job->attempts(),
                timestampMs: $now,
                eventId: $delivery->event->id,
                subscriptionId: $delivery->subscription->id,
            );
            $response = $this->transport->send($request);

            if ($response->isSuccessful()) {
                $this->queue->complete($lease, $now);
                ++$this->webhookDelivered;

                return;
            }

            ++$this->webhookFailures;
            $outcome = $this->queue->fail($lease, $now, $response->error);

            if ($outcome->status === FailureStatus::DeadLettered) {
                ++$this->webhookDeadLettered;

                if ($delivery->subscription->id === 'down-' . $this->tenantId(0)) {
                    ++$this->downDeadLettered;
                }
            }

            return;
        }

        throw new LogicException(sprintf('Unknown job type: %s', $job->type));
    }

    private function shouldFail(string $jobId): bool
    {
        return $this->failEvery > 0 && Hashers::hash64($jobId) % $this->failEvery === 0;
    }

    /**
     * @param array{
     *     queries: int,
     *     non_empty: int,
     *     postings: int,
     *     candidates: int,
     *     prefix_terms: int,
     *     rebuilds_first: int,
     *     rebuilds_second: int
     * } $search
     */
    private function writeReport(
        ScenarioReport $report,
        float $cacheBefore,
        float $cacheDuring,
        float $cacheAfter,
        int $drainedAt,
        array $search,
    ): void {
        $cacheCounters = $this->cache->counters();
        $notificationCounters = $this->notifications->counters();
        $webhookCounters = $this->webhooks->counters();
        $queueCounters = $this->queue->counters();

        $cacheReads = $cacheCounters->get('reads');
        $cacheHits = $cacheCounters->get('primaryHits') + $cacheCounters->get('replicaHits');
        $cacheMisses = $cacheCounters->get('misses');
        $cacheReplicaHits = $cacheCounters->get('replicaHits');
        $cacheRepairs = $cacheCounters->get('readRepairs');
        $overallHitRatio = $cacheReads === 0 ? 0.0 : $cacheHits / $cacheReads;
        $admissionRate = $this->admitted / $this->requests;

        $searchDocuments = $this->search->documentsIndexed();
        $searchPostings = $this->search->postingsCount();
        $searchDictionary = $this->search->dictionarySize();

        $notificationEvents = $notificationCounters->get('events');
        $notificationIntents = $notificationCounters->get('channelIntents');
        $notificationPlanned = $notificationCounters->get('planned');
        $notificationSuppressed = $notificationCounters->get('suppressedDuplicates');

        $webhookEvents = $webhookCounters->get('events');
        $webhookDeliveries = $webhookCounters->get('deliveriesCreated');
        $webhookAttempts = $this->transport->attempts();
        $webhookEffects = $this->transport->effects();
        $webhookSuppressed = $this->transport->suppressedDuplicates();
        $webhookInvalid = $this->transport->invalidSignatures();

        $enqueued = $queueCounters->get('enqueued');
        $leased = $queueCounters->get('leased');
        $completed = $queueCounters->get('completed');
        $failed = $queueCounters->get('failed');
        $retried = $queueCounters->get('retried');
        $deadLettered = $queueCounters->get('deadLettered');
        $queuePending = $this->queue->pending();
        $queueLeases = $this->queue->leaseCount();
        $queueProcessed = $this->queue->processed()->size();

        $report->section('Admission and requests');
        $report->table(['metric', 'value'], [
            ['requests', number_format($this->requests)],
            ['admitted', number_format($this->admitted)],
            ['denied by the rate limiter', number_format($this->denied)],
            ['admission rate', sprintf('%.1f%%', $admissionRate * 100)],
            ['catalog links', number_format($this->catalogSize)],
            ['created links', number_format($this->creates)],
            ['resolved links', number_format($this->resolves)],
        ]);
        $report->measure('requests', $this->requests);
        $report->measure('admitted', $this->admitted);
        $report->measure('denied', $this->denied);
        $report->measure('admission_rate', $admissionRate);
        $report->measure('catalog_links', $this->catalogSize);
        $report->measure('creates', $this->creates);
        $report->measure('resolves', $this->resolves);

        $report->section('Distributed cache');
        $report->table(['metric', 'value'], [
            ['reads', number_format($cacheReads)],
            ['primary + replica hits', number_format($cacheHits)],
            ['misses', number_format($cacheMisses)],
            ['overall hit ratio', sprintf('%.1f%%', $overallHitRatio * 100)],
            ['hit ratio before failure', sprintf('%.1f%%', $cacheBefore * 100)],
            ['hit ratio during failure', sprintf('%.1f%%', $cacheDuring * 100)],
            ['hit ratio after recovery', sprintf('%.1f%%', $cacheAfter * 100)],
            ['node failures / recoveries', sprintf('%d / %d', $this->cacheFailures, $this->cacheRecoveries)],
            ['entries lost with the node', number_format($this->cacheLostEntries)],
            ['replica hits', number_format($cacheReplicaHits)],
            ['read repairs', number_format($cacheRepairs)],
            ['repository fallbacks', number_format($this->repositoryFallbacks)],
        ]);
        $report->measure('cache_reads', $cacheReads);
        $report->measure('cache_hits', $cacheHits);
        $report->measure('cache_misses', $cacheMisses);
        $report->measure('cache_hit_ratio', $overallHitRatio);
        $report->measure('cache_hit_ratio_before', $cacheBefore);
        $report->measure('cache_hit_ratio_during', $cacheDuring);
        $report->measure('cache_hit_ratio_after', $cacheAfter);
        $report->measure('cache_node_failures', $this->cacheFailures);
        $report->measure('cache_node_recoveries', $this->cacheRecoveries);
        $report->measure('cache_lost_entries', $this->cacheLostEntries);
        $report->measure('cache_replica_hits', $cacheReplicaHits);
        $report->measure('cache_read_repairs', $cacheRepairs);
        $report->measure('cache_repository_fallbacks', $this->repositoryFallbacks);

        $report->section('Search');
        $report->table(['metric', 'value'], [
            ['documents indexed', number_format($searchDocuments)],
            ['dictionary terms (sum of shards)', number_format($searchDictionary)],
            ['postings', number_format($searchPostings)],
            ['queries', number_format($search['queries'])],
            ['non-empty responses', number_format($search['non_empty'])],
            ['postings scanned', number_format($search['postings'])],
            ['candidates scored', number_format($search['candidates'])],
            ['prefix terms returned', number_format($search['prefix_terms'])],
            ['prefix rebuilds, first / second query', sprintf('%d / %d', $search['rebuilds_first'], $search['rebuilds_second'])],
        ]);
        $report->measure('search_documents', $searchDocuments);
        $report->measure('search_dictionary_terms', $searchDictionary);
        $report->measure('search_postings', $searchPostings);
        $report->measure('search_queries', $search['queries']);
        $report->measure('search_non_empty', $search['non_empty']);
        $report->measure('search_postings_scanned', $search['postings']);
        $report->measure('search_candidates_scored', $search['candidates']);
        $report->measure('search_prefix_terms', $search['prefix_terms']);
        $report->measure('search_prefix_rebuilds_first', $search['rebuilds_first']);
        $report->measure('search_prefix_rebuilds_second', $search['rebuilds_second']);

        $report->section('Notifications');
        $report->table(['metric', 'value'], [
            ['notification events', number_format($notificationEvents)],
            ['channel intents', number_format($notificationIntents)],
            ['planned immediate deliveries', number_format($notificationPlanned)],
            ['duplicate channel deliveries suppressed', number_format($notificationSuppressed)],
            ['queued notification jobs', number_format($this->notificationJobs)],
            ['delivered', number_format($this->notificationDelivered)],
            ['dead-lettered', number_format($this->notificationDeadLettered)],
        ]);
        $report->measure('notification_events', $notificationEvents);
        $report->measure('notification_channel_intents', $notificationIntents);
        $report->measure('notification_planned', $notificationPlanned);
        $report->measure('notification_duplicates_suppressed', $notificationSuppressed);
        $report->measure('notification_jobs', $this->notificationJobs);
        $report->measure('notification_delivered', $this->notificationDelivered);
        $report->measure('notification_dead_lettered', $this->notificationDeadLettered);

        $report->section('Webhooks');
        $report->table(['metric', 'value'], [
            ['webhook events', number_format($webhookEvents)],
            ['deliveries created', number_format($webhookDeliveries)],
            ['HTTP attempts', number_format($webhookAttempts)],
            ['unique effects', number_format($webhookEffects)],
            ['duplicate effects suppressed', number_format($webhookSuppressed)],
            ['invalid signatures', number_format($webhookInvalid)],
            ['delivered', number_format($this->webhookDelivered)],
            ['dead-lettered', number_format($this->webhookDeadLettered)],
            ['down endpoint deliveries / dead letters', sprintf('%d / %d', $this->downDeliveries, $this->downDeadLettered)],
        ]);
        $report->measure('webhook_events', $webhookEvents);
        $report->measure('webhook_deliveries', $webhookDeliveries);
        $report->measure('webhook_attempts', $webhookAttempts);
        $report->measure('webhook_effects', $webhookEffects);
        $report->measure('webhook_duplicates_suppressed', $webhookSuppressed);
        $report->measure('webhook_invalid_signatures', $webhookInvalid);
        $report->measure('webhook_delivered', $this->webhookDelivered);
        $report->measure('webhook_dead_lettered', $this->webhookDeadLettered);
        $report->measure('webhook_down_deliveries', $this->downDeliveries);
        $report->measure('webhook_down_dead_lettered', $this->downDeadLettered);

        $report->section('Job queue');
        $report->table(['metric', 'value'], [
            ['enqueued', number_format($enqueued)],
            ['leases', number_format($leased)],
            ['completed', number_format($completed)],
            ['failed attempts', number_format($failed)],
            ['retries', number_format($retried)],
            ['dead-lettered', number_format($deadLettered)],
            ['peak depth', number_format($this->peakQueueDepth)],
            ['pending / leased after drain', sprintf('%d / %d', $queuePending, $queueLeases)],
            ['processed ids', number_format($queueProcessed)],
            ['simulated drain end', number_format($drainedAt) . ' ms'],
        ]);
        $report->measure('queue_enqueued', $enqueued);
        $report->measure('queue_leased', $leased);
        $report->measure('queue_completed', $completed);
        $report->measure('queue_failed', $failed);
        $report->measure('queue_retried', $retried);
        $report->measure('queue_dead_lettered', $deadLettered);
        $report->measure('queue_peak_depth', $this->peakQueueDepth);
        $report->measure('queue_pending', $queuePending);
        $report->measure('queue_leases', $queueLeases);
        $report->measure('queue_processed_ids', $queueProcessed);
        $report->measure('simulated_drain_end_ms', $drainedAt);

        /** @var list<array{name: string, passed: bool, detail: string}> $checks */
        $checks = [];
        $check = static function (string $name, bool $passed, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
        };

        $check(
            'requests conserved',
            $this->requests === $this->admitted + $this->denied,
            sprintf('%d = %d + %d', $this->requests, $this->admitted, $this->denied),
        );
        $check(
            'admitted split into creates and resolves',
            $this->admitted === $this->creates + $this->resolves,
            sprintf('%d = %d + %d', $this->admitted, $this->creates, $this->resolves),
        );
        $check(
            'both request kinds exercised',
            $this->creates > 0 && $this->resolves > 0,
            sprintf('%d creates, %d resolves', $this->creates, $this->resolves),
        );
        $check(
            'catalog populated',
            count($this->catalogCodes) === $this->catalogSize,
            sprintf('%d of %d codes', count($this->catalogCodes), $this->catalogSize),
        );
        $check(
            'cache reads match resolves',
            $cacheReads === $this->resolves,
            sprintf('%d reads / %d resolves', $cacheReads, $this->resolves),
        );
        $check(
            'cache outcomes cover reads',
            $cacheHits + $cacheMisses === $cacheReads,
            sprintf('%d hits + %d misses = %d', $cacheHits, $cacheMisses, $cacheReads),
        );
        $check(
            'cache misses fall back to the repository',
            $cacheMisses === $this->repositoryFallbacks,
            sprintf('%d misses / %d fallbacks', $cacheMisses, $this->repositoryFallbacks),
        );
        $check(
            'cache node failed once',
            $this->cacheFailures === 1,
            sprintf('%d failure(s)', $this->cacheFailures),
        );
        $check(
            'cache node recovered once',
            $this->cacheRecoveries === 1,
            sprintf('%d recovery(s)', $this->cacheRecoveries),
        );
        $check(
            'cache failure lost entries',
            $this->cacheLostEntries > 0,
            sprintf('%d entries lost', $this->cacheLostEntries),
        );
        $check(
            'hit ratio did not collapse after recovery',
            $cacheAfter >= $cacheDuring - 1e-9,
            sprintf('during %.1f%%, after %.1f%%', $cacheDuring * 100, $cacheAfter * 100),
        );
        $check(
            'search indexed every link',
            $searchDocuments === $this->catalogSize + $this->creates,
            sprintf('%d indexed / %d expected', $searchDocuments, $this->catalogSize + $this->creates),
        );
        $check(
            'every search query returned a hit',
            $search['non_empty'] === $search['queries'],
            sprintf('%d / %d queries', $search['non_empty'], $search['queries']),
        );
        $check(
            'prefix index rebuilt lazily',
            $search['prefix_terms'] > 0 && $search['rebuilds_second'] === $search['rebuilds_first'],
            sprintf(
                '%d terms, %d then %d rebuilds',
                $search['prefix_terms'],
                $search['rebuilds_first'],
                $search['rebuilds_second'],
            ),
        );
        $check(
            'notification replay was deduplicated',
            $notificationEvents === $this->creates * 2 && $notificationSuppressed === $this->creates * 3,
            sprintf('%d events, %d duplicates suppressed', $notificationEvents, $notificationSuppressed),
        );
        $check(
            'one notification job per channel',
            $this->notificationJobs === $this->creates * 3,
            sprintf('%d jobs / %d channels', $this->notificationJobs, $this->creates * 3),
        );
        $check(
            'notification jobs all finished',
            $this->notificationDelivered + $this->notificationDeadLettered === $this->notificationJobs,
            sprintf(
                '%d delivered + %d dead = %d',
                $this->notificationDelivered,
                $this->notificationDeadLettered,
                $this->notificationJobs,
            ),
        );
        $check(
            'one webhook event per create',
            $webhookEvents === $this->creates,
            sprintf('%d events / %d creates', $webhookEvents, $this->creates),
        );
        $check(
            'webhook fan-out is deterministic',
            $webhookDeliveries === $this->creates + $this->tenant0Creates,
            sprintf(
                '%d deliveries / %d creates + %d tenant-0',
                $webhookDeliveries,
                $this->creates,
                $this->tenant0Creates,
            ),
        );
        $check(
            'each webhook job made one attempt',
            $webhookAttempts === $this->webhookLeases,
            sprintf('%d attempts / %d leases', $webhookAttempts, $this->webhookLeases),
        );
        $check(
            'webhook jobs all finished',
            $this->webhookDelivered + $this->webhookDeadLettered === $this->webhookJobs,
            sprintf(
                '%d delivered + %d dead = %d',
                $this->webhookDelivered,
                $this->webhookDeadLettered,
                $this->webhookJobs,
            ),
        );
        $check(
            'down endpoint exhausted its attempts',
            $this->downDeadLettered === $this->downDeliveries && $this->downDeliveries > 0,
            sprintf('%d / %d down deliveries', $this->downDeadLettered, $this->downDeliveries),
        );
        $check(
            'signatures verified',
            $webhookInvalid === 0,
            sprintf('%d invalid', $webhookInvalid),
        );
        $check(
            'effects bounded by deliveries',
            $webhookEffects >= $this->webhookDelivered && $webhookEffects <= $webhookDeliveries,
            sprintf('%d effects, %d delivered, %d deliveries', $webhookEffects, $this->webhookDelivered, $webhookDeliveries),
        );
        $check(
            'queue enqueued every job',
            $enqueued === $this->notificationJobs + $this->webhookJobs,
            sprintf('%d enqueued / %d notification + %d webhook', $enqueued, $this->notificationJobs, $this->webhookJobs),
        );
        $check(
            'queue conserved jobs',
            $completed + $deadLettered === $enqueued,
            sprintf('%d completed + %d dead = %d enqueued', $completed, $deadLettered, $enqueued),
        );
        $check(
            'queue failures split into retries and dead letters',
            $failed === $retried + $deadLettered,
            sprintf('%d failed = %d retried + %d dead', $failed, $retried, $deadLettered),
        );
        $check(
            'queue completions match delivered jobs',
            $completed === $this->notificationDelivered + $this->webhookDelivered,
            sprintf('%d completed / %d delivered', $completed, $this->notificationDelivered + $this->webhookDelivered),
        );
        $check(
            'queue dead letters match consumers',
            $deadLettered === $this->notificationDeadLettered + $this->webhookDeadLettered,
            sprintf('%d dead / %d consumer dead letters', $deadLettered, $this->notificationDeadLettered + $this->webhookDeadLettered),
        );
        $check(
            'queue fault counts match consumers',
            $failed === $this->notificationFailures + $this->webhookFailures,
            sprintf('%d failed / %d consumer failures', $failed, $this->notificationFailures + $this->webhookFailures),
        );
        $check(
            'queue leases match consumer attempts',
            $leased === $this->notificationLeases + $this->webhookLeases,
            sprintf('%d leases / %d consumer attempts', $leased, $this->notificationLeases + $this->webhookLeases),
        );
        $check(
            'queue processed ids match completions',
            $queueProcessed === $completed,
            sprintf('%d processed / %d completed', $queueProcessed, $completed),
        );
        $check(
            'queue drained',
            $queuePending === 0 && $queueLeases === 0,
            sprintf('%d pending, %d leased', $queuePending, $queueLeases),
        );
        $check(
            'peak depth is the whole stream',
            $this->peakQueueDepth === $enqueued,
            sprintf('%d peak / %d enqueued', $this->peakQueueDepth, $enqueued),
        );
        $check(
            'admission happened',
            $this->admitted > 0,
            sprintf('%d admitted', $this->admitted),
        );

        $passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
        $invariantsOk = $passed === count($checks);
        $report->section('Invariants');
        $report->table(
            ['invariant', 'result', 'detail'],
            array_map(
                static fn (array $check): array => [
                    $check['name'],
                    $check['passed'] ? 'yes' : 'no',
                    $check['detail'],
                ],
                $checks,
            ),
        );
        $report->measure('invariants_ok', $invariantsOk);
        $report->measure('invariants_passed', $passed);
        $report->measure('invariants_total', count($checks));

        $report->line();
        $report->line(sprintf(
            'The token bucket admitted %s of %s requests; the rest were rejected before any work.',
            number_format($this->admitted),
            number_format($this->requests),
        ));
        $report->line(sprintf(
            'Replication kept the cache readable through one node failure: %s replica hits and %s read repairs.',
            number_format($cacheReplicaHits),
            number_format($cacheRepairs),
        ));
        $report->line(sprintf(
            'Notifications were planned once per channel and a replay was suppressed %s times.',
            number_format($notificationSuppressed),
        ));
        $report->line(sprintf(
            'Webhooks used stable idempotency keys: %s effects for %s delivered jobs, with %s duplicate effects suppressed.',
            number_format($webhookEffects),
            number_format($this->webhookDelivered),
            number_format($webhookSuppressed),
        ));
        $report->line(sprintf(
            'The permanently down endpoint dead-lettered %s deliveries; the queue finished with %d pending jobs.',
            number_format($this->downDeadLettered),
            $queuePending,
        ));
    }

    /**
     * @param array<string, int> $from
     * @param array<string, int> $to
     */
    private static function hitRatio(array $from, array $to): float
    {
        $reads = ($to['reads'] ?? 0) - ($from['reads'] ?? 0);

        if ($reads <= 0) {
            return 0.0;
        }

        $hits = (($to['primaryHits'] ?? 0) + ($to['replicaHits'] ?? 0))
            - (($from['primaryHits'] ?? 0) + ($from['replicaHits'] ?? 0));

        return $hits / $reads;
    }

    private function tenantId(int $index): string
    {
        return 'tenant-' . $index;
    }
}
