<?php
// Leave the first helper request unfinished, then check its replacement.
\datadog\appsec\testing\rinit();
\datadog\appsec\push_addresses(['http.client_ip' => '80.80.80.80']);
echo 'The replacement helper client did not block the request';
