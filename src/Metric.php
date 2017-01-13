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

final class Metric
{
    private $name;
    private $value;
    private $unit;
    private $dimensions;

    public function __construct($name, $value, $unit, array $dimensions=[])
    {
        $this->name = $name;
        $this->value = $value;
        $this->unit = $unit;
        $this->dimensions = $dimensions;
    }

    public static function count($name, $value, array $dimensions=[])
    {
        return new self($name, $value, 'Count', $dimensions);
    }

    public static function millis($name, $value, array $dimensions=[])
    {
        return new self($name, $value, 'Milliseconds', $dimensions);
    }

    public function toClientArray(array $dimensions=[])
    {
        return [
            'MetricName' => $this->name,
            'Value' => $this->value,
            'Unit' => $this->unit,
            'Dimensions' => self::toClientDimensions(array_replace(
                $dimensions,
                $this->dimensions
            )),
        ];
    }

    private static function toClientDimensions(array $kvs)
    {
        $out = [];
        foreach ($kvs as $name => $value) {
            $out[] = [
                'Name' => $name, 
                'Value' => self::limitString($value),
            ];
        }

        return $out;
    }

    /**
     * Cloudwatch only allows 255 characters for dimension names and values. This
     * ensures that the content for those values from users is limited to an
     * appropriate length.
     */
    private static function limitString($in)
    {
        return substr($in, 0, 255);
    }
}
