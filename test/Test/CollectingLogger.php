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

use ArrayIterator;
use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger implements \Countable, \IteratorAggregate
{
    /**
     * @var string[]
     */
    private array $messages = [];

    /**
     * @return string[]
     */
    public function getIterator() : ArrayIterator
    {
        return new ArrayIterator($this->messages);
    }

    /**
     * @return string[]
     */
    public function getMessages() : array
    {
        return $this->messages;
    }

    public function count() : int
    {
        return count($this->messages);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context=[]) : void
    {
        $replace = [];
        foreach ($context as $k => $v) {
            $replace['{'.$k.'}'] = is_scalar($v) ? (string) $v : get_debug_type($v);
        }

        $this->messages[] = sprintf('[%s] %s', $level, strtr($message, $replace));
    }
}
