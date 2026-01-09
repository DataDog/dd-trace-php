<?php
$rootSpan = \DDTrace\root_span();
$case = $_GET['case'] ?? 'unknown';

switch ($case) {
    case 'with_route':
        // Test case: http.route is present - should use it for sampling
        $rootSpan->meta["http.route"] = "/users/{id}/profile";
        $rootSpan->meta["http.method"] = "GET";

        header("Content-Type: application/json");
        http_response_code(200);

        echo json_encode([
            "status" => "ok",
            "test_case" => "with_route",
            "messages" => ["test", "data"]
        ]);
        break;

    case 'with_endpoint':
        // Test case: http.route is absent, http.endpoint is present - should use http.endpoint for sampling
        // Do NOT set http.route
        $rootSpan->meta["http.endpoint"] = "/api/products/{param:int}";
        $rootSpan->meta["http.method"] = "GET";

        header("Content-Type: application/json");
        http_response_code(200);

        echo json_encode([
            "status" => "ok",
            "test_case" => "with_endpoint",
            "messages" => ["test", "data"]
        ]);
        break;

    case '404':
        // Test case: http.route is absent, http.endpoint is present, but status is 404 - should NOT sample
        // Do NOT set http.route
        $rootSpan->meta["http.endpoint"] = "/api/notfound/{param:int}";
        $rootSpan->meta["http.method"] = "GET";

        header("Content-Type: application/json");
        http_response_code(404);

        echo json_encode([
            "status" => "error",
            "test_case" => "404_with_endpoint",
            "message" => "Not found"
        ]);
        break;

    case 'computed':
        // Test case: Neither http.route nor http.endpoint set - should compute endpoint on-demand
        // The endpoint should be computed but NOT added as a tag on the span
        // Do NOT set http.route or http.endpoint
        // Set http.url so endpoint can be computed
        $rootSpan->meta["http.url"] = "http://localhost:8080/endpoint_fallback_computed/users/123/orders/456";
        $rootSpan->meta["http.method"] = "GET";

        header("Content-Type: application/json");
        http_response_code(200);

        echo json_encode([
            "status" => "ok",
            "test_case" => "computed_on_demand",
            "messages" => ["test", "data"],
        ]);
        break;

    default:
        header("Content-Type: application/json");
        http_response_code(400);

        echo json_encode([
            "status" => "error",
            "test_case" => "unknown",
            "message" => "Invalid case parameter. Valid values: with_route, with_endpoint, 404, computed"
        ]);
        break;
}
