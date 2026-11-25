# `_dd.appsec.normalized_route`

The root span tag `_dd.appsec.normalized_route` (RFC-1103, amended by
RFC-1125, which replaces its normalization rules) is set by the Laravel,
Symfony, Laminas and WordPress integrations. It is built by
`DDTrace\Util\RouteNormalizer` from the route the framework matched and from
which of that route's parameters took part in the match. `http.route` is not
affected by any of the cases below.

The route is an OpenAPI path template:

- static text within a URL segment is kept (`/users/user-{id}`) and
  percent-encoded: every character other than `A-Z a-z 0-9 - . _ ~` is
  encoded with uppercase hex. Where the framework matches the encoded path
  (Laminas, WordPress), an existing escape is kept (`%c3%a9` -> `%C3%A9`);
  where it matches the decoded path (Laravel, Symfony, and WordPress when the
  rule only matches the decoded path), `%` is a literal character (`%25`);
- parameters in the same segment stay separate, with the text between them
  (`/photos/{id}.{format}`);
- a parameter name that is already valid (`[A-Za-z0-9_]`) keeps it; in other
  names each Unicode code point outside that set becomes `_` (`föo` ->
  `f_o`). A replaced name equal to a name already in the route gets the
  smallest numeric suffix not used in the route (`/a/{user-id}/{user_id}` ->
  `/a/{user_id_2}/{user_id}`), so replaced names colliding with each other
  are suffixed in path order;
- an unnamed parameter or catch-all is `{param<N>}`, with the smallest N not
  used in the route, and is placed where it matched: a catch-all that
  continues a segment stays in it (`/api{param1}`);
- the route ends with `/` if and only if the route declares a trailing slash
  and nothing follows it.

An optional part (parameter, static text or group) is in the route when the
framework bound a non-empty value to it from the URL (for static text: when
its match included it), and left out otherwise; an empty value or a default
is absent. Every catch-all is treated as optional. Presence is resolved with
the framework's own matching data, or by running the framework's own pattern
again on the same subject. Where presence cannot be determined, the route
falls back to its mandatory parts: the tag is never omitted because optional
parts cannot be resolved. This page lists, per integration, where that
fallback is used, and the remaining cases where no route can be built at all
(no route determined, or a route that cannot be expressed as path segments
and parameters).

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
integrations do; a template-only normalization would not describe the
effective route of the request, as RFC-1125 requires.

- No other framework is supported (e.g. Lumen, Mezzio, Phalcon).

## Laravel

Set by the `Router::findRoute` hook. Falls back to the mandatory parameters
when the route has an optional parameter (`{name?}`) and Laravel's path
capture map was not recorded for the request. The map is taken from
`RouteParameterBinder::bindPathParameters()` (5.4+) or `Route::bindPathParameters()` (<= 5.3), before route defaults are
merged. A route bound without going through these methods has no map.

An optional parameter that matched an empty string counts as absent, as it
does for Laravel. Laravel matches the decoded path, so a `%` in a route is
static text (`%25`).

## Symfony

Set in the `HttpKernel::handle` hook from Symfony's compiled URL generator
cache (`url_generating_routes.php`, or `UrlGenerator.php` on 4.3-4.4). Drupal
requests go through the same code. Omitted when:

- `DD_TRACE_SYMFONY_HTTP_ROUTE` is false;
- the request has no `_route`, the container has no `kernel.cache_dir`, the
  generator cache file is missing, or the route is not in it under its name
  or its `name.locale` (i18n) name.

For a route whose trailing variables have defaults (e.g. `/posts/{page}` with
a default `page`), Symfony's own matcher regex for the route is run again on
the decoded path info. It falls back to the mandatory variables when:

- the route has host variables;
- Symfony's `RouteCompiler` does not reproduce the generator's tokens, or
  throws;
- the regex does not match the path;
- the path has a trailing slash that the last variable did not take, and the
  path without it also matches (Symfony redirects there, or another route may
  have matched).

An optional variable the replay bound to an empty value (e.g. `{rest}` with
requirement `.*` on `/catchall/`) is absent, together with the separator
Symfony puts in its optional. A mandatory variable is in the route whatever
its value.

The route is the route path of the matched route. The request's base URL
(the directory of the front controller) is not part of it. Symfony matches
the decoded path, so a `%` in a route is static text (`%25`).

Where opcache is enabled, the generator cache is loaded on every request, and
the process-lifetime routing cache is keyed by a hash of the loaded data of the
matched route. A route rebuilt by `cache:clear` or a deploy gets a new key
whatever the file's modification time, and when opcache still serves the
previous generator file, the entry used is the one for that file, which is the
one Symfony routes with, so `http.route` is not changed.

