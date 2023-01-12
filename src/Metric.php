<?php declare(strict_types=1);

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
    private string $name;

    private int|float $value;

    private string $unit;

    /**
     * @var array<string, string>
     */
    private array $dimensions;

    /**
     * @param array<string, string> $dimensions
     */
    public function __construct(string $name, int|float $value, string $unit, array $dimensions=[])
    {
        $this->name = $name;
        $this->value = $value;
        $this->unit = $unit;
        $this->dimensions = $dimensions;
    }

    /**
     * @param array<string, string> $dimensions
     */
    public static function count(string $name, int $value, array $dimensions=[]) : self
    {
        return new self($name, $value, 'Count', $dimensions);
    }

    /**
     * @param array<string, string> $dimensions
     */
    public static function millis(string $name, float $value, array $dimensions=[]) : self
    {
        return new self($name, $value, 'Milliseconds', $dimensions);
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(array $dimensions=[]) : array
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

    /**
     * @return array<string, string> $kvs
     * @return array<int, array<string, string>>
     */
    private static function toClientDimensions(array $kvs) : array
    {
        $out = [];
        foreach ($kvs as $name => $value) {
            $out[] = [
                'Name' => $name, 
                'Value' => self::limitDimensionValueString($value),
            ];
        }

        return $out;
    }

    /**
     * Cloudwatch only allows 1024 characters for dimension names and values. This
     * ensures that the content for those values from users is limited to an
     * appropriate length.
     */
    private static function limitDimensionValueString($in) : string
    {
        return substr($in, 0, 1024);
    }
}
