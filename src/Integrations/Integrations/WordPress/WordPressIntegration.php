<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\WordPress\V4\WordPressIntegrationLoader;

class WordPressIntegration extends Integration
{
    const NAME = 'wordpress';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

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
        });

         \DDTrace\hook_function(
            'wp_authenticate',
            null,
            function ($par, $retval) {
                $userClass = '\WP_User';
                if (!($retval instanceof $userClass)) {
                    //Login failed
                    if (!function_exists('\datadog\appsec\track_user_login_failure_event'))
                    {
                        return;
                    }
                    $errorClass = '\WP_Error';
                    $exists = $retval instanceof $errorClass &&
                        \property_exists($retval, 'errors') &&
                        is_array($retval->errors) &&
                        isset($retval->errors['incorrect_password']);

                    $usernameUsed = isset($_POST['log']) ?  $_POST['log']: '';
                    \datadog\appsec\track_user_login_failure_event($usernameUsed, $exists, [], true);
                    return;
                }
                //From this moment on, login is succesful
                if (!function_exists('\datadog\appsec\track_user_login_success_event'))
                {
                    return;
                }
                $data = \property_exists($retval, 'data') ? $retval->data: null;

                $id = \property_exists($data, 'ID') ? $data->ID: null;
                $metadata = [];
                if (\property_exists($data, 'user_email')) {
                    $metadata['email'] = $data->user_email;
                }

                if (\property_exists($data, 'display_name')) {
                    $metadata['name'] = $data->display_name;
                }
                \datadog\appsec\track_user_login_success_event(
                    $id,
                    $metadata,
                    true
                );
            }
        );

        return self::LOADED;
    }
}
