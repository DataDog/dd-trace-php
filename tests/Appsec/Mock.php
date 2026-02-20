<?php

namespace datadog\appsec;


if (!class_exists('datadog\appsec\AppsecStatusBase')) {
    /**
     * Shared logic for filtering events by names and addresses.
     *
     * @internal
     */
    abstract class AppsecStatusBase
    {
        /**
         * @param array<int, array{event: string, token: string}> $rows
         * @return array<int, array<string, mixed>>
         */
        protected static function filterEventsByNamesAndAddresses(array $rows, string $token, array $names, array $addresses): array
        {
            $result = [];
            foreach ($rows as $row) {
                if ($row['token'] !== $token) {
                    continue;
                }
                $new = json_decode($row['event'], true);
                if ($new === null) {
                    continue;
                }
                if (empty($names) || in_array($new['eventName'], $names) &&
                    (empty($addresses) || !empty(array_intersect($addresses, array_keys($new[0] ?? []))))) {
                    $result[] = $new;
                }
            }
            return $result;
        }

        abstract public function init(): void;

        abstract public function setDefaults(): void;

        /**
         * @param array<string, mixed> $event
         */
        abstract public function addEvent(array $event, string $eventName): void;

        /**
         * @return array<int, array<string, mixed>>
         */
        abstract public function getEvents(array $names = [], array $addresses = []): array;

        /**
         * @param array<string, mixed> $event
         */
        abstract public function simulateBlockOnEvent($event): void;
    }
}
if (!class_exists('datadog\appsec\AppsecStatusInMemory')) {
    final class AppsecStatusInMemory extends AppsecStatusBase
    {
        /** @var array<int, array{event: string, token: string}> */
        private $events = [];
        /** @var array<int, array{event: string, token: string}> */
        private $blockedEvents = [];

        public function init(): void
        {
            $this->events = [];
            $this->blockedEvents = [];
        }

        public function setDefaults(): void
        {
            $token = ini_get("datadog.trace.agent_test_session_token");
            $this->events = array_values(array_filter($this->events, function ($row) use ($token) {
                return $row['token'] !== $token;
            }));
            $this->blockedEvents = array_values(array_filter($this->blockedEvents, function ($row) use ($token) {
                return $row['token'] !== $token;
            }));
        }

        public function addEvent(array $event, $eventName): void
        {
            $event['eventName'] = $eventName;
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");

            $eventIsBlocked = false;
            foreach ($this->blockedEvents as $row) {
                if ($row['event'] === $jsonEvent && $row['token'] === $token) {
                    $eventIsBlocked = true;
                    break;
                }
            }
            $this->events[] = ['event' => $jsonEvent, 'token' => $token];
            if ($eventIsBlocked) {
                \DDTrace\Testing\trigger_error("Datadog blocked the request and NON RELEVANT TEXT FROM HERE", E_ERROR);
            }
        }

        public function getEvents(array $names = [], array $addresses = []): array
        {
            $token = ini_get("datadog.trace.agent_test_session_token");
            return self::filterEventsByNamesAndAddresses($this->events, $token, $names, $addresses);
        }

        public function simulateBlockOnEvent($event): void
        {
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");
            $this->blockedEvents[] = ['event' => $jsonEvent, 'token' => $token];
        }
    }
}

if (!class_exists('datadog\appsec\AppsecStatusMysql')) {
    final class AppsecStatusMysql extends AppsecStatusBase
    {

        private function initiated(): bool
        {
            $stmt = $this->getDbPdo()->prepare("SELECT * FROM information_schema.tables WHERE table_name = :table_name");
            $stmt->execute(['table_name' => 'appsec_events']);
            return $stmt->rowCount() > 0;
        }

        public function init(): void
        {
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_events (event varchar(1000), token varchar(100))");
            $this->getDbPdo()->exec("CREATE TABLE IF NOT EXISTS appsec_blocked_events (event varchar(1000), token varchar(100))");
        }

        public function setDefaults(): void
        {
            if (!$this->initiated()) {
                return;
            }
            $token = ini_get("datadog.trace.agent_test_session_token");
            $stmt = $this->getDbPdo()->prepare("DELETE FROM appsec_events WHERE token = :token");
            $stmt->execute(['token' => $token]);
            $stmt = $this->getDbPdo()->prepare("DELETE FROM appsec_blocked_events WHERE token = :token");
            $stmt->execute(['token' => $token]);
        }

        public function addEvent(array $event, $eventName): void
        {
            if (!$this->initiated()) {
                return;
            }
            $event['eventName'] = $eventName;
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");

            $stmt = $this->getDbPdo()->prepare("SELECT * from appsec_blocked_events where event=:event and token=:token");
            $stmt->execute(['event' => $jsonEvent, 'token' => $token]);
            $eventIsBlocked = $stmt->rowCount() > 0;

            $stmt = $this->getDbPdo()->prepare("INSERT INTO appsec_events VALUES (:event, :token)");
            $stmt->execute(['event' => $jsonEvent, 'token' => $token]);

            if ($eventIsBlocked) {
                \DDTrace\Testing\trigger_error("Datadog blocked the request and NON RELEVANT TEXT FROM HERE", E_ERROR);
            }
        }

        public function getEvents(array $names = [], array $addresses = []): array
        {
            if (!$this->initiated()) {
                return [];
            }
            $token = ini_get("datadog.trace.agent_test_session_token");
            $stmt = $this->getDbPdo()->prepare("SELECT * FROM appsec_events WHERE token = :token");
            $stmt->execute(['token' => $token]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return self::filterEventsByNamesAndAddresses($events, $token, $names, $addresses);
        }

        public function simulateBlockOnEvent($event): void
        {
            $jsonEvent = json_encode($event);
            $token = ini_get("datadog.trace.agent_test_session_token");
            $stmt = $this->getDbPdo()->prepare("INSERT INTO appsec_blocked_events VALUES (:event, :token)");
            $stmt->execute(['event' => $jsonEvent, 'token' => $token]);
        }
    }
}

if (!class_exists('datadog\appsec\AppsecStatus')) {
    class AppsecStatus
    {
        /** @var AppsecStatusBase|null */
        private static $instance = null;

        /**
         * The first call defines the mode: getInstance(true) = in-memory, getInstance() or getInstance(false) = MySQL.
         * Mode is fixed until clearInstances() is called.
         *
         * @param bool $inMemory When true, use in-memory storage (only applied on first call)
         * @return AppsecStatusBase
         */
        public static function getInstance(bool $inMemory = false)
        {
            if (self::$instance === null) {
                self::$instance = $inMemory ? new AppsecStatusInMemory() : new AppsecStatusMysql();
            }
            return self::$instance;
        }

        public static function clearInstances(): void
        {
            self::$instance = null;
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
