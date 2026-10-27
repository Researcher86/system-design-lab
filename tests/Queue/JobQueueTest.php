<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Queue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Queue\ExponentialBackoff;
use SystemDesignLab\Queue\FailureStatus;
use SystemDesignLab\Queue\Job;
use SystemDesignLab\Queue\JobQueue;
use SystemDesignLab\Queue\ProcessedJobStore;

final class JobQueueTest extends TestCase
{
    public function testHigherPriorityLeasesFirst(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('low', priority: 1), 0);
        $queue->enqueue($this->job('high', priority: 10), 0);

        $lease = $queue->lease('worker-a', 0);

        self::assertNotNull($lease);
        self::assertSame('high', $lease->jobId());
    }

    public function testEqualPriorityIsFifo(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('first'), 0);
        $queue->enqueue($this->job('second'), 0);
        $queue->enqueue($this->job('third'), 0);

        foreach (['first', 'second', 'third'] as $expected) {
            $lease = $queue->lease('worker-a', 0);
            self::assertNotNull($lease);
            self::assertSame($expected, $lease->jobId());
            self::assertTrue($queue->complete($lease, 0));
        }

        self::assertNull($queue->lease('worker-a', 0));
    }

    public function testDelayedJobIsNotLeasedEarly(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('delayed', availableAtMs: 100), 0);

        self::assertNull($queue->lease('worker-a', 99));
        self::assertSame(1, $queue->delayedDepth());

        $lease = $queue->lease('worker-a', 100);

        self::assertNotNull($lease);
        self::assertSame('delayed', $lease->jobId());
        self::assertSame(0, $queue->delayedDepth());
    }

    public function testFutureHighPriorityDoesNotBlockADueLowPriorityJob(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('future-high', priority: 10, availableAtMs: 100), 0);
        $queue->enqueue($this->job('due-low', priority: 1), 0);

        $lease = $queue->lease('worker-a', 0);

        self::assertNotNull($lease);
        self::assertSame('due-low', $lease->jobId());
    }

    public function testExpiredLeaseRedeliversImmediately(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1', maxAttempts: 3), 0);
        $first = $queue->lease('worker-a', 0, visibilityTimeoutMs: 100);
        self::assertNotNull($first);

        self::assertNull($queue->lease('worker-b', 99));

        $second = $queue->lease('worker-b', 100, visibilityTimeoutMs: 100);

        self::assertNotNull($second);
        self::assertSame('job-1', $second->jobId());
        self::assertSame(2, $second->job->attempts());
        self::assertSame(1, $queue->counters()->get('expiredLeases'));
        self::assertSame(1, $queue->counters()->get('retried'));
    }

    public function testStaleCompletionAfterAnExpiredLeaseIsRejected(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1'), 0);
        $first = $queue->lease('worker-a', 0, visibilityTimeoutMs: 100);
        self::assertNotNull($first);
        $second = $queue->lease('worker-b', 100, visibilityTimeoutMs: 100);
        self::assertNotNull($second);

        self::assertFalse($queue->complete($first, 101));
        self::assertSame(1, $queue->counters()->get('staleCompletions'));
        self::assertTrue($queue->complete($second, 101));
        self::assertSame(1, $queue->counters()->get('completed'));
    }

    public function testStaleFailureAfterAnExpiredLeaseIsRejected(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1'), 0);
        $first = $queue->lease('worker-a', 0, visibilityTimeoutMs: 100);
        self::assertNotNull($first);
        $second = $queue->lease('worker-b', 100, visibilityTimeoutMs: 100);
        self::assertNotNull($second);

        self::assertSame(FailureStatus::Stale, $queue->fail($first, 101)->status);
        self::assertSame(1, $queue->counters()->get('staleFailures'));
        self::assertSame(0, $queue->counters()->get('failed'));

        self::assertSame(FailureStatus::Retried, $queue->fail($second, 101)->status);
    }

    public function testCompleteMarksTheJobProcessedAndRejectsAReplay(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1'), 0);
        $lease = $queue->lease('worker-a', 0);
        self::assertNotNull($lease);

        self::assertTrue($queue->complete($lease, 0));
        self::assertFalse($queue->enqueue($this->job('job-1'), 0));
        self::assertSame(1, $queue->counters()->get('duplicateEnqueues'));
        self::assertSame(0, $queue->activeCount());
        self::assertSame(1, $queue->processed()->size());
    }

    public function testFailureRetriesWithBackoffThenDeadLetters(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1', maxAttempts: 3), 0);

        $first = $queue->lease('worker-a', 0);
        self::assertNotNull($first);
        $firstFailure = $queue->fail($first, 0, 'temporary');
        self::assertSame(FailureStatus::Retried, $firstFailure->status);
        self::assertSame(100, $firstFailure->retryDelayMs);

        self::assertNull($queue->lease('worker-a', 99));

        $second = $queue->lease('worker-a', 100);
        self::assertNotNull($second);
        $secondFailure = $queue->fail($second, 100, 'temporary');
        self::assertSame(FailureStatus::Retried, $secondFailure->status);
        self::assertSame(200, $secondFailure->retryDelayMs);

        self::assertNull($queue->lease('worker-a', 299));

        $third = $queue->lease('worker-a', 300);
        self::assertNotNull($third);
        $thirdFailure = $queue->fail($third, 300, 'permanent');

        self::assertSame(FailureStatus::DeadLettered, $thirdFailure->status);
        self::assertSame(3, $third->job->attempts());
        self::assertCount(1, $queue->deadLetters());
        self::assertSame('permanent', $queue->deadLetters()[0]->lastError());
        self::assertSame(3, $queue->counters()->get('failed'));
        self::assertSame(2, $queue->counters()->get('retried'));
        self::assertSame(1, $queue->counters()->get('deadLettered'));
        self::assertSame(0, $queue->activeCount());
    }

    public function testLeaseExpiryOnTheLastAttemptDeadLetters(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1', maxAttempts: 1), 0);
        $lease = $queue->lease('worker-a', 0, visibilityTimeoutMs: 100);
        self::assertNotNull($lease);

        $queue->tick(100);

        self::assertCount(1, $queue->deadLetters());
        self::assertSame(1, $queue->counters()->get('expiredLeases'));
        self::assertSame(1, $queue->counters()->get('deadLettered'));
        self::assertSame(0, $queue->pending());
        self::assertSame(0, $queue->leaseCount());
    }

    public function testDuplicateActiveEnqueueIsRejected(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1'), 0);

        self::assertFalse($queue->enqueue($this->job('job-1'), 0));
        self::assertSame(1, $queue->counters()->get('duplicateEnqueues'));
        self::assertSame(1, $queue->pending());
    }

    public function testQueueDepthSeparatesReadyDelayedAndLeasedWork(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('ready'), 0);
        $queue->enqueue($this->job('delayed', availableAtMs: 100), 0);
        $queue->lease('worker-a', 0);

        self::assertSame(0, $queue->readyDepth());
        self::assertSame(1, $queue->delayedDepth());
        self::assertSame(1, $queue->leaseCount());
        self::assertSame(1, $queue->pending());
        self::assertSame(2, $queue->activeCount());
    }

    public function testOldestPendingTracksTheBacklogAge(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('first', createdAtMs: 10), 10);
        $queue->enqueue($this->job('second', createdAtMs: 20), 20);

        self::assertSame(10, $queue->oldestPendingAtMs());

        $first = $queue->lease('worker-a', 20);
        self::assertNotNull($first);
        self::assertSame('first', $first->jobId());
        self::assertTrue($queue->complete($first, 20));
        self::assertSame(20, $queue->oldestPendingAtMs());

        $second = $queue->lease('worker-a', 20);
        self::assertNotNull($second);
        self::assertTrue($queue->complete($second, 20));
        self::assertNull($queue->oldestPendingAtMs());
    }

    public function testHappyPathCounters(): void
    {
        $queue = $this->queue();
        $queue->enqueue($this->job('job-1'), 0);
        $lease = $queue->lease('worker-a', 0);
        self::assertNotNull($lease);
        $queue->complete($lease, 0);

        self::assertSame(1, $queue->counters()->get('enqueued'));
        self::assertSame(1, $queue->counters()->get('leased'));
        self::assertSame(1, $queue->counters()->get('completed'));
    }

    public function testLeaseRejectsAnEmptyWorker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue()->lease('', 0);
    }

    public function testLeaseRejectsAZeroVisibilityTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue()->lease('worker-a', 0, 0);
    }

    public function testAllJobsCompleteUnderMixedPrioritiesAndDelays(): void
    {
        $queue = $this->queue();

        for ($i = 0; $i < 100; ++$i) {
            $queue->enqueue(
                $this->job(
                    'job-' . $i,
                    priority: $i % 5,
                    availableAtMs: ($i % 10) * 10,
                ),
                0,
            );
        }

        for ($now = 0; $now <= 200; $now += 10) {
            while (($lease = $queue->lease('worker-a', $now)) !== null) {
                self::assertTrue($queue->complete($lease, $now));
            }
        }

        self::assertSame(100, $queue->counters()->get('completed'));
        self::assertSame(0, $queue->counters()->get('deadLettered'));
        self::assertSame(0, $queue->pending());
        self::assertSame(0, $queue->activeCount());
    }

    private function queue(): JobQueue
    {
        return new JobQueue(
            new ExponentialBackoff(baseMs: 100, multiplier: 2.0, maxMs: 1_000, jitterMs: 0),
            new ProcessedJobStore(ttlMs: 86_400_000, cleanupInterval: 0),
        );
    }

    private function job(
        string $id,
        int $priority = 0,
        int $createdAtMs = 0,
        int $maxAttempts = 3,
        ?int $availableAtMs = null,
    ): Job {
        return new Job(
            id: $id,
            type: 'test',
            priority: $priority,
            createdAtMs: $createdAtMs,
            maxAttempts: $maxAttempts,
            availableAtMs: $availableAtMs,
        );
    }
}
