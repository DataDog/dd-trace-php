<?php

namespace datadog\appsec;

class AppsecStatus {

    private static $instance = null;
    const CONFIGURATION_ID = 123;
    
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
        $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_configuration (id int, enabled int)");
        $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000))");
    }

    public function destroy()
    {
        $this->getDbPdo()->exec("DROP TABLE appsec_configuration");
        $this->getDbPdo()->exec("DROP TABLE appsec_events");
    }   


    public function setDefaults()
    {
        $this->getDbPdo()->exec("DELETE FROM appsec_configuration");
        $this->getDbPdo()->exec("DELETE FROM appsec_events");
        $this->getDbPdo()->exec(sprintf("INSERT INTO appsec_configuration VALUES (%s, 0)", AppsecStatus::CONFIGURATION_ID));

    }

    public function isEnabled()
    {
        $result = $this->getDbPdo()->query("SELECT enabled FROM appsec_configuration WHERE id=" . AppsecStatus::CONFIGURATION_ID)->fetch();
        return $result['enabled'] ==  1;
    }

    public function setEnabled()
    {
        $this->getDbPdo()->exec(sprintf("UPDATE appsec_configuration SET enabled=1 WHERE id=" . AppsecStatus::CONFIGURATION_ID));
    }

    public function setDisabled()
    {
        $this->getDbPdo()->exec(sprintf("UPDATE appsec_configuration SET enabled=0 WHERE id=" . AppsecStatus::CONFIGURATION_ID));
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

/**
 * This function is exposed by appsec but here we are mocking it for tests
 */
function is_enabled() {
    return AppsecStatus::getInstance()->isEnabled();
}

/**
 * This function is exposed by appsec but here we are mocking it for tests
 */
function track_user_login_success_event($userId, $metadata) {
    $event = [
        'userId' => $userId,
        'metadata' => $metadata

    ];
    AppsecStatus::getInstance()->addEvent($event, 'track_user_login_success_event');
}

/**
 * This function is exposed by appsec but here we are mocking it for tests
 */
function track_user_login_failure_event($userId, $exists, $metadata) {
    $event = [
        'userId' => $userId,
        'exists' => $exists,
        'metadata' => $metadata

    ];
    AppsecStatus::getInstance()->addEvent($event, 'track_user_login_failure_event');
}

