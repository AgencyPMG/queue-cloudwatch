<?php
/**
 * This file is part of pmg/queue-cloudwatch
 *
 * Copyright (c) PMG <https://www.pmg.com>
 *
 * For full copyright information see the LICENSE file distributed
 * with this source code.
 *
 * @license     http://opensource.org/licenses/Apache-2.0 Apache-2.0
 */

namespace PMG\Queue\CloudWatch;

use Aws\CloudWatch\CloudWatchClient;

/**
 * @group slow
 * @group integration
 */
class MetricsDriverIntegrationTest extends TestCase
{
    private CloudWatchClient $cloudwatch;
    private MetricsDriver $driver;

    public function testDriverErrorsAreTrackedWithMetrics() : void
    {
        $this->driverThrowsFrom('enqueue');

        try {
            $this->driver->enqueue(self::Q, $this->message);
        } finally {
            $this->assertNoErrors();
        }
    }

    public function testEnqueuePutsMessageEnqueuedDataAndReturnsEnvelope() : void
    {
        $this->wrapped->expects($this->once())
            ->method('enqueue')
            ->with(self::Q, $this->message)
            ->willReturn($this->envelope);

        $e = $this->driver->enqueue(self::Q, $this->message);

        $this->assertSame($this->envelope, $e);
        $this->assertNoErrors();
    }

    /**
     * @return interable<string[]>
     */
    public static function finishers() : iterable
    {
        return [
            ['ack'],
            ['fail'],
            ['release'],
        ];
    }

    /**
     * @dataProvider finishers
     */
    public function testDequeuingAMessageAndFinishingItInSomeWayTracksMetrics(string $finish) : void
    {
        $this->wrapped->expects($this->once())
            ->method('dequeue')
            ->with(self::Q)
            ->willReturn($this->envelope);
        $this->wrapped->expects($this->once())
            ->method($finish)
            ->with(self::Q, $this->envelope);

        $e = $this->driver->dequeue(self::Q);
        sleep(1); // give a bit of time to track for MessageTime
        call_user_func([$this->driver, $finish], self::Q, $e);

        $this->assertNoErrors();
    }

    public function testRetryReturnsTheEnvelopeFromTheTheWrappedDriver() : void
    {
        $this->wrapped->expects($this->once())
            ->method('dequeue')
            ->with(self::Q)
            ->willReturn($this->envelope);
        $this->wrapped->expects($this->once())
            ->method('retry')
            ->with(self::Q, $this->envelope)
            ->willReturn($this->envelope);


        $e = $this->driver->dequeue(self::Q);
        sleep(1); // give a bit of time to track for MessageTime
        $e2 = call_user_func([$this->driver, 'retry'], self::Q, $e);

        $this->assertSame($this->envelope, $e2);
        $this->assertNoErrors();
    }

    protected function setUp() : void
    {
        parent::setUp();
        $this->cloudwatch = new CloudWatchClient([
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'version' => 'latest',
            'endpoint' => getenv('LOCALSTACK_ENDPOINT') ?: 'http://localhost:4566',
            'credentials' => [
                'key' => 'ignoredbylocalstack',
                'secret' => 'ignoredbylocalstack',
            ],
        ]);
        $this->driver = new MetricsDriver(
            $this->wrapped,
            $this->cloudwatch,
            self::NS,
            $this->logger
        );
    }

    private function driverThrowsFrom(string $method) : void
    {
        $this->expectException(Test\TestDriverError::class);
        $this->wrapped->expects($this->once())
            ->method($method)
            ->willThrowException(new Test\TestDriverError('oops'));
    }

    private function assertNoErrors() : void
    {
        $this->assertCount(
            0,
            $this->logger,
            "Unexpected Errors:\n".implode("\n", iterator_to_array($this->logger))
        );
    }
}
