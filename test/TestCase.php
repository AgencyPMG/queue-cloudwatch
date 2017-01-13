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

use PMG\Queue\Driver;
use PMG\Queue\DefaultEnvelope;
use PMG\Queue\SimpleMessage;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    const NS = 'PMG/Queue/MetricsTest';
    const Q = 'queueName';

    protected $logger, $message, $wrapped, $envelope;

    protected function setUp()
    {
        $this->wrapped = $this->createMock(Driver::class);
        $this->logger = new Test\CollectingLogger();
        $this->message = new SimpleMessage('TestMessage');
        $this->envelope = new DefaultEnvelope($this->message);
    }
}
