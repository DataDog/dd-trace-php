<?php
// Child script for php2shell_mitigation_memory_limit_below_chunk.phpt: does as
// little as possible, so the request fits in memory_limit=1M (which the engine
// raises to one 2 MiB chunk). Prints the memory_limit in effect, so a refused
// -d value (the default then applies) is visible. The memory_limit=0 startup
// case sends it a body whose parse does not fit, so it never runs there.
echo "SCRIPT RAN memory_limit=", ini_get('memory_limit'),
    " get_fields=", count($_GET), " post_fields=", count($_POST), "\n";
