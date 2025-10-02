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
                $pdo = new \PDO('mysql:host=mysql-integration', 'test', 'test');
                $pdo->exec("CREATE DATABASE IF NOT EXISTS test");
                $this->connection = new \PDO('mysql:host=mysql-integration;dbname=test', 'test', 'test');
                $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }
            return $this->connection;
        }

        /**
         * Not all test are interested on events but frameworks are instrumented so this check is to avoid errors
         */
        private function initiated()
        {
            $stmt = $this->getDbPdo()->prepare("SELECT * FROM information_schema.tables WHERE table_name = :table_name");
            $stmt->execute(['table_name' => 'appsec_events']);
            return $stmt->rowCount() > 0;
        }

        public function init()
        {
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000), token varchar(100))");
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_blocked_events (event varchar(1000), token varchar(100))");
        }

        public function setDefaults()
        {
            if (!$this->initiated()) {
                return;
            }
            $stmt = $this->getDbPdo()->prepare("DELETE FROM appsec_events WHERE token = :token");
            $stmt->execute(['token' => ini_get("datadog.trace.agent_test_session_token")]);
            $stmt = $this->getDbPdo()->prepare("DELETE FROM appsec_blocked_events WHERE token = :token");
            $stmt->execute(['token' => ini_get("datadog.trace.agent_test_session_token")]);
        }

        public function addEvent(array $event, $eventName)
        {
            if (!$this->initiated()) {
                return;
            }
            $event['eventName'] = $eventName;
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");

            $stmt = $this->getDbPdo()->prepare("SELECT * from appsec_blocked_events where event=:event and token=:token");
            $stmt->execute([
                'event' => $jsonEvent,
                'token' => $token
            ]);
            $eventIsBlocked = $stmt->rowCount() > 0;

            $stmt = $this->getDbPdo()->prepare("INSERT INTO appsec_events VALUES (:event, :token)");
            $stmt->execute([
                'event' => $jsonEvent,
                'token' => $token
            ]);

            if ($eventIsBlocked) {
                \DDTrace\Testing\trigger_error("Datadog blocked the request and NON RELEVANT TEXT FROM HERE", E_ERROR);
            }
        }

        public function getEvents(array $names = [], array $addresses = [])
        {
            $result = [];

            if (!$this->initiated()) {
                return $result;
            }

            $stmt = $this->getDbPdo()->prepare("SELECT * FROM appsec_events WHERE token = :token");
            $stmt->execute(['token' => ini_get("datadog.trace.agent_test_session_token")]);
            $events = $stmt->fetchAll();

            foreach ($events as $event) {
                $new = json_decode($event['event'], true);
                if ($new === null) {
                    continue;
                }
                if (empty($names) || in_array($new['eventName'], $names) &&
                    (empty($addresses) || !empty(array_intersect($addresses, array_keys($new[0]))))) {
                    $result[] = $new;
                }
            }

            return $result;
        }

        public function simulateBlockOnEvent($event) {
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");
            $stmt = $this->getDbPdo()->prepare("INSERT INTO appsec_blocked_events VALUES (:event, :token)");
            $stmt->execute([
                'event' => $jsonEvent,
                'token' => $token
            ]);
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
     * @param ?array|string $params keys: rasp_rule, subctx_id, subctx_last_call
     */
    function push_addresses($addresses, $params = '')
    {
        if (!appsecMockEnabled()) {
            return;
        }
        if (is_string($params)) {
            $rasp_rule = $params;
        } elseif (is_array($params)) {
            $rasp_rule = $params['rasp_rule'] ?? '';
        } else {
            $rasp_rule = '';
        }
        AppsecStatus::getInstance()->addEvent(['rasp_rule' => $rasp_rule, $addresses], 'push_addresses');
    }
}
