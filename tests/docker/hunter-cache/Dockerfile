FROM datadog/dd-appsec-php-ci:toolchain as toolchain-with-cache

ADD cmake/HunterGate.cmake /hunter/
ADD tests/docker/hunter-cache/CMakeLists.txt /hunter/

RUN mkdir -p /tmp/hunter && \
	cd /tmp/hunter && \
	cmake /hunter && \
	rm -rf /tmp/hunter

FROM scratch
COPY --from=toolchain-with-cache /root/.hunter /
