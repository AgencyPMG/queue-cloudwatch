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
use Aws\Exception\AwsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PMG\Queue\Driver;
use PMG\Queue\Envelope;
use PMG\Queue\Message;
use PMG\Queue\Exception\DriverError;

final class MetricsDriver implements Driver
{
    const DEFAULT_NAMESPACE = 'PMG/Queue';
    const NO_MSG_NAME = '__none__';

    /**
     * @var Driver
     */
    private $wrapped;

    /**
     * @var CloudWatchClient
     */
    private $cloudwatch;

    /**
     * @var string
     */
    private $metricsNamespace;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * A map of message envelopes to their start times. Used to track timing
     * on the message.
     *
     * @var SplObjectStorage
     */
    private $startTimes;

    public function __construct(
        Driver $wrapped,
        CloudWatchClient $cloudwatch,
        $metricsNamespace=null,
        LoggerInterface $logger=null
    ) {
        $this->wrapped = $wrapped;
        $this->cloudwatch = $cloudwatch;
        $this->metricsNamespace = $metricsNamespace ?: self::DEFAULT_NAMESPACE;
        $this->logger = $logger ?: new NullLogger();
        $this->startTimes = new \SplObjectStorage();
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue($queueName, Message $message)
    {
        try {
            $out = $this->wrapped->enqueue($queueName, $message);
        } catch (DriverError $e) {
            $this->trackDriverError($queueName, $e, $message);
            throw $e;
        }

        $this->trackMetrics($queueName, [
            Metric::count('MessageEnqueue', 1),
        ], $message);

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public function dequeue($queueName)
    {
        try {
            $env = $this->wrapped->dequeue($queueName);
        } catch (DriverError $e) {
            $this->trackDriverError($queueName, $e);
            throw $e;
        }

        // got a `null` back from the wrapped driver so just pass it on
        // no need to to track metrics for non-dequeues
        if (!$env) {
            return $env;
        }

        $this->trackMetrics($queueName, [
            Metric::count('MessageDequeue', 1),
        ], $env->unwrap());

        $this->startTimes[$env] = self::now();

        return $env;
    }

    /**
     * {@inheritdoc}
     */
    public function ack($queueName, Envelope $envelope)
    {
        try {
            $out = $this->wrapped->ack($queueName, $envelope);
        } catch (DriverError $e) {
            $this->trackDriverError($queueName, $e, $envelope->unwrap());
            throw $e;
        }

        $this->trackMessageFinished('Success', $queueName, $envelope);

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public function retry($queueName, Envelope $envelope)
    {
        try {
            $out = $this->wrapped->retry($queueName, $envelope);
        } catch (DriverError $e) {
            $this->trackDriverError($queueName, $e, $envelope->unwrap());
            throw $e;
        }

        $this->trackMessageFinished('Retry', $queueName, $envelope);

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public function fail($queueName, Envelope $envelope)
    {
        try {
            $out = $this->wrapped->fail($queueName, $envelope);
        } catch (DriverError $e) {
            $this->trackDriverError($queueName, $e, $envelope->unwrap());
            throw $e;
        }

        $this->trackMessageFinished('Failure', $queueName, $envelope);

        return $out;
    }

    private function trackDriverError($queueName, DriverError $e, Message $msg=null)
    {
        $this->trackMetrics($queueName, [
            Metric::count('DriverError', 1, ['ErrorClass' => get_class($e)]),
        ], $msg);
    }

    private function trackMessageFinished($type, $queueName, Envelope $envelope)
    {
        $metrics = [
            Metric::count("Message{$type}", 1),
        ];
        if (isset($this->startTimes[$envelope])) {
            $time = round(self::now() - $this->startTimes[$envelope], 1);
            $metrics[] = Metric::millis('MessageTime', $time, [
                'MessageStatus' => $type,
            ]);
            unset($this->startTimes[$envelope]);
        }

        $this->trackMetrics($queueName, $metrics, $envelope->unwrap());
    }

    /**
     * Sends all the provided `Metrics` to cloudwatch.
     *
     * The only thing interesting in here is that is sends the metrics both with
     * and without dimensions. CloudWatch treats each unique combination of
     * dimensions as a unique metric. Which is pretty useless for getting an
     * aggregate view. To help with that, we send each metric twice. Once with
     * dimensions and once without.
     *
     * @see http://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/cloudwatch_concepts.html#dimension-combinations
     *
     * @param string $queueName The queue to which the $msg belongs
     * @param Metric[] $metrics The set of metrics to track
     * @param Message|null $msg The message that's being tracked. May be `null`
     *        on driver errors.
     * @return void
     */
    private function trackMetrics($queueName, array $metrics, Message $msg=null)
    {
        $dimensions = $this->dimensionsFor($queueName, $msg);

        try {
            $this->cloudwatch->putMetricData([
                'Namespace' => $this->metricsNamespace,
                'MetricData' => array_merge(...array_map(function (Metric $m) use ($dimensions) {
                    $noDim = $m->toClientArray();
                    unset($noDim['Dimensions']);

                    return [$noDim, $m->toClientArray($dimensions)];
                }, $metrics)),
            ]);
        } catch (AwsException $e) {
            $this->logger->error('Caught {cls} putting metric data: {msg}', [
                'cls' => get_class($e),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function dimensionsFor($queueName, Message $msg=null)
    {
        return [
            'QueueName' => $queueName,
            'MessageName' => $msg ? $msg->getName() : self::NO_MSG_NAME,
        ];
    }

    private static function now()
    {
        return microtime(true) * 1000;
    }
}
