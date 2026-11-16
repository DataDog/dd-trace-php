<?php

/** @generate-function-entries */

namespace datadog\appsec {

    /**
     * Mark the current request as a user signup.
     *
     * `$user_id` is the stable identifier of the user being created. It
     * populates the `appsec.events.users.signup.usr.id` tag. Must be a
     * non-empty string; otherwise the call is a no-op.
     *
     * `$metadata` is a free-form `array<string, string>` of additional
     * fields recorded under `appsec.events.users.signup.<key>` (entries
     * whose key is not a string or whose value is not a string are
     * silently dropped). The conventional key to pass here is `usr.login`
     * — the identifier the user typed (email/username) when distinct from
     * `$user_id`. Any other string key is also accepted.
     *
     * Sets the following tags on the root span:
     *   - `appsec.events.users.signup.usr.id` = `$user_id`
     *   - `appsec.events.users.signup.track` = `"true"`
     *   - `appsec.events.users.signup.<key>` = `<value>` for each entry of
     *     `$metadata`. Keys are used verbatim as the trailing segment of
     *     the tag name, so avoid characters that are invalid in tag names.
     *
     * @see https://docs.datadoghq.com/security/application_security/how-it-works/add-user-info/
     */
    function track_user_signup_event(string $user_id, array $metadata = []): void {}

    /**
     * Mark the current request as a successful user login.
     *
     * `$user_id` is the stable identifier of the user logging in. It
     * populates the `usr.id` tag. Must be a non-empty string; otherwise
     * the call is a no-op.
     *
     * `$metadata` is a free-form `array<string, string>` of additional
     * fields recorded under `appsec.events.users.login.success.<key>`
     * (non-string keys and non-string values are silently dropped).
     * Conventional keys to pass here:
     *   - `usr.login` — the identifier the user typed (email/username)
     *                   when distinct from `$user_id`.
     *   - `usr.org`   — tenant / organization, where applicable.
     * Any other string key is also accepted.
     *
     * Sets the following tags on the root span:
     *   - `usr.id` = `$user_id`
     *   - `appsec.events.users.login.success.track` = `"true"`
     *   - `appsec.events.users.login.success.<key>` = `<value>` for each
     *     entry of `$metadata`
     *
     * @deprecated use {@see \datadog\appsec\v2\track_user_login_success()} instead.
     * @see https://docs.datadoghq.com/security/application_security/how-it-works/add-user-info/
     */
    function track_user_login_success_event(string $user_id, array $metadata = []): void {}

    /**
     * Mark the current request as a failed user login.
     *
     * `$user_id` is the identifier of the user that attempted to log in.
     * When non-empty it populates `appsec.events.users.login.failure.usr.id`;
     * when empty (the authenticating identifier doesn't map to a known
     * user) the tag is not set.
     *
     * `$exists` indicates whether the account the caller tried to
     * authenticate as actually exists, and is recorded as the `usr.exists`
     * sub-tag of the failure event.
     *
     * `$metadata` is a free-form `array<string, string>` of additional
     * fields recorded under `appsec.events.users.login.failure.<key>`
     * (non-string keys and non-string values are silently dropped). The
     * conventional key to pass here is `usr.login` — the identifier the
     * user typed (email/username) when distinct from `$user_id`. Any other
     * string key is also accepted; the `usr.exists` key is reserved and
     * is overwritten by `$exists`.
     *
     * Sets the following tags on the root span:
     *   - `appsec.events.users.login.failure.usr.id` = `$user_id` (only
     *     when non-empty)
     *   - `appsec.events.users.login.failure.usr.exists` = `"true"` /
     *     `"false"` depending on `$exists`
     *   - `appsec.events.users.login.failure.track` = `"true"`
     *   - `appsec.events.users.login.failure.<key>` = `<value>` for each
     *     entry of `$metadata`
     *
     * @deprecated use {@see \datadog\appsec\v2\track_user_login_failure()} instead.
     * @see https://docs.datadoghq.com/security/application_security/how-it-works/add-user-info/
     */
    function track_user_login_failure_event(string $user_id, bool $exists, array $metadata = []): void {}

    /**
     * Attach the authenticated user to the current request.
     *
     * `$user_id` is the stable identifier of the authenticated user. It
     * populates the `usr.id` tag. Must be a non-empty string; otherwise
     * the call is a no-op.
     *
     * `$metadata` is a free-form `array<string, string>` of additional
     * user attributes (non-string keys and non-string values are silently
     * dropped). Each entry becomes a `usr.<key>` tag, so callers should
     * pass the bare attribute name (without the `usr.` prefix). Recognized
     * attributes:
     *   - `name`
     *   - `email`
     *   - `session_id`
     *   - `role`
     *   - `scope`
     * (`id` is already set from `$user_id` and does not need to be passed
     * here.) Other string keys are also accepted.
     *
     * Sets the following tags on the root span:
     *   - `usr.id` = `$user_id`
     *   - `_dd.appsec.user.collection_mode` = `"sdk"`
     *   - `usr.<key>` = `<value>` for each entry of `$metadata`
     *
     * {@see \DDTrace\set_user()} is an alternative entry point that also
     * attaches the user to the current request and runs the same
     * authenticated-user check when the AppSec extension is loaded.
     * Differences if you call `\DDTrace\set_user()` instead:
     *   - `_dd.appsec.user.collection_mode = "sdk"` is not set on the span;
     *   - this function attaches a broader set of HTTP request headers as
     *     `http.request.headers.*` tags (forwarding/IP/host/content/accept
     *     headers); `\DDTrace\set_user()` records only a smaller default set;
     *   - `\DDTrace\set_user()` can propagate the user identity to downstream
     *     services across distributed traces (via the `_dd.p.usr.id`
     *     propagation tag); this function cannot.
     *
     * @see https://docs.datadoghq.com/security/application_security/how-it-works/add-user-info/
     */
    function track_authenticated_user_event(string $user_id, array $metadata = []): void {}

    /**
     * Emit a custom AppSec business-logic event on the root span.
     *
     * `$event_name` must be a non-empty string; otherwise the call is a
     * no-op. It is used verbatim as a component of the tag name, so it
     * must be a valid tag fragment.
     *
     * `$metadata` is a free-form `array<string, string>` of fields
     * recorded under `appsec.events.<event_name>.<key>` (non-string keys
     * and non-string values are silently dropped). The `track` key is
     * reserved and is overwritten.
     *
     * Sets the following tags on the root span:
     *   - `appsec.events.<event_name>.track` = `"true"`
     *   - `appsec.events.<event_name>.<key>` = `<value>` for each entry of
     *     `$metadata`
     *
     * @see https://docs.datadoghq.com/security/application_security/how-it-works/add-user-info/
     */
    function track_custom_event(string $event_name, array $metadata = []): void {}
}

namespace datadog\appsec\internal {

    function track_user_signup_event_automated(string $framework, ?string $user_login, ?string $user_id, ?array $metadata = null): void {}

    function track_user_login_success_event_automated(string $framework, ?string $user_login, ?string $user_id, ?array $metadata = null): void {}

    function track_user_login_failure_event_automated(string $framework, ?string $user_login, ?string $user_id, bool $exists, ?array $metadata = null): void {}

    function track_authenticated_user_event_automated(string $framework, ?string $user_id): void {}
}
