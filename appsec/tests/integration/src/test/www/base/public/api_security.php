<?php

$rootSpan = \DDTrace\root_span();
$rootSpan->meta["http.route"] = $_GET["route"] ?: "/foo/bar";

header("Content-Type: application/json");

echo json_encode([
    "status" => "ok",
    "messages" => ["foo", "bar"]
]);
