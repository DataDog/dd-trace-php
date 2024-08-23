<?php

namespace datadog\appsec;

if (!class_exists('datadog\appsec\AppsecStatus')) {
    class AppsecStatus {

        private static $instance = null;
        private $connection;

        protected function __construct() {
        }

        public static function getInstance()
        {
            if (!self::$instance) {
                self::$instance = new static();
            }

            return self::$instance;
        }

        protected function getDbPdo()
        {
            if (!isset($this->connection)) {
                $pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');
                $pdo->exec("CREATE DATABASE IF NOT EXISTS test");
                $this->connection = new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
            }
            return $this->connection;
        }

        /**
        * Not all test are interested on events but frameworks are instrumented so this check is to avoid errors
        */
        private function initiated()
        {
            return $this->getDbPdo()
                ->query("SELECT * FROM information_schema.tables WHERE table_name = 'appsec_events'")
                ->rowCount() > 0;
        }

        public function init()
        {
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000), token varchar(100))");
        }

        public function setDefaults()
        {
            if (!$this->initiated()) {
                return;
            }
            $this->getDbPdo()->exec("DELETE FROM appsec_events WHERE token = '" . ini_get("datadog.trace.agent_test_session_token") . "'");
        }

        public function addEvent(array $event, $eventName)
        {
            if (!$this->initiated()) {
                return;
            }

            $event['eventName'] = $eventName;
            $this->getDbPdo()->exec(sprintf("INSERT INTO appsec_events VALUES ('%s', '%s')", json_encode($event), ini_get("datadog.trace.agent_test_session_token")));
        }

        public function getEvents(array $names = [], array $addresses = [])
        {
            $result = [];

            if (!$this->initiated()) {
                return $result;
            }

            $events = $this->getDbPdo()->query("SELECT * FROM appsec_events WHERE token = '" . ini_get("datadog.trace.agent_test_session_token") . "'")->fetchAll();

            foreach ($events as $event) {
                $new = json_decode($event['event'], true);
                if (empty($names) || in_array($new['eventName'], $names) &&
                    (empty($addresses) || !empty(array_intersect($addresses, array_keys($new))))) {
                    $result[] = $new;
                }
            }

            return $result;
        }
    }
}

if (!function_exists('datadog\appsec\appsecMockEnabled')) {
    function appsecMockEnabled() {
        return getenv('APPSEC_MOCK_ENABLED') === "true";
    }
}

if (!function_exists('datadog\appsec\track_user_login_success_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_success_event($userId, $metadata, $automated) {
        if(!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,
            'automated' => $automated

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_success_event');
    }
}

if (!function_exists('datadog\appsec\track_user_login_failure_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_failure_event($userId, $exists, $metadata, $automated) {
        if(!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'exists' => $exists,
            'metadata' => $metadata,
            'automated' => $automated

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_failure_event');
    }
}

if (!function_exists('datadog\appsec\track_user_signup_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_signup_event($userId, $metadata, $automated) {
        if(!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,
            'automated' => $automated

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_signup_event');
    }
}

if (!function_exists('datadog\appsec\push_address')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     * @param array $params
     */
    function push_address($key, $value) {
        if(!appsecMockEnabled()) {
           return;
        }
        AppsecStatus::getInstance()->addEvent([$key => $value], 'push_address');
    }
}
