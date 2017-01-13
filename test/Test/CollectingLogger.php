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

namespace PMG\Queue\CloudWatch\Test;

use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger implements \Countable, \IteratorAggregate
{
    private $messages = [];

    public function getIterator()
    {
        return new \ArrayIterator($this->messages);
    }

    public function getMessages()
    {
        return $this->messages;
    }

    public function count()
    {
        return count($this->messages);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context=[])
    {
        $replace = [];
        foreach ($context as $k => $v) {
            $replace['{'.$k.'}'] = $v;
        }

        $this->messages[] = sprintf('[%s] %s', $level, strtr($message, $replace));
    }
}
