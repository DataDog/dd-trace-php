<?php

return function(FastRoute\RouteCollector $r) {
    $r->addRoute('GET', '/simple', '\App\SimpleController');
    $r->addRoute('GET', '/simple_view', '\App\SimpleViewController');
    $r->addRoute('GET', '/error', '\App\ErrorController');

    $r->addRoute('GET', '/pdo', '\App\PDOController');
    $r->addRoute('GET', '/fatal', '\App\FatalErrorController');
};
