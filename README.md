# Queue CloudWatch

A `pmg/queue` driver decorator that dispatches cloudwatch metrics when messages
are sent through.

## Example

```php
use AWS\CloudWatch\CloudWatchClient;
use PMG\Queue\Driver;
use PMG\Queue\CloudWatch\MetricsDriver;

/** @var Driver $driver */
$driver = createAnActualDriverSomehow();

// this is the default metric namespace, change if desired.
$metricNamespace = 'PMG/Queue';
$finalDriver = new MetricsDriver($driver, CloudWatchClient::factory([
    'region' => 'us-east-1',
    'version' => 'latest',
]), $metricNamespace);

// now use $finalDriver in your consumers/producers
```

## Metrics

All metrics have the dimensions...

- `QueueName` - The name of the queue to which the metrics belong
- `MessageName` - The value returned from `Message::getName`. When a message is
  not present for logging the metric, this dimension will be set to `__none__`.

### Driver Metrics

- `DriverError` - A `Count` metric unit fired when the wrapped driver throws a
  `DriverError` exception. This will have an `ErrorClass` dimension that contains
  the exception class name that was thrown.

### Message Counts

There's a metric for each method on the driver, essentially. They all use
`Count` as the metric unit.

- `MessageEnqueue` - Fired on `Driver::enqueue`
- `MessageDequeue` - Fired on `Driver::dequeue`. This is only fired when a
  message is returned from the wrapped `Driver::dequeue`.
- `MessageSuccess ` - Fired on `Driver::ack`
- `MessageFailure` - Fired on `Driver::fail`
- `MessageRetry` - Fired on `Driver::retry`

You might use these message counts to alert on a high volume of message
failures or retries.

### Message Timers

The metrics driver will time dequeued jobs until they are acked, failed, or
retried. These all use `Milliseconds` as their metric unit.

- `MessageTime` - The amount of time a message took, tracked for every message
  regardless of how it finished.

The `MessageTime` metric will have an additional dimension named `MessageStatus`
which is how the given message finished when the timer completed. This will be:

- `Success` when the message was passed to `Driver::ack`
- `Failure` when the message was passed to `Driver::fail`
- `Retry` When the message was passed to `Driver::retry`

## Error Handling

First up, the wrapped driver is *always* called first. If an error occurs from
the wrapped driver it's tracked and rethrown before any further metrics can be
logged. The idea here is that driver errors invalidate any processes acting on a
message anyway.

Errors from the cloudwatch client are caught and logged, however. You can pass a
fourth `$logger` argument to `MetricsDriver` if you wish to see these errors,
but a `NullLogger` is used by default.

```php
use AWS\CloudWatch\CloudWatchClient;
use PMG\Queue\Driver;
use PMG\Queue\CloudWatch\MetricsDriver;

/** @var Driver $driver */
$driver = createAnActualDriverSomehow();

// this is the default metric namespace, change if desired.
$metricNamespace = 'PMG/Queue';
$finalDriver = new MetricsDriver($driver, CloudWatchClient::factory([
    'region' => 'us-east-1',
    'version' => 'latest',
]), $metricNamespace, $yourLoggerFromSomeplace);

// now use $finalDriver in your consumers/producers
```

## Testing

```
./vendor/bin/phpunit
```

The tests include a set of integration tests that actually talk to CloudWatch.
Be sure to have [set up credentials](http://docs.aws.amazon.com/aws-sdk-php/v3/guide/guide/credentials.html).
in order to run those tests. Otherwise you may exclude them with...

```
./vendor/bin/phpunit --exclude-group integration
```
