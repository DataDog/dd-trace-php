<?php

namespace DDTrace\Util;

use DDTrace\Data\Span;
use DDTrace\Tag;

/**
 * @internal This class is meant only for internal usage. It can change at any time without any notice.

 * @package DDTrace\Util
 */
final class SpanTagger
{
    private static $instance;

    private $isPeerServiceTaggingEnabled = true;

    private $peerServiceMapping = [];

    private function __construct()
    {
        // Do initialization using config params
        $this->isPeerServiceTaggingEnabled = dd_trace_env_config("DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED");
        $this->peerServiceMapping = \dd_trace_env_config("DD_TRACE_PEER_SERVICE_MAPPING");
    }

    /**
     * Return the singleton instanceof the tagger, initiatialized with the proper configs.
     *
     * @return SpanTagger
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new SpanTagger();
        }

        return self::$instance;
    }

    /**
     * Given an array of precursors (a.k.a. tag names), it will iterate through span tags, set peer.service based on the
     * value of the precursor found.
     * @param Span $span
     * @param string[] $orderedPrecursorsNames A sorted list of precursors, the first one that is found will be used.
     * @return void
     */
    public function setPeerService(Span $span, array $orderedPrecursorsNames)
    {
        if ($this->isPeerServiceTaggingEnabled) {
            foreach ($orderedPrecursorsNames as $precursor) {
                $value = $span->getTag($precursor);
                if (!empty($value)) {
                    if (isset($this->peerServiceMapping[$value])) {
                        // Remapping
                        $remapped = $this->peerServiceMapping[$value];
                        if (!empty($remapped)) {
                            $value = $remapped;
                        }
                    }
                    $span->setTag(Tag::PEER_SERVICE, $value);
                    $span->setTag(TAG::PEER_SERVICE_SOURCE, $precursor);
                    break;
                }
            }
        }

        // If peer.service tagging is not enabled, nothing to do
    }
}
