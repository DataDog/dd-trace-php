<?php
// the only purpose this script has is to trigger as much cheap timeline samples
// as possible

for ($i = 0; $i < 100_000; $i++) {
    gc_collect_cycles();
}
