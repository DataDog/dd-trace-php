ARG profilingImage="datadog/dd-trace-ci:profiling_centos-6"
ARG phpImage

# Can't do COPY --from=${profilingImage} for some reason, so use FROM to give
# it a name to work around it.
FROM ${profilingImage} as profiling
FROM ${phpImage} as php
COPY --from=profiling /usr/local /usr/local
COPY --from=profiling /rust /rust
ENV CARGO_HOME=/rust/cargo
ENV RUSTUP_HOME=/rust/rustup
