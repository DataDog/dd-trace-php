package com.datadog.appsec.php.mock_agent.rem_cfg

class IntegrityCheckException extends RuntimeException {
    IntegrityCheckException(String s) {
        super(s)
    }

    IntegrityCheckException(String s, Exception e) {
        super(s, e)
    }
}
