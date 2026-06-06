<?php

namespace Bench {
    class AlphaThing {
        public $payload;
    }

    class BetaThing {
        public $payload;
    }
}

namespace {
    // Keep allocations rooted via globals so they survive every GC cycle.
    // Counts are chosen well above the 32-object emission threshold and well
    // above any engine-internal class population we'd realistically see in a
    // 20-line CLI script, so AlphaThing must lead BetaThing in the survivors
    // label.
    $GLOBALS['alphas'] = [];
    for ($i = 0; $i < 200; $i++) {
        $GLOBALS['alphas'][] = new \Bench\AlphaThing();
    }

    $GLOBALS['betas'] = [];
    for ($i = 0; $i < 100; $i++) {
        $GLOBALS['betas'][] = new \Bench\BetaThing();
    }

    // User-induced GC — guarantees the timeline GC sample fires on a known
    // heap.
    gc_collect_cycles();
}
