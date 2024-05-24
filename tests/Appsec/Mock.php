<?php

namespace datadog\appsec;

if (!class_exists('datadog\appsec\AppsecStatus')) {
    class AppsecStatus {

        private static $instance = null;

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
            return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
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
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000))");
        }

        public function destroy()
        {
            $this->getDbPdo()->exec("DROP TABLE appsec_events");
        }


        public function setDefaults()
        {
            if (!$this->initiated()) {
                return;
            }
            $this->getDbPdo()->exec("DELETE FROM appsec_events");
        }

        public function addEvent(array $event, $eventName)
        {
            if (!$this->initiated()) {
                return;
            }

            $event['eventName'] = $eventName;
            $this->getDbPdo()->exec(sprintf("INSERT INTO appsec_events VALUES ('%s')", json_encode($event)));
        }

        public function getEvents()
        {
            $result = [];

            if (!$this->initiated()) {
                return $result;
            }

            $events = $this->getDbPdo()->query("SELECT * FROM appsec_events")->fetchAll();

            foreach ($events as $event) {
                $result[] = json_decode($event['event'], true);
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