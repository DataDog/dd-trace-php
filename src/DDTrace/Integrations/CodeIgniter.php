<?php

namespace DDTrace\Integrations;

use DDTrace\Encoders\Json;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Types;
use DDTrace\Transport\Http;
use OpenTracing\GlobalTracer;

/**
 * DataDog CodeIgniter tracing library. Use by installing the dd-trace library.
 *
 * composer require datadog/dd-trace
 *
 * And then to your config/config.php ensure hooks are enabled.
 *
 * $config['enable_hooks'] = TRUE;
 *
 * And in config/hooks.php add:
 *
 * $hook['pre_system'] = function() { new DDTrace\Integrations\CodeIgniter };
 */

class CodeIgniter
{
    private $enabled = true;
    private $hooked_database = false;

    private $tracer;
    private $CI;

    private $scope_codeigniter;
    private $scope_system;
    private $scope_controller_construct;
    private $scope_controller;

    public function __construct()
    {
        $this->pre_system();
    }

    public function pre_system()
    {
        global $EXT;

        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load CodeIgniter integration.', E_USER_WARNING);
            return;
        }

        $self = $this;
        if (!isset($EXT->hooks['pre_controller'])) {
            $EXT->hooks['pre_controller'] = array();
        }
        $EXT->hooks['pre_controller'][] = function() use ($self) { $self->pre_controller(); };

        if (!isset($EXT->hooks['post_controller_constructor'])) {
            $EXT->hooks['post_controller_constructor'] = array();
        }
        $EXT->hooks['post_controller_constructor'][] = function() use ($self) {
            $self->post_controller_constructor();
        };

        if (!isset($EXT->hooks['post_controller'])) {
            $EXT->hooks['post_controller'] = array();
        }
        $EXT->hooks['post_controller'][] = function() use ($self) {
            $self->post_controller();
        };

        if (php_sapi_name() == 'cli') {
            $this->enabled = false;
        } else {
            $this->create_tracer();

            $scope = $this->tracer->startActiveSpan('codeigniter.system');
            $this->scope_system = $scope;
        }
    }

    public function create_tracer()
    {
        $this->enabled = true;
        $tracer = new Tracer(new Http(new Json()));

        GlobalTracer::set($tracer);
        $this->tracer = $tracer;

        $scope = $tracer->startActiveSpan('codeigniter');
        $this->scope_codeigniter = $scope;
        $span = $this->scope_codeigniter->getSpan();
        $span->setTag(Tags\SERVICE_NAME, $this->getAppName());
        $span->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);

        if (class_exists('Memcached')) {
            Memcached::load();
        }
        PDO::load();
        if (class_exists('Predis\Client')) {
            Predis::load();
        }

        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
    }

    public function pre_controller()
    {
        if (!$this->enabled) {
            return;
        }

        if (isset($this->scope_system) && $this->scope_system !== null) {
            $this->scope_system->close();
        }

        $scope = GlobalTracer::get()->startActiveSpan('codeigniter.controller.construct');
        $this->scope_controller_construct = $scope;
    }

    public function post_controller_constructor()
    {
        $this->CI = &get_instance();
        $self = $this;

        $this->CI->ddtrace = $this;

        if ($this->enabled == false) {
            return;
        }

        if (isset($this->scope_codeigniter) && $this->scope_codeigniter !== null)
        {
            dd_trace('CI_URI', '_set_uri_string', function ($str) {
                try {
                    $this->_set_uri_string($str);
                    $this->scope_codeigniter->getSpan()->setResource($str);
                } catch (\Exception $e) {
                    throw $e;
                }
            });
        }

        dd_trace('CI_Loader', 'view', function ($view, $data = array(), $return = false) {
            $scope = GlobalTracer::get()->startActiveSpan('codeigniter.view');
            $span = $scope->getSpan();
            try {
                $span->setTag('view', $view);
                return $this->view($view, $data, $return);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        dd_trace('CI_Loader', 'database', function ($params = '', $return = false, $query_builder = null) use ($self) {
            $scope = GlobalTracer::get()->startActiveSpan('codeigniter.database');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'codeigniter.database');
            $span->setResource('codeigniter.database');

            try {
                $db = $this->database($params, $return, $query_builder);
                $span->setTag(Tags\SERVICE_NAME, get_class(get_instance()->db));
                $self->hook_database();

                return $db;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        if (isset($this->scope_controller_construct) && $this->scope_controller_construct !== null) {
            $span = $this->scope_controller_construct->getSpan();
            $span->overwriteOperationName($this->CI->uri->ruri_string().'.__construct');
            $this->scope_controller_construct->close();
        }

        $scope = GlobalTracer::get()->startActiveSpan($this->CI->uri->rsegment(0).'.'.$this->CI->uri->ruri_string());
        $this->scope_controller = $scope;
    }

    public function hook_database()
    {
        if ($this->hooked_database) {
            return;
        }
        $this->hooked_database = true;

        $db_class = get_class($this->CI->db);

        dd_trace($db_class, 'query', function ($sql, $binds = false, $return_object = null) {
            $scope = GlobalTracer::get()->startActiveSpan('codeigniter.database.query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, get_class($this));
            $span->setResource($sql);

            try {
                $result = $this->query($sql, $binds, $return_object);
                if (is_object($result)) {
                    $span->setTag('db.rowcount', $result->num_rows());
                }
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    public function post_controller()
    {
        if (!$this->enabled) {
            return;
        }

        if (isset($this->scope_controller) && $this->scope_controller !== false) {
            $this->scope_controller->close();
        }
    }

    private function getAppName()
    {
        if (isset($_ENV['ddtrace_app_name'])) {
            return $_ENV['ddtrace_app_name'];
        } else {
            return 'codeigniter';
        }
    }
}
