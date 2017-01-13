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
use Aws\CommandInterface;
use Aws\Exception\AwsException;

class MetricsDriverTest extends TestCase
{
    private $cloudwatch, $driver;

    public static function finishers()
    {
        return [
            ['ack', 'Success'],
            ['fail', 'Failure'],
            ['retry', 'Retry'],
        ];
    }

    public function testEnqueueWithDriverErrorTracksDriverErrorMetrics()
    {
        $this->driverThrowsFrom('enqueue');
        $this->willTrackDriverError();

        $this->driver->enqueue(self::Q, $this->message);
    }

    public function testDequeueWithDriverErrorTracksDriverErrorMetrics()
    {
        $this->driverThrowsFrom('dequeue');
        $this->willTrackDriverError();

        $this->driver->dequeue(self::Q);
    }

    /**
     * @dataProvider finishers
     */
    public function testFinisherWithDriverErrorTrackerDriverErrorMetrics($method)
    {
        $this->driverThrowsFrom($method);
        $this->willTrackDriverError();

        call_user_func([$this->driver, $method], self::Q, $this->envelope);
    }

    public function testEnqueuePutsMessageEnqueuedDataAndReturnsEnvelope()
    {
        $this->willTrackMessageMetric('Enqueue');
        $this->wrapped->expects($this->once())
            ->method('enqueue')
            ->with(self::Q, $this->message)
            ->willReturn($this->envelope);

        $e = $this->driver->enqueue(self::Q, $this->message);

        $this->assertSame($this->envelope, $e);
    }

    public function testDequeueDoesNothingWhenNoMessageIsReturnedFromWrappedDriver()
    {
        $this->cloudwatch->expects($this->never())
            ->method('putMetricData');
        $this->wrapped->expects($this->once())
            ->method('dequeue')
            ->with(self::Q)
            ->willReturn(null);

        $env = $this->driver->dequeue(self::Q);

        $this->assertNull($env);
    }

    /**
     * @dataProvider finishers
     * @group slow
     */
    public function testDequeuingFinishFlowTracksExpectedMetrics($finish, $type)
    {
        $this->willTrackMessageMetric('Dequeue', $this->at(0));
        $this->cloudwatch->expects($this->at(1))
            ->method('putMetricData')
            ->with($this->callback(function (array $req) use ($type) {
                $mn = $this->metricNamesIn($req);

                $this->assertCount(4, $mn, "Should have four message metrics: 2 Message{$type}, 2 MessageTime. Got: ".var_export($mn, true));
                $this->assertContains("Message{$type}", $mn);
                $this->assertContains('MessageTime', $mn);

                return true;
            }));
        $this->wrapped->expects($this->once())
            ->method('dequeue')
            ->with(self::Q)
            ->willReturn($this->envelope);
        $this->wrapped->expects($this->once())
            ->method($finish)
            ->with(self::Q, $this->envelope)
            ->willReturn($this->envelope);

        $e = $this->driver->dequeue(self::Q);
        sleep(1); // give a bit of time to track for MessageTime
        $e2 = call_user_func([$this->driver, $finish], self::Q, $e);

        $this->assertSame($this->envelope, $e2);
    }

    public function testCloudWatchErrorsAreLoggedAndSwallowed()
    {
        $this->cloudwatch->expects($this->once())
            ->method('putMetricData')
            ->willThrowException(new AwsException('oops', $this->createMock(CommandInterface::class)));
        $this->wrapped->expects($this->once())
            ->method('enqueue')
            ->willReturn($this->envelope);

        $this->driver->enqueue(self::Q, $this->message);

        $this->assertCount(1, $this->logger);
    }

    protected function setUp()
    {
        parent::setUp();
        $this->cloudwatch = $this->getMockBuilder(CloudWatchClient::class)
            ->disableOriginalConstructor()
            ->setMethods(['putMetricData'])
            ->getMock();
        $this->driver = new MetricsDriver(
            $this->wrapped,
            $this->cloudwatch,
            self::NS,
            $this->logger
        );
    }

    private function driverThrowsFrom($method)
    {
        $this->expectException(Test\TestDriverError::class);
        $this->wrapped->expects($this->once())
            ->method($method)
            ->willThrowException(new Test\TestDriverError('oops'));
    }

    private function willTrackDriverError()
    {
        $this->cloudwatch->expects($this->once())
            ->method('putMetricData')
            ->with($this->callback(function (array $req) {
                $mn = $this->metricNamesIn($req);

                $this->assertCount(2, $mn, 'should have two driver errors');
                $this->assertContains('DriverError', $mn);

                return true;
            }));
    }

    private function willTrackMessageMetric($type, $when=null)
    {
        $this->cloudwatch->expects($when ?: $this->once())
            ->method('putMetricData')
            ->with($this->callback(function (array $req) use ($type) {
                $mn = $this->metricNamesIn($req);

                $this->assertContains("Message{$type}", $mn);

                return true;
            }));
        
    }

    private function metricNamesIn(array $request)
    {
        return array_map(function (array $m) {
            return $m['MetricName'];
        }, $request['MetricData']);
    }
}
