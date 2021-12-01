ARG PHP_VERSION
ARG VARIANT
FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT as build

COPY --from=datadog/dd-appsec-php-ci:hunter-cache / /root/.hunter/

RUN mkdir /project
ADD CMakeLists.txt /project/
ADD run-tests.php /project/
ADD src /project/src/
ADD .github /project/.github/
ADD .git /project/.git/
ADD cmake /project/cmake/
ADD third_party /project/third_party/
ADD tests /project/tests/

RUN /project/.github/workflows/release/build_package $(find /root/php -mindepth 1 -maxdepth 1 -type d)

FROM scratch
COPY --from=build /out /out/
