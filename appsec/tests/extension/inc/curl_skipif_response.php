<?php
// --SKIPIF-- of the curl tests that look at response data: curl_skipif.php,
// and libcurl 7.80.0 or later. Below 7.80 the header and write functions are
// not interposed and no response data is collected or analysed (exception to
// G7.5; G3.4).
include __DIR__ . '/curl_skipif.php';
curl_skipif_needs_prereq('the response data');
