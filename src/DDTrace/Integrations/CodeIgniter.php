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

    private $span_codeigniter;
    private $span_system;
    private $span_controller_construct;
    private $span_controller;

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
        if (!isset($EXT->hooks['pre_controller']))
            $EXT->hooks['pre_controller'] = array();
        $EXT->hooks['pre_controller'][] = function() use ($self) { $self->pre_controller(); };

        if (!isset($EXT->hooks['post_controller_constructor']))
            $EXT->hooks['post_controller_constructor'] = array();
        $EXT->hooks['post_controller_constructor'][] = function() use ($self) { $self->post_controller_constructor(); };

        if (!isset($EXT->hooks['post_controller']))
            $EXT->hooks['post_controller'] = array();
        $EXT->hooks['post_controller'][] = function() use ($self) { $self->post_controller(); };

        if (php_sapi_name() == 'cli') {
            $this->enabled = false;
        } else {
            $this->create_tracer();

            $scope = $this->tracer->startActiveSpan('codeigniter.system');
            $this->span_system = $scope->getSpan();
        }
    }

    public function create_tracer()
    {
        $self = $this;
        $this->enabled = true;
        $tracer = new Tracer(new Http(new Json()));

        GlobalTracer::set($tracer);
        $this->tracer = $tracer;

        $scope = $tracer->startActiveSpan('codeigniter');
        $this->span_codeigniter = $scope->getSpan();
        $this->span_codeigniter->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);

        dd_trace('CI_URI', '_set_uri_string', function ($str) {
            try {
                $this->_set_uri_string($str);
            } catch (\Exception $e) {
                throw $e;
            } finally {
                if (isset($this->span_codeigniter))
                    $this->span_codeigniter->setResource($str);
            }
        });

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

        dd_trace('CI_Loader', 'database', function ($params = '', $return = FALSE, $query_builder = NULL) use ($self) {
            $scope = GlobalTracer::get()->startActiveSpan('codeigniter.database');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'codeigniter.database');
            $span->setResource('codeigniter.database');
            try {
                $db = $this->database($params, $return, $query_builder);
                $span->setTag(Tags\SERVICE_TYPE, get_class($db));
                $self->hook_database($db);

                return $db;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
    }

    public function hook_database($db)
    {
        if ($this->hooked_database)
            return;
        $this->hooked_database = true;

        $db_class = get_class($db);

        dd_trace($db_class, 'query', function ($sql, $binds = FALSE, $return_object = NULL) {
            $scope = GlobalTracer::get()->startActiveSpan('codeigniter.database.query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'codeigniter.database');
            $span->setResource($sql);
            try {
                $result = $this->query($sql, $binds, $return_object);
                $span->setTag('db.rowcount', $result->num_rows());
                return $return;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    public function pre_controller()
    {
        if (!$this->enabled)
            return;

        if (isset($this->span_system) && $this->span_system !== null)
            $this->span_system->close();

        $scope = GlobalTracer::get()->startActiveSpan('codeigniter.controller.construct');
        $this->span_controller_construct = $scope->getSpan();
    }

    public function post_controller_constructor()
    {
        $this->CI = &get_instance();

        if ($this->enabled == false)
            return;

        if (isset($this->span_controller_construct) && $this->span_controller_construct !== null)
        {
            $this->span_controller_construct->overwriteOperationName($this->CI->uri->rsegment(0).'.__construct');
        }

        $scope = GlobalTracer::get()->startActiveSpan($this->CI->uri->rsegment(0).'.'.$this->CI->uri->rsegment(1));
        $this->span_controller = $scope->getSpan();
    }

    public function post_controller()
    {
        if (!$this->enabled)
            return;

        if (isset($this->span_controller) && $this->span_controller !== false)
            $this->span_controller->close();
    }
}
