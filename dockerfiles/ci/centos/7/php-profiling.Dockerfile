ARG baseImage="datadog/dd-trace-ci:profiling_centos-7"
ARG phpImage
ARG phpVersion

FROM ${phpImage} as php
FROM ${baseImage} as base
ARG phpVersion
COPY --from=php /opt/php /opt/php
ENV PATH="/opt/php/${phpVersion}/bin:$PATH"
