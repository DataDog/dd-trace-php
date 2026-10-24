# `_dd.appsec.normalized_route`

The root span tag `_dd.appsec.normalized_route` (RFC-1103) is set by the
Laravel, Symfony, Laminas and WordPress integrations. It is built by
`DDTrace\Util\RouteNormalizer` from the route the framework matched and from
which of that route's parameters took part in the match. Optional parts are
resolved with the framework's own matching data, or by running the
framework's own pattern again on the same subject. Where that is not possible
the tag is omitted, never guessed (RFC-1103 R-5). `http.route` is not affected
by any of the cases below.

This page lists, per integration, when the tag is omitted and which parts of
the request path it deliberately leaves out.

## All integrations

- The tag is set only when AppSec is enabled and `DD_API_SECURITY_ENABLED` is
  true. A request with no `http.route` gets no normalized route either.
- Only the path the framework routed is used. A prefix removed before the
  request reaches PHP (e.g. by a reverse proxy) cannot be known and is not
  part of the route.

## Frameworks that set `http.route` but no normalized route

CakePHP, CodeIgniter, Slim and Yii set `http.route` and deliberately do not
set `_dd.appsec.normalized_route`. This is a decision, not a gap to be filled
later: for these integrations `http.route` is not a route template that can be
normalized without emitting values taken from the request.

- **CodeIgniter** — when no configured route matches, `http.route` is the
  concrete request path (`implode('/', $router->uri->segments)`), which is the
  common case for CodeIgniter's implicit `controller/method/arg` routing.
  Normalizing it would copy request-controlled path values into the tag, at
  unbounded cardinality.
- **Yii** — `http.route` is synthesised with `Url::toRoute()` over
  `actionParams`. Only parameters present in the action's argument list become
  `:name`; the rest stay in the route as their literal request values.
- **Slim** — route patterns are available, but optional sections
  (`/users/{id}[/{format}]`, and static ones such as `/feed[.json]`) cannot be
  resolved per request from `getArguments()` alone, and Slim 3 exposes no
  arguments at the hook site at all.
- **CakePHP** — the matched route template is available, but the route's
  matched parameters are not exposed at the hook site, so optional and
  mixed-segment parts cannot be resolved.

Any later attempt to support these must first establish per-request parameter
participation from the framework's own matching data, as the four supported
integrations do; a template-only normalization would violate RFC-1103 R-2/R-3.

- No other framework is supported (e.g. Lumen, Mezzio, Phalcon).

## Laravel

Set by the `Router::findRoute` hook. Omitted when the route has an optional
parameter (`{name?}`) and Laravel's path capture map was not recorded for the
request. The map is taken from `RouteParameterBinder::bindPathParameters()`
(5.4+) or `Route::bindPathParameters()` (<= 5.3), before route defaults are
merged. A route bound without going through these methods has no map.

An optional parameter that matched an empty string counts as absent, as it
does for Laravel.

## Symfony

Set in the `HttpKernel::handle` hook from Symfony's compiled URL generator
cache (`url_generating_routes.php`, or `UrlGenerator.php` on 4.3-4.4). Drupal
requests go through the same code. Omitted when:

- `DD_TRACE_SYMFONY_HTTP_ROUTE` is false;
- the request has no `_route`, the container has no `kernel.cache_dir`, the
  generator cache file is missing, or the route is not in it under its name
  or its `name.locale` (i18n) name;
- for a route whose trailing variables have defaults (e.g.
  `/posts/{page}` with a default `page`), Symfony's own matcher regex for the
  route is run again on the decoded path info, and the tag is omitted when:
  - the route has host variables;
  - Symfony's `RouteCompiler` does not reproduce the generator's tokens, or
    throws;
  - the regex does not match the path;
  - the path has a trailing slash that the last variable did not take, and
    the path without it also matches (Symfony redirects there, or another
    route may have matched).

The route is the route path of the matched route. The request's base URL
(the directory of the front controller) is not part of it.

## Laminas

Set by the `RouteInterface::match` hook for the top-level `TreeRouteStack`
match of a named route. The routes Laminas matched are recorded while
`Laminas\Mvc\RouteListener::onRoute` runs, and their patterns are run again
on the same path, at the same offset, to tell which optional parts took part.
Omitted when:

- routing happens outside `RouteListener::onRoute` (e.g. non-MVC
  applications using `TreeRouteStack` directly): nothing is recorded. A later
  match of another URL by application code during the same request does not
  change the tag set by the request's own routing;
- some route consumed path without being recorded: a route type other than
  `Literal`, `Segment`, `Regex` or `Wildcard`, or a `match()` that builds its
  own `RouteMatch`;
- a `Segment` has translated literals (`{key}` parts), which need the
  router's translator;
- a `Segment` optional with a leading separator matched an empty capture
  (e.g. `[/:a]` on `/e/`): Laminas drops the empty parameter, and the
  separator it matched is not explained by any shape of the route;
- a `Regex` route's spec does not line up with the text the regex matched
  (e.g. text the regex holds in a non-capturing optional, or a placeholder
  that names no capture group), a capture that took part has no placeholder
  in the spec, or the spec is empty;
- a `Wildcard` matched more than one piece without starting with `/`;
- replaying a route throws.

Deliberate simplifications:

- The router's base URL (`TreeRouteStack::getBaseUrl()`, the application's
  directory below the document root) is not part of the route, as it is not
  part of `http.route`.
- A `Wildcard` always renders as one catch-all element `/{paramN}`, however
  many key/value pairs it matched. A Wildcard that matched a single piece with
  no `/` continues the URL segment before it, which then becomes a parameter.
  A Wildcard that matched nothing adds nothing.

## WordPress

Set by the `WP::main` hook when WordPress matched a rewrite rule
(`did_permalink`) and the request is not a 404. The matched rule is run again
on `WP::$request`. Omitted when:

- the rule can consume text that is not inside a capture group: outside
  captures it uses `.`, a character class, `*`, `+`, `{`, an escape such as
  `\d` or `\w`, an inline option or a lookaround. This rejects WordPress's
  index permalink rules (`index.php/...`) and its default attachment rules;
- the rule matches `WP::$request` only after URL-decoding (WordPress tries
  both), or matches only a prefix of it;
- WordPress may have matched a subject that is not kept. It swaps the subject
  for `$requested_file/$requested_path` from the first rule that starts with
  the requested file, so the tag is omitted when `PATH_INFO` is not empty
  (e.g. `/index.php/foo`) and the requested file cannot be shown to be empty.
  A site whose server rewrites every request to `index.php/$1` has the whole
  request URI consumed by the path info, leaving an empty requested file: no
  swap can happen there and the tag is kept. A subdirectory installation whose
  request URI is exactly the home path is omitted, though WordPress would not
  have swapped;
- a capture cannot be placed in a URL segment, or the capture names do not
  line up with their groups.

Deliberate simplifications:

- WordPress strips the path of a subdirectory installation (`home_url()`)
  from `WP::$request`, so it is not part of the route.
- A capture that matched an empty string counts as absent. Unnamed captures
  are named `paramN` after their group number.
