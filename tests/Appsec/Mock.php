<?php

namespace datadog\appsec;

if (!class_exists('datadog\appsec\AppsecStatus')) {
    class AppsecStatus
    {
        private static $instance = null;
        private $connection;

        protected function __construct()
        {
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

        protected function checkPdoErrors($query)
        {
            if ($this->getDbPdo()->errorCode() != '00000') {
                var_dump($this->getDbPdo()->errorInfo());
                var_dump("Query with error: $query");
            }
        }

        protected function runQuery($query)
        {
            $query = $this->getDbPdo()->query($query);
            $this->checkPdoErrors($query);
            return $query;
        }

        protected function execQuery($query)
        {
            $this->getDbPdo()->exec($query);
            $this->checkPdoErrors($query);
        }

        /**
        * Not all test are interested on events but frameworks are instrumented so this check is to avoid errors
        */
        private function initiated()
        {
            $result = $this->runQuery("SELECT * FROM information_schema.tables WHERE table_name in ('appsec_events')")
                ->rowCount() == 1;
            return $result;
        }

        public function init()
        {
            $this->execQuery("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000), token varchar(100))");
        }

        public function setDefaults()
        {
            if (!$this->initiated()) {
                return;
            }
            $this->execQuery("DELETE FROM appsec_events WHERE token = '" . ini_get("datadog.trace.agent_test_session_token") . "'");
        }

        public function addEvent(array $event, $eventName)
        {
            if (!$this->initiated()) {
                return;
            }
            $event['eventName'] = $eventName;
            $event = $this->getDbPdo()->quote(json_encode($event));
            $this->execQuery(sprintf("INSERT INTO appsec_events VALUES (%s, '%s')", $event, ini_get("datadog.trace.agent_test_session_token")));
        }

        public function getEvents(array $names = [], array $addresses = [])
        {
            $result = [];

            if (!$this->initiated()) {
                return $result;
            }

            $events = $this->runQuery("SELECT * FROM appsec_events WHERE token = '" . ini_get("datadog.trace.agent_test_session_token") . "'")->fetchAll();

            foreach ($events as $event) {
                $new = json_decode($event['event'], true);
                if (empty($names) || in_array($new['eventName'], $names) &&
                    (empty($addresses) || !empty(array_intersect($addresses, array_keys($new[0]))))) {
                    $result[] = $new;
                }
            }

            return $result;
        }
    }
}

if (!function_exists('datadog\appsec\appsecMockEnabled')) {
    function appsecMockEnabled()
    {
        return getenv('APPSEC_MOCK_ENABLED') === "true";
    }
}

if (!function_exists('datadog\appsec\track_user_login_success_event_automated')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_success_event_automated($userLogin, $userId, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userLogin' => $userLogin,
            'userId' => $userId,
            'metadata' => $metadata,

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_success_event_automated');
    }
}

if (!function_exists('datadog\appsec\track_user_login_success_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_success_event($userId, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_success_event');
    }
}

if (!function_exists('datadog\appsec\track_user_login_failure_event_automated')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_failure_event_automated($userLogin, $userId, $exists, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userLogin' => $userLogin,
            'userId' => $userId,
            'exists' => $exists,
            'metadata' => $metadata,

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_failure_event_automated');
    }
}

if (!function_exists('datadog\appsec\track_user_login_failure_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_failure_event($userId, $exists, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'exists' => $exists,
            'metadata' => $metadata,
        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_login_failure_event');
    }
}

if (!function_exists('datadog\appsec\track_user_signup_event_automated')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_signup_event_automated($userLogin, $userId, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userLogin' => $userLogin,
            'userId' => $userId,
            'metadata' => $metadata,

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_signup_event_automated');
    }
}

if (!function_exists('datadog\appsec\track_user_signup_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_signup_event($userId, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,
        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_signup_event');
    }
}

if (!function_exists('datadog\appsec\track_authenticated_user_event_automated')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_authenticated_user_event_automated($userId)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_authenticated_user_event_automated');
    }
}

if (!function_exists('datadog\appsec\track_authenticated_user_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_authenticated_user_event($userId, $metadata)
    {
        if (!appsecMockEnabled()) {
            return;
        }
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,
        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_authenticated_user_event');
    }
}

if (!function_exists('datadog\appsec\push_addresses')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     * @param array $params
     */
    function push_addresses($addresses, $rasp = "")
    {
        if (!appsecMockEnabled()) {
            return;
        }
        AppsecStatus::getInstance()->addEvent(['rasp_rule' => $rasp, $addresses], 'push_addresses');
    }
}