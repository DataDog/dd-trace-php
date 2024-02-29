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
            $this->getDbPdo()->exec("DELETE FROM appsec_events");

        }

        public function addEvent(array $event, $eventName)
        {
            $event['eventName'] = $eventName;
            $this->getDbPdo()->exec(sprintf("INSERT INTO appsec_events VALUES ('%s')", json_encode($event)));
        }

        public function getEvents()
        {
            $result = [];

            $events = $this->getDbPdo()->query("SELECT * FROM appsec_events")->fetchAll();

            foreach ($events as $event) {
                $result[] = json_decode($event['event'], true);
            }

            return $result;
        }
    }
}

if (!function_exists('datadog\appsec\track_user_login_success_event')) {
    /**
     * This function is exposed by appsec but here we are mocking it for tests
     */
    function track_user_login_success_event($userId, $metadata, $automated) {
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
        $event = [
            'userId' => $userId,
            'metadata' => $metadata,
            'automated' => $automated

        ];
        AppsecStatus::getInstance()->addEvent($event, 'track_user_signup_event');
    }
}
