<?php

namespace datadog\appsec;

class AppsecStatus {

    private static $instance = null;

    private bool $initiated = false;

    protected function __construct() {
        $this->initiated = false;
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
        $this->initiated = true;
    }

    public function destroy()
    {
        $this->getDbPdo()->exec("DROP TABLE appsec_events");
        $this->initiated = false;
    }

    public function setDefaults()
    {
        if (!$this->initiated) {
         return;
        }
        $this->getDbPdo()->exec("DELETE FROM appsec_events");

    }

    public function addEvent(array $event, $eventName)
    {
        if (!$this->initiated) {
         return;
        }
        $event['eventName'] = $eventName;
        $this->getDbPdo()->exec(sprintf("INSERT INTO appsec_events VALUES ('%s')", json_encode($event)));
    }

    public function getEvents()
    {
        $result = [];

        if (!$this->initiated) {
         return $result;
        }
        
        $events = $this->getDbPdo()->query("SELECT * FROM appsec_events")->fetchAll();

        foreach ($events as $event) {
            $result[] = json_decode($event['event'], true);
        }

        return $result;
    }
}

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

/**
 * This function is exposed by appsec but here we are mocking it for tests
 * @param array $params
 */
function ddappsec_push_address($params) {
    AppsecStatus::getInstance()->addEvent($params, 'ddappsec_push_address');
}
