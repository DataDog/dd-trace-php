default:
  retry:
    max: 2
    when:
      - unknown_failure
      - data_integrity_failure
      - runner_system_failure
      - scheduler_failure
      - api_failure
