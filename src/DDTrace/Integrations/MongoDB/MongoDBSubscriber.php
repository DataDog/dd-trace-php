<?php

namespace DDTrace\Integrations\MongoDB;

use MongoDB\Driver\Monitoring\CommandSubscriber;
use DDTrace\Type;
use DDTrace\Tag;

class MongoDBSubscriber implements CommandSubscriber
{
    public function commandStarted($ev)
    {
        // We do it here because from the Connection instance we have access to the list of possible servers, but
        // not to the actual server that has been picked (e.g. the PRIMARY). While this would work for 'Standalone'
        // servers, we would not know which server to pick up in 'Replica Set' and 'Sharded Cluster' mode.
        // Here we have the correct selected server, instead.
        // See: https://docs.mongodb.com/manual/reference/connection-string/
        $span = \DDTrace\active_span();
        if ($span && 0 === \strpos($span->name, 'MongoDB\Collection.')) {
            $span->meta[Tag::TARGET_HOST] = $ev->getServer()->getHost();
            $span->meta[Tag::TARGET_PORT] = $ev->getServer()->getPort();
        }
    }

    public function commandFailed($ev)
    {
    }


    public function commandSucceeded($ev)
    {
    }
}
