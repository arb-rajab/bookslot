<?php

namespace App\Queue\RabbitMq;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

class RabbitMqConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        return new RabbitMqQueue($config);
    }
}
