FROM datadog/dd-appsec-php-ci:toolchain as helper-build

COPY --from=datadog/dd-appsec-php-ci:hunter-cache / /root/.hunter/

RUN mkdir /project
ADD CMakeLists.txt /project/
ADD src /project/src/
ADD cmake /project/cmake/
ADD third_party /project/third_party/
ADD tests /project/tests/
ADD .github /project/.github/

RUN apt-get update && apt-get install -y \
	git                                  \
	&& rm -rf /var/lib/apt/lists/*

ADD .git /project/.git/

RUN mkdir -p /helper_build &&                                                              \
	cd /helper_build &&                                                                    \
	cmake                                                                                  \
		-DCMAKE_BUILD_TYPE=RelWithDebInfo                                                  \
		-DDD_APPSEC_BUILD_EXTENSION=OFF                                                    \
		-DCMAKE_INSTALL_PREFIX=/opt                                                        \
		-DDD_APPSEC_INSTALL_RULES_FILE=/project/.github/workflows/release/recommended.json \
		-DCMAKE_TOOLCHAIN_FILE=/build/Toolchain.cmake                                      \
		-DDD_APPSEC_ENABLE_PATCHELF_LIBC=ON                                                \
		/project &&                                                                        \
	VERBOSE=1 make -j ddappsec-helper &&                                                   \
	make -j install &&                                                                     \
	rm -rf /helper_build

FROM scratch
COPY --from=helper-build /opt /opt/
