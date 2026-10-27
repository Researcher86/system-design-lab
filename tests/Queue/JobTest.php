<?php

declare(strict_types=1);

namespace SystemDesignLab\Tests\Queue;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SystemDesignLab\Queue\Job;

final class JobTest extends TestCase
{
    public function testAvailabilityDefaultsToCreationTime(): void
    {
        $job = $this->job('job-1', createdAtMs: 10);

        self::assertSame(10, $job->availableAtMs());
        self::assertSame(0, $job->attempts());
        self::assertTrue($job->isPending());
        self::assertFalse($job->isLeased());
        self::assertNull($job->completedAtMs());
        self::assertNull($job->deadLetteredAtMs());
    }

    public function testLeaseIncrementsAttemptsAndSetsExpiry(): void
    {
        $job = $this->job('job-1');

        $job->lease('worker-a', 20, 100);

        self::assertSame(1, $job->attempts());
        self::assertSame('worker-a', $job->leaseOwner());
        self::assertSame(120, $job->leaseExpiresAtMs());
        self::assertTrue($job->isLeased());
        self::assertFalse($job->leaseExpiredAt(119));
        self::assertTrue($job->leaseExpiredAt(120));
    }

    public function testJobCannotBeLeasedTwice(): void
    {
        $job = $this->job('job-1');
        $job->lease('worker-a', 0, 100);

        $this->expectException(LogicException::class);
        $job->lease('worker-b', 0, 100);
    }

    public function testJobCannotBeLeasedBeforeItIsAvailable(): void
    {
        $job = $this->job('job-1', availableAtMs: 100);

        $this->expectException(LogicException::class);
        $job->lease('worker-a', 0, 100);
    }

    public function testCompleteClearsTheLeaseAndMarksTheJobFinished(): void
    {
        $job = $this->job('job-1');
        $job->lease('worker-a', 0, 100);

        $job->complete(20);

        self::assertFalse($job->isPending());
        self::assertFalse($job->isLeased());
        self::assertSame(20, $job->completedAtMs());
        self::assertSame(1, $job->attempts());
    }

    public function testRetryMovesAvailabilityAndClearsTheLease(): void
    {
        $job = $this->job('job-1', maxAttempts: 3);
        $job->lease('worker-a', 10, 100);

        $job->scheduleRetry(10, 50, 'temporary failure');

        self::assertSame(60, $job->availableAtMs());
        self::assertFalse($job->isLeased());
        self::assertSame(1, $job->attempts());
        self::assertSame('temporary failure', $job->lastError());
        self::assertTrue($job->isPending());
    }

    public function testRetryOnTheLastAttemptIsRejected(): void
    {
        $job = $this->job('job-1', maxAttempts: 1);
        $job->lease('worker-a', 0, 100);

        $this->expectException(LogicException::class);
        $job->scheduleRetry(0, 10, 'last attempt');
    }

    public function testDeadLetterMarksTheJobFinished(): void
    {
        $job = $this->job('job-1');
        $job->lease('worker-a', 0, 100);

        $job->deadLetter(30, 'permanent failure');

        self::assertFalse($job->isPending());
        self::assertFalse($job->isLeased());
        self::assertSame(30, $job->deadLetteredAtMs());
        self::assertSame('permanent failure', $job->lastError());
    }

    /**
     * @return iterable<string, array{string, string, int, int, int, int}>
     */
    public static function invalidJobs(): iterable
    {
        yield 'empty id' => ['', 'type', 0, 0, 1, 0];
        yield 'empty type' => ['job-1', '', 0, 0, 1, 0];
        yield 'negative creation' => ['job-1', 'type', 0, -1, 1, -1];
        yield 'zero attempts' => ['job-1', 'type', 0, 0, 0, 0];
        yield 'available before creation' => ['job-1', 'type', 0, 10, 1, 9];
    }

    #[DataProvider('invalidJobs')]
    public function testInvalidJobsAreRejected(
        string $id,
        string $type,
        int $priority,
        int $createdAtMs,
        int $maxAttempts,
        int $availableAtMs,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new Job($id, $type, $priority, $createdAtMs, $maxAttempts, $availableAtMs);
    }

    public function testLeaseRejectsAnEmptyWorker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->job('job-1')->lease('', 0, 100);
    }

    public function testLeaseRejectsAZeroVisibilityTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->job('job-1')->lease('worker-a', 0, 0);
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
