ARG PHP_VERSION
ARG VARIANT
FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT as ext-build

COPY --from=datadog/dd-appsec-php-ci:hunter-cache / /root/.hunter/

RUN mkdir /project
ADD CMakeLists.txt /project/
ADD src /project/src/
ADD cmake /project/cmake/
ADD third_party /project/third_party/
ADD tests /project/tests/

RUN mkdir -p /ext_build &&                            \
	cd /ext_build &&                                  \
	cmake                                             \
		-DCMAKE_BUILD_TYPE=RelWithDebInfo             \
		-DDD_APPSEC_BUILD_HELPER=OFF                  \
		-DCMAKE_INSTALL_PREFIX=/opt                   \
		-DDD_APPSEC_ENABLE_PATCHELF_LIBC=ON           \
		-DCMAKE_TOOLCHAIN_FILE=/build/Toolchain.cmake \
		/project &&                                   \
	make -j extension &&                              \
	make -j install &&                                \
	rm -rf /ext_build

FROM scratch
COPY --from=ext-build /opt /opt/
