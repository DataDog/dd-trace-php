<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;

class WordPressIntegration extends Integration
{
    const NAME = 'wordpress';

    /**
     * @var string
     */
    private $serviceName;

    public function getServiceName()
    {
        if (!empty($this->serviceName)) {
            return $this->serviceName;
        }

        $this->serviceName = \ddtrace_config_app_name(WordPressIntegration::NAME);

        return $this->serviceName;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function init(): int
    {
        $integration = $this;

        // This call happens right in central config initialization
        \DDTrace\hook_function('wp_check_php_mysql_versions', null, function () use ($integration) {
            if (!isset($GLOBALS['wp_version']) || !is_string($GLOBALS['wp_version'])) {
                return false;
            }
            $majorVersion = substr($GLOBALS['wp_version'], 0, 1);
            if ($majorVersion >= 4) {
                $loader = new WordPressIntegrationLoader();
                $loader->load($integration);
            }

            return true;
        });

        \DDTrace\hook_method('WP', 'main', null, function ($This, $scope, $args) {
            if (\property_exists($This, 'did_permalink') && $This->did_permalink === true) {
                if (
                    function_exists('\datadog\appsec\push_addresses') &&
                    \property_exists($This, 'query_vars') &&
                        function_exists('is_404') && is_404() === false
                ) {
                    $parameters = $This->query_vars;
                    if (count($parameters) > 0) {
                        \datadog\appsec\push_addresses(["server.request.path_params" => $parameters]);
                    }
                }
            }
        });

        \DDTrace\hook_function(
            'wp_authenticate',
            null,
            function ($args, $retval) {
                $userClass = '\WP_User';

                $username = null;
                if (isset($args[0])) {
                    $username = $args[0];
                }

                if (!($retval instanceof $userClass)) {
                    //Login failed
                    if (!function_exists('\datadog\appsec\track_user_login_failure_event_automated')) {
                        return;
                    }
                    if (empty($username)) {
                        return;
                    }
                    $errorClass = '\WP_Error';
                    $exists = $retval instanceof $errorClass &&
                        \property_exists($retval, 'errors') &&
                        is_array($retval->errors) &&
                        isset($retval->errors['incorrect_password']);

                    \datadog\appsec\track_user_login_failure_event_automated($username, $username, $exists, []);
                    return;
                }

                //From this moment on, login is succesful
                if (!function_exists('\datadog\appsec\track_user_login_success_event_automated')) {
                    return;
                }

                $data = \property_exists($retval, 'data') ? $retval->data : null;

                $id = \property_exists($data, 'ID') ? $data->ID : null;
                $metadata = [];
                if (\property_exists($data, 'user_email')) {
                    $metadata['email'] = $data->user_email;
                }

                if (\property_exists($data, 'display_name')) {
                    $metadata['name'] = $data->display_name;
                }

                \datadog\appsec\track_user_login_success_event_automated(
                    $username,
                    $id,
                    $metadata
                );
            }
        );


        \DDTrace\hook_function(
            'register_new_user',
            null,
            function ($args, $retval) {
                if (!function_exists('\datadog\appsec\track_user_signup_event_automated')) {
                    return;
                }

                $metadata = [];
                $login = null;

                if (isset($args[0])) {
                    $metadata['username'] = $args[0];
                    $login = $args[0];
                }

                if (isset($args[1])) {
                    $metadata['email'] = $args[1];
                }

                \datadog\appsec\track_user_signup_event_automated(
                    $login,
                    $retval,
                    $metadata
                );
            }
        );

        \DDTrace\hook_function(
            'wp_validate_auth_cookie',
            null,
            function ($args, $retval) {
                if (!function_exists('\datadog\appsec\track_authenticated_user_event_automated')) {
                    return;
                }

                if ($retval !== false) {
                    \datadog\appsec\track_authenticated_user_event_automated(
                        $retval,
                    );
                }
            }
        );


        return self::LOADED;
    }
}
