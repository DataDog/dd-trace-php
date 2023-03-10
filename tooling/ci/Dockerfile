FROM datadog/dd-appsec-php-ci:php-7.0-release AS php_agent_debug
RUN apt-get update && apt-get install -y jq
COPY ./tooling/ci/ddtrace-info.sh ./tooling/ci/binaries* /binaries/
RUN /binaries/ddtrace-info.sh
RUN touch -c -a -m -d @0 /binaries/*

FROM scratch as collect
COPY --from=php_agent_debug /binaries/datadog-php-tracer.x86_64.tar.gz /datadog-php-tracer.x86_64.tar.gz
COPY --from=php_agent_debug /binaries/dd-appsec-php-amd64.tar.gz /dd-appsec-php-amd64.tar.gz
COPY --from=php_agent_debug /binaries/LIBRARY_VERSION /LIBRARY_VERSION
COPY --from=php_agent_debug /binaries/LIBDDWAF_VERSION /LIBDDWAF_VERSION
COPY --from=php_agent_debug /binaries/APPSEC_EVENT_RULES_VERSION /APPSEC_EVENT_RULES_VERSION
COPY --from=php_agent_debug /binaries/PHP_APPSEC_VERSION /PHP_APPSEC_VERSION

FROM scratch
COPY --from=collect /* /
