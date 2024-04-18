<?php

echo "foo";

\DDTrace\dogstatsd_count("baboum", 32.8, ['foo' => "12", 'bar' => true, "dd"]);
\DDTrace\dogstatsd_gauge("gogo", 21);
