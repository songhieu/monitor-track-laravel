<?php

namespace MonitorTrack\Monolog;

use Monolog\Logger;
use MonitorTrack\Client;

/**
 * `custom` driver factory for the "monitor-track" logging channel:
 *
 *     'monitor-track' => ['driver' => 'custom', 'via' => ChannelFactory::class, 'level' => 'debug'],
 */
final class ChannelFactory
{
    public function __construct(private ?Client $client = null)
    {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        return new Logger(
            (string) ($config['name'] ?? 'monitor-track'),
            [new Handler($this->client, $config['level'] ?? 'debug', (bool) ($config['bubble'] ?? true))],
        );
    }
}
