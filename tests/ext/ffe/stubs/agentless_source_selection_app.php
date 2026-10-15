<?php

echo "fixture_child_ready\n";
\DDTrace\ffe_evaluate('flag', \DDTrace\FFE_STRING, null, array(), false);
echo json_encode(\DDTrace\Internal\ffe_provider_state());