Where opcache is not enabled for the SAPI (e.g. a long-running CLI server with
`opcache.enable_cli` off), every load reads the file, so the cache is keyed by
the generator file's path and modification time, and a cache hit does not load
it. Nothing is cached while the modification time is the current second, since
a rewrite within that second would keep it.

## Laminas

Set by the `RouteInterface::match` hook for the top-level `TreeRouteStack`
match of a named route. The routes Laminas matched are recorded while
`Laminas\Mvc\RouteListener::onRoute` runs, and their patterns are run again
on the same path, at the same offset, to tell which optional parts took part.
A `Regex` route is a whole-route regex: the text it matched outside capture
groups is static text, each capture that took part is a parameter (unnamed
ones get `param<N>`; an unnamed capture around a named one, as in
`(\.(?<format>json))?`, only groups it), and its spec plays no part. A `Wildcard` is an unnamed
catch-all, absent when it matched nothing. A `Segment` translated literal
(`{key}`, with a `TranslatorAwareTreeRouteStack`) is static text: its
translation with the router's translator, text domain and locale, as
`Segment::match()` translates it.

It falls back to the mandatory parts of the named route (Literal text,
Segment literals and translated literals and the Segment parameters outside optionals, a Regex's
literal text and mandatory captures; a Wildcard adds nothing) when:

- routing happens outside `RouteListener::onRoute` (e.g. non-MVC
  applications using `TreeRouteStack` directly): nothing is recorded. A later
  match of another URL by application code during the same request does not
  change the tag set by the request's own routing;
- some route consumed path without being recorded: a route type other than
  `Literal`, `Segment`, `Regex` or `Wildcard`, or a `match()` that builds its
  own `RouteMatch`;
- a `Segment` optional with a leading separator matched an empty capture
  (e.g. `[/:a]` on `/e/`): the empty parameter is absent, and the separator
  it matched is not explained by any shape of the route;
- a `Wildcard` matched more than one piece without starting with `/`;
- replaying a `Segment` keeps more than 256 distinct states (offset and
  captures used so far), which takes hundreds of optionals in one route;
- replaying a route throws.

The tag is not set, as the route cannot be expressed, when a `Regex` route
can match text outside its captures that is not plain literal text (a
character class, `.`, an escape such as `\d`, a repeated part, a lookaround),
and when the fallback is needed but the named route has a `Regex` route with
an alternation outside an optional part, a `Segment` with translated
literals and no translator in the match options, or a route type
laminas-router does not ship.

Deliberate simplifications:

- The router's base URL (`TreeRouteStack::getBaseUrl()`, the application's
  directory below the document root) is not part of the route, as it is not
  part of `http.route`.
- A `Wildcard` always renders as one catch-all element `{param<N>}`, however
  many key/value pairs it matched.

## WordPress

Set by the `WP::main` hook when WordPress matched a rewrite rule
(`did_permalink`) and the request is not a 404. The matched rule is run again
on `WP::$request`, and, like WordPress, on its URL-decoded form when it does
not match the raw one. The rule is a whole-route regex: text of the subject
outside the captures that took part is static text, each such capture is a
parameter (named captures keep their name, unnamed ones get `param<N>`, the
smallest N not used), and a capture that matched an empty string is absent.
WordPress matches `#^rule#`, so a rule without `$` is a prefix route: the
rest of the path is its unnamed catch-all.

It falls back to the rule's mandatory parts (its literal text and the
captures outside optional parts, without the optional ones) when which
captures took part cannot be determined:

- WordPress may have matched a subject that is not kept. It swaps the
  subject for `$requested_file/$requested_path` from the first rule that
  starts with the requested file, so the fallback is used when `PATH_INFO` is
  not empty (e.g. `/index.php/foo`) and the requested file cannot be shown to
  be empty. A site whose server rewrites every request to `index.php/$1` has
  the whole request URI consumed by the path info, leaving an empty requested
  file: no swap can happen there and the rule is replayed. A subdirectory
  installation whose request URI is exactly the home path uses the fallback,
  though WordPress would not have swapped;
- the rule matches neither `WP::$request` nor its URL-decoded form.

The tag is not set, as the route cannot be expressed, when:

- the rule can consume text that is not inside a capture group: outside
  captures it uses `.`, a character class, `*`, `+`, `{`, an escape such as
  `\d` or `\w`, an inline option or a lookaround. This rejects WordPress's
  index permalink rules (`index.php/...`) and its default attachment rules;
- the fallback is needed and the rule has an alternation outside an optional
  part (which branch matched is unknown);
- the capture names do not line up with their groups.

Deliberate simplifications:

- WordPress strips the path of a subdirectory installation (`home_url()`)
  from `WP::$request`, so it is not part of the route.
- A capture nested in another is covered by the outer one.
