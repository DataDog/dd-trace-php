<?php

namespace DDTrace\Tests\Common;

/**
 * A tracer to connect to the agent  replayer.
 */
trait AgentReplayerTrait
{
    /**
     * Returns the agent replayer endopint.
     *
     * @return string
     */
    public function getAgentReplayerEndpoint()
    {
        return 'http://request-replayer';
    }

    /**
     * Returns the latest agent replayer request.
     *
     * @return mixed|array
     */
    public function getLastAgentRequest()
    {
        return json_decode(file_get_contents($this->getAgentReplayerEndpoint() . '/replay'), true);
    }
}
