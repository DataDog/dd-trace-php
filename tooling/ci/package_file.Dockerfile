FROM bash AS ci_sync
RUN apk update; apk add curl jq
COPY ./ci_sync.sh /usr/local/bin/ci_sync.sh
WORKDIR /build
ARG CIRCLECI_TOKEN="arg"
ENV CIRCLECI_TOKEN="${CIRCLECI_TOKEN}"
RUN bash /usr/local/bin/ci_sync.sh
RUN touch -c -a -m -t 201512180130.09 *

FROM scratch as collect
COPY --from=ci_sync /build/*.tar.gz /

FROM scratch
COPY --from=collect / /