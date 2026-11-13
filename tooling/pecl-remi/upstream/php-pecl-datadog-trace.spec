# remirepo spec file for php-pecl-datadog-trace
#
# SPDX-FileCopyrightText:  Copyright 2019-2026 Remi Collet
# SPDX-License-Identifier: CECILL-2.1
# http://www.cecill.info/licences/Licence_CeCILL_V2-en.txt
#
# Please, preserve the changelog entries
#
%if 0%{?scl:1}
%scl_package       php-pecl-datadog-trace
%else
%global _root_libdir %{_libdir}
%global _root_bindir %{_bindir}
%endif

# NOTICE: mock may requires --enable-network --cleanup-after

# --enable-ddtrace-libddwaf-source for offline build
%bcond_with   libddwaf_source

# needed to be able to run ddtrace.so (outside of php)
# not required but for sanity
%define _lto_cflags %{nil}

# to much issues with test suite and parallel builds
%bcond_with        tests

# TODO: build for EL-8 and EL-9
# see https://rpms.remirepo.net/rpmphp/zoom.php?rpm=rust
# if needed, build using --enablerepo=stream
# and delete root_cache after the build
#     EL  8.10     1.79.0
#     EL  8 priv   1.92.0
#     EL  9.8      1.92.0
#     EL  9 stream 1.95.0
#     EL 10.2      1.92.0
#     EL 10 stream 1.95.0

# pecl name
%global proj_name        datadog_trace
# no underscore for package name
%global safe_name        datadog-trace
# extension name
%global pecl_name        ddtrace

%global upstream_version 1.27.1
#global upstream_prever  beta1
%global with_zts         0%{!?_without_zts:%{?__ztsphp:1}}
%global ini_name         40-%{pecl_name}.ini
%global sources          %{proj_name}-%{upstream_version}%{?upstream_prever}
%global _configure       ../%{sources}/configure

Summary:        APM and distributed tracing for PHP
Name:           %{?scl_prefix}php-pecl-datadog-trace
Version:        %{upstream_version}%{?upstream_prever:~%{upstream_prever}}
Release:        1%{?dist}
# extension is dual-licensed under Apache 2.0 or BSD3.
# bundled rust ext are MIT AND Apache-2.0
License:        (Apache-2.0 OR BSD-3-Clause) AND MIT AND Apache-2.0
URL:            https://pecl.php.net/package/%{proj_name}
Source0:        https://pecl.php.net/get/%{sources}.tgz
# awfull hack, use a bundled rust registry
Source1:        makedeps.sh
Source2:        %{proj_name}-deps-%{upstream_version}%{?upstream_prever}.tgz

# ddtrace only supports 64-bit platforms
ExcludeArch:    %{ix86} %{arm}

BuildRequires:  make
%if %{with libddwaf_source}
BuildRequires:  cmake
BuildRequires:  rust-src
%endif
BuildRequires:  cargo >= 1.91.1
BuildRequires:  rust >= 1.91.1
BuildRequires:  clang-devel
BuildRequires:  %{?dtsprefix}gcc
BuildRequires: (%{?scl_prefix}php-devel >= 7.0 with %{?scl_prefix}php-devel < 8.7)
BuildRequires:  %{?scl_prefix}php-pear
BuildRequires:  curl-devel
BuildRequires:  %{?scl_prefix}php-json
BuildRequires:  %{?scl_prefix}php-curl
# For tests
BuildRequires:  %{?scl_prefix}php-posix
#BuildRequires:  gdb
#BuildRequires:  %%{?scl_prefix}php-debuginfo
#BuildRequires:  %%{?scl_prefix}php-debugsource
%if 0%{?fedora} >= 40
# TODO need investigation
BuildRequires:  gmp
BuildRequires:  libssh2
%endif

Requires:       %{?scl_prefix}php(zend-abi) = %{php_zend_api}
Requires:       %{?scl_prefix}php(api) = %{php_core_api}
Requires:       %{?scl_prefix}php-json%{?_isa}

# Extension
Provides:       %{?scl_prefix}php-%{pecl_name}               = %{version}
Provides:       %{?scl_prefix}php-%{pecl_name}%{?_isa}       = %{version}
# PECL
Provides:       %{?scl_prefix}php-pecl(%{proj_name})         = %{version}
Provides:       %{?scl_prefix}php-pecl(%{proj_name})%{?_isa} = %{version}


%description
The Datadog PHP Tracer brings APM and distributed tracing to PHP.

Documentation: https://docs.datadoghq.com/tracing/languages/php/

Package built for PHP %(%{__php} -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')%{?scl: as Software Collection (%{scl} by %{?scl_vendor}%{!?scl_vendor:rh})}.


%prep
%setup -q -c -a2

# Don't install tests
sed -e 's/role="test"/role="src"/' \
    -e '/LICENSE/s/role="doc"/role="src"/' \
    -i package.xml

cd %{sources}
: PEAR installer stuff
sed -e 's:@php_dir@:%{pear_phpdir}:' \
    -e 's:_BUILD_FROM_PECL_:1:' \
    -i tracer/configuration.h \
       ext/configuration.h

: Sanity check, really often broken
extver=$(cat VERSION)
if test "x${extver}" != "x%{upstream_version}%{?upstream_prever}"; then
   : Error: Upstream extension version is ${extver}, expecting %{upstream_version}%{?upstream_prever}
   #exit 1
fi
cd ..

: Create cargo configuration to use vendor directory
mkdir .cargo
cat << EOF | tee .cargo/config.toml
[build]
jobs = %(echo %{?_smp_mflags} | sed 's/\-j//')
rustc = "%{_root_bindir}/rustc"

[env]
CFLAGS = "%{build_cflags}"
CXXFLAGS = "%{build_cxxflags}"
LDFLAGS = "%{build_ldflags}"

[term]
verbose = true

[source.crates-io]
replace-with = "vendored-sources"

[source.vendored-sources]
directory = "$PWD/mycargo"
EOF

: Required rust version
grep -h rust-version mycargo/*/Cargo.toml %{sources}/*/Cargo.toml %{sources}/Cargo.toml | sort -u | tail -n 8

mkdir NTS
%if %{with_zts}
mkdir ZTS
%endif

# Create configuration file
cat << 'EOF' | tee %{ini_name}
; Enable %{pecl_name} extension module
extension=%{pecl_name}.so

; Configuration
;ddtrace.disable = Off
;ddtrace.cgroup_file = '/proc/self/cgroup'
EOF


%build
%{?dtsenable}
%if 0%{?scl:1}
source /opt/%{scl_vendor}/%{scl}/enable
%endif

cd %{sources}
%{__phpize}
[ -f Makefile.global ] && GLOBAL=Makefile.global || GLOBAL=build/Makefile.global
sed -e 's/INSTALL_ROOT/DESTDIR/' -i $GLOBAL

cd ../NTS
%configure \
    --enable-ddtrace \
%if %{with libddwaf_source}
    --enable-ddtrace-libddwaf-source \
%endif
    --with-php-config=%{__phpconfig}

# make_build breaks verbose mode for rust, so keep old way
make %{?_smp_mflags}

%if %{with_zts}
cd ../ZTS
%configure \
    --enable-ddtrace \
%if %{with libddwaf_source}
    --enable-ddtrace-libddwaf-source \
%endif
    --with-php-config=%{__ztsphpconfig}

make %{?_smp_mflags}
%endif


%install
%{?dtsenable}

%make_install -C NTS

# install config file
install -D -m 644 %{ini_name} %{buildroot}%{php_inidir}/%{ini_name}

# Install XML package description
install -D -m 644 package.xml %{buildroot}%{pecl_xmldir}/%{name}.xml

%if %{with_zts}
%make_install -C ZTS

install -D -m 644 %{ini_name} %{buildroot}%{php_ztsinidir}/%{ini_name}
%endif

# PEAR stuff
mkdir -p                 %{buildroot}%{pear_phpdir}/datadog_trace/
cp -pr %{sources}/src    %{buildroot}%{pear_phpdir}/datadog_trace/src

# Documentation
for i in $(grep 'role="doc"' package.xml | sed -e 's/^.*name="//;s/".*$//')
do install -Dpm 644 %{sources}/$i %{buildroot}%{pecl_docdir}/%{pecl_name}/$i
done


%check
cd %{sources}

# XFAIL
find tests/ -type f -exec grep -q -- '--XFAIL--' {} \; -delete -print

# DEP for ext dependencies, MOD for ext used in tests
DEP=""
MOD=""
OPT="-q --show-diff --keep-skip"
%if "%{php_version}" > "8.0"
OPT="$OPT %{?_smp_mflags}"
%endif
# Extension dependency
[ -f %{php_extdir}/json.so ]  && DEP="$DEP -d extension=json.so"
# Used by test suite
[ -f %{php_extdir}/curl.so ]  && MOD="$MOD -d extension=curl.so"
[ -f %{php_extdir}/posix.so ] && MOD="$MOD -d extension=posix.so"

export DD_INSTRUMENTATION_TELEMETRY_ENABLED=0

: Minimal load test for NTS extension
%{__php} --no-php-ini $DEP \
    --define extension=%{buildroot}%{php_extdir}/%{pecl_name}.so \
    --modules | grep -i '^%{pecl_name}$'

%if %{with_zts}
: Minimal load test for ZTS extension
%{__ztsphp} --no-php-ini $DEP \
    --define extension=%{buildroot}%{php_ztsextdir}/%{pecl_name}.so \
    --modules | grep -i '^%{pecl_name}$'
%endif

%if %{with tests}
export DD_TRACE_CLI_ENABLED=1
export DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
#export DD_TRACE_LOG_LEVEL=trace
#export DD_TRACE_LOG_FILE=$PWD/logfile.log
#export RUST_BACKTRACE=1

# known to fail
rm tests/ext/telemetry/config.phpt
rm tests/ext/background-sender/sidecar_thread_mode_permissions.phpt
rm tests/ext/pcntl/pcntl_fork_thread_mode_orphan.phpt
rm tests/ext/ffe/system_test_data_evaluate.phpt
rm tests/ext/dogstatsd/health_metrics_over_uds.phpt
rm tests/ext/dogstatsd/metrics_over_uds.phpt

: Upstream test suite for NTS extension
TEST_PHP_EXECUTABLE=%{_bindir}/php \
TEST_PHP_ARGS="-n $DEP $MOD -d extension=$PWD/../NTS/modules/%{pecl_name}.so" \
REPORT_EXIT_STATUS=1 \
%{_bindir}/php -n run-tests.php $OPT
%endif


%files
%doc %{pecl_docdir}/%{pecl_name}
%license %{sources}/LICENSE*
%dir %{pear_phpdir}/datadog_trace
     %{pear_phpdir}/datadog_trace/src

%{pecl_xmldir}/%{name}.xml
%config(noreplace) %{php_inidir}/%{ini_name}
%{php_extdir}/%{pecl_name}.so

%if %{with_zts}
%config(noreplace) %{php_ztsinidir}/%{ini_name}
%{php_ztsextdir}/%{pecl_name}.so
%endif


%changelog
* Fri Nov 13 2026 Remi Collet <remi@remirepo.net> - 1.27.1-1
- update to 1.27.1 (still broken)

* Thu Nov  5 2026 Remi Collet <remi@remirepo.net> - 1.27.0-1
- update to 1.27.0
- open https://github.com/DataDog/dd-trace-php/issues/4432 build failure

* Tue Oct 27 2026 Remi Collet <remi@remirepo.net> - 1.26.1-1
- update to 1.26.1

* Fri Oct  9 2026 Remi Collet <remi@remirepo.net> - 1.26.0-1
- update to 1.26.0

* Wed Sep  9 2026 Remi Collet <remi@remirepo.net> - 1.25.1-1
- update to 1.25.1
- open https://github.com/DataDog/dd-trace-php/issues/4175 test failures
- open https://github.com/DataDog/dd-trace-php/issues/4174 offline build

* Sat Sep  5 2026 Remi Collet <remi@remirepo.net> - 1.25.0-1
- update to 1.25.0
- open https://github.com/DataDog/dd-trace-php/issues/4162 build fails

* Sat Aug 22 2026 Remi Collet <remi@remirepo.net> - 1.24.0-1
- update to 1.24.0

* Thu Jul 30 2026 Remi Collet <remi@remirepo.net> - 1.23.3-1
- update to 1.23.3

* Wed Jul 29 2026 Remi Collet <remi@remirepo.net> - 1.23.2-1
- update to 1.23.2

* Tue Jun  9 2026 Remi Collet <remi@remirepo.net> - 1.21.0-1
- update to 1.21.0

* Thu May 21 2026 Remi Collet <remi@remirepo.net> - 1.20.0-1
- update to 1.20.0

* Thu May  7 2026 Remi Collet <remi@remirepo.net> - 1.19.2-1
- update to 1.19.2

* Thu Apr 30 2026 Remi Collet <remi@remirepo.net> - 1.19.1-1
- update to 1.19.1

* Wed Apr 29 2026 Remi Collet <remi@remirepo.net> - 1.19.0-1
- update to 1.19.0

* Wed Apr 15 2026 Remi Collet <remi@remirepo.net> - 1.18.0-1
- update to 1.18.0

* Sun Apr  5 2026 Remi Collet <remi@remirepo.net> - 1.17.1-1
- update to 1.17.1
- open https://github.com/DataDog/dd-trace-php/issues/3775 test failure

* Thu Mar  5 2026 Remi Collet <remi@remirepo.net> - 1.16.0-1
- update to 1.16.0

* Sun Jan  4 2026 Remi Collet <remi@remirepo.net> - 1.15.2-1
- update to 1.15.2

* Fri Dec 19 2025 Remi Collet <remi@remirepo.net> - 1.15.1-1
- update to 1.15.1

* Tue Dec 16 2025 Remi Collet <remi@remirepo.net> - 1.15.0-1
- update to 1.15.0

* Thu Nov 27 2025 Remi Collet <remi@remirepo.net> - 1.14.1-1
- update to 1.14.1

* Fri Nov 14 2025 Remi Collet <remi@remirepo.net> - 1.14.0-1
- update to 1.14.0

* Thu Oct 30 2025 Remi Collet <remi@remirepo.net> - 1.13.1-1
- update to 1.13.1

* Wed Oct  1 2025 Remi Collet <remi@remirepo.net> - 1.13.0-1
- update to 1.13.0

* Mon Sep 15 2025 Remi Collet <remi@remirepo.net> - 1.12.1-1
- update to 1.12.1

* Thu Aug 28 2025 Remi Collet <remi@remirepo.net> - 1.12.0-1
- update to 1.12.0

* Fri Aug  1 2025 Remi Collet <remi@remirepo.net> - 1.11.0-1
- update to 1.11.0

* Thu May 29 2025 Remi Collet <remi@remirepo.net> - 1.10.0-1
- update to 1.10.0

* Fri May  9 2025 Remi Collet <remi@remirepo.net> - 1.9.0-1
- update to 1.9.0

* Sat Apr 12 2025 Remi Collet <remi@remirepo.net> - 1.8.3-1
- update to 1.8.3

* Sun Apr  6 2025 Remi Collet <remi@remirepo.net> - 1.8.2-1
- update to 1.8.2

* Thu Apr  3 2025 Remi Collet <remi@remirepo.net> - 1.8.0-1
- update to 1.8.0

* Mon Mar 17 2025 Remi Collet <remi@remirepo.net> - 1.7.3-1
- update to 1.7.3

* Sun Mar  9 2025 Remi Collet <remi@remirepo.net> - 1.7.2-1
- update to 1.7.2

* Thu Feb 27 2025 Remi Collet <remi@remirepo.net> - 1.7.1-1
- update to 1.7.1

* Sat Feb 22 2025 Remi Collet <remi@remirepo.net> - 1.7.0-1
- update to 1.7.0

* Sun Feb 16 2025 Remi Collet <remi@remirepo.net> - 1.6.4-1
- update to 1.6.4

* Fri Jan 17 2025 Remi Collet <remi@remirepo.net> - 1.6.3-1
- update to 1.6.3

* Mon Jan 13 2025 Remi Collet <remi@remirepo.net> - 1.6.2-1
- update to 1.6.2

* Thu Jan  9 2025 Remi Collet <remi@remirepo.net> - 1.6.1-1
- update to 1.6.1

* Thu Jan  9 2025 Remi Collet <remi@remirepo.net> - 1.6.0-1
- update to 1.6.0
- re-license spec file to CECILL-2.1
- raise dependency on rust 1.78

* Wed Nov 20 2024 Remi Collet <remi@remirepo.net> - 1.5.1-1
- update to 1.5.1

* Tue Nov 19 2024 Remi Collet <remi@remirepo.net> - 1.5.0-1
- update to 1.5.0

* Mon Oct 21 2024 Remi Collet <remi@remirepo.net> - 1.4.2-1
- update to 1.4.2

* Mon Oct 14 2024 Remi Collet <remi@remirepo.net> - 1.4.1-1
- update to 1.4.1

* Wed Oct  9 2024 Remi Collet <remi@remirepo.net> - 1.4.0-1
- update to 1.4.0
- raise dependency on rust 1.76
- open https://github.com/DataDog/dd-trace-php/issues/2882
  broken build with rust 1.75 (EL-8 and EL-9)

* Fri Sep 27 2024 Remi Collet <remi@remirepo.net> - 1.3.2-1
- update to 1.3.2

* Fri Sep  6 2024 Remi Collet <remi@remirepo.net> - 1.3.1-1
- update to 1.3.1

* Fri Sep  6 2024 Remi Collet <remi@remirepo.net> - 1.4.0-0
- test build for changes in upcoming 1.4.0

* Fri Aug 30 2024 Remi Collet <remi@remirepo.net> - 1.3.0-1
- update to 1.3.0
- open https://github.com/DataDog/dd-trace-php/issues/2825 offline build

* Wed Jul 24 2024 Remi Collet <remi@remirepo.net> - 1.2.0-1
- update to 1.2.0

* Tue Jun 11 2024 Remi Collet <remi@remirepo.net> - 1.1.0-1
- update to 1.1.0

* Wed Apr 17 2024 Remi Collet <remi@remirepo.net> - 1.0.0~beta1-1
- update to 1.0.0beta1

* Fri Apr  5 2024 Remi Collet <remi@remirepo.net> - 0.99.1-1
- update to 0.99.1

* Wed Apr  3 2024 Remi Collet <remi@remirepo.net> - 0.99.0-1
- update to 0.99.0

* Tue Mar  5 2024 Remi Collet <remi@remirepo.net> - 0.98.1-1
- update to 0.98.1
- install the src directory added in the archive

* Tue Feb 20 2024 Remi Collet <remi@remirepo.net> - 0.98.0-1
- update to 0.98.0

* Thu Jan 18 2024 Remi Collet <remi@remirepo.net> - 0.97.0-1
- update to 0.97.0

* Wed Dec 20 2023 Remi Collet <remi@remirepo.net> - 0.96.0-1
- update to 0.96.0

* Thu Dec  7 2023 Remi Collet <remi@remirepo.net> - 0.95.0-1
- update to 0.95.0

* Wed Nov 29 2023 Remi Collet <remi@remirepo.net> - 0.94.1-1
- update to 0.94.1

* Thu Nov 23 2023 Remi Collet <remi@remirepo.net> - 0.94.0-2
- test build for https://github.com/DataDog/dd-trace-php/pull/2383

* Tue Nov 21 2023 Remi Collet <remi@remirepo.net> - 0.94.0-1
- update to 0.94.0
- open https://github.com/DataDog/dd-trace-php/issues/2376 LTO build failure

* Mon Nov 13 2023 Remi Collet <remi@remirepo.net> - 0.93.2-1
- update to 0.93.2

* Wed Nov  1 2023 Remi Collet <remi@remirepo.net> - 0.93.1-1
- update to 0.93.1

* Mon Oct 30 2023 Remi Collet <remi@remirepo.net> - 0.93.0-1
- update to 0.93.0

* Tue Oct 10 2023 Remi Collet <remi@remirepo.net> - 0.92.2-1
- update to 0.92.2

* Mon Oct  2 2023 Remi Collet <remi@remirepo.net> - 0.92.1-1
- update to 0.92.1
- disable test suite which raises too much issues

* Wed Sep 27 2023 Remi Collet <remi@remirepo.net> - 0.92.0-1
- update to 0.92.0
- open https://github.com/DataDog/dd-trace-php/issues/2285
  [Bug]: Failed connecting to the sidecar: Connection refused (os error 111)
- open https://github.com/DataDog/dd-trace-php/issues/2286
  [Bug]: Error in `datadog-ipc-helper': double free or corruption
- open https://github.com/DataDog/dd-trace-php/issues/2287
  [Bug]: test suite hangs

* Fri Sep 15 2023 Remi Collet <remi@remirepo.net> - 0.91.2-1
- update to 0.91.2

* Sat Sep  9 2023 Remi Collet <remi@remirepo.net> - 0.91.1-1
- update to 0.91.1

* Thu Sep  7 2023 Remi Collet <remi@remirepo.net> - 0.91.0-1
- update to 0.91.0
- raise build dependency on rust 1.70

* Mon Jul 17 2023 Remi Collet <remi@remirepo.net> - 0.90.0-1
- update to 0.90.0
- build out of sources tree

* Tue Jul  4 2023 Remi Collet <remi@remirepo.net> - 0.89.0-1
- update to 0.89.0

* Mon Jun 19 2023 Remi Collet <remi@remirepo.net> - 0.88.1-1
- update to 0.88.1
- open https://github.com/DataDog/dd-trace-php/issues/2115
  test failures

* Thu Jun 15 2023 Remi Collet <remi@remirepo.net> - 0.88.0-1
- update to 0.88.0
- use vendored rust dependencies
- open https://github.com/DataDog/dd-trace-php/issues/2103
  Cannot build OFFLINE

* Fri May 19 2023 Remi Collet <remi@remirepo.net> - 0.87.2-1
- update to 0.87.2

* Tue May 16 2023 Remi Collet <remi@remirepo.net> - 0.87.1-1
- update to 0.87.1

* Sat May 13 2023 Remi Collet <remi@remirepo.net> - 0.87.0-1
- update to 0.87.0

* Thu Apr  6 2023 Remi Collet <remi@remirepo.net> - 0.86.3-1
- update to 0.86.3

* Wed Apr  5 2023 Remi Collet <remi@remirepo.net> - 0.86.2-1
- update to 0.86.2

* Fri Mar 31 2023 Remi Collet <remi@remirepo.net> - 0.86.1-1
- update to 0.86.1

* Thu Mar 23 2023 Remi Collet <remi@remirepo.net> - 0.86.0-1
- update to 0.86.0

* Fri Feb  3 2023 Remi Collet <remi@remirepo.net> - 0.84.0-1
- update to 0.84.0

* Thu Jan 19 2023 Remi Collet <remi@remirepo.net> - 0.83.1-1
- update to 0.83.1

* Sat Jan 14 2023 Remi Collet <remi@remirepo.net> - 0.83.0-1
- update to 0.83.0

* Wed Dec  7 2022 Remi Collet <remi@remirepo.net> - 0.82.0-1
- update to 0.82.0

* Thu Nov  3 2022 Remi Collet <remi@remirepo.net> - 0.81.1-1
- update to 0.81.1

* Wed Nov  2 2022 Remi Collet <remi@remirepo.net> - 0.81.0-1
- update to 0.81.0

* Thu Sep 29 2022 Remi Collet <remi@remirepo.net> - 0.80.0-1
- update to 0.80.0

* Fri Sep  9 2022 Remi Collet <remi@remirepo.net> - 0.79.0-1
- update to 0.79.0

* Mon Aug 29 2022 Remi Collet <remi@remirepo.net> - 0.78.0-1
- update to 0.78.0

* Wed Aug  3 2022 Remi Collet <remi@remirepo.net> - 0.77.0-1
- update to 0.77.0

* Fri Jul 29 2022 Remi Collet <remi@remirepo.net> - 0.76.2-1
- update to 0.76.2

* Mon Jul 18 2022 Remi Collet <remi@remirepo.net> - 0.76.1-1
- update to 0.76.1

* Mon Jul 11 2022 Remi Collet <remi@remirepo.net> - 0.76.0-1
- update to 0.76.0
- drop PHP 5 support

* Mon Jun 13 2022 Remi Collet <remi@remirepo.net> - 0.75.0-1
- update to 0.75.0

* Thu May 12 2022 Remi Collet <remi@remirepo.net> - 0.74.0-1
- update to 0.74.0

* Thu Apr 28 2022 Remi Collet <remi@remirepo.net> - 0.73.0-1
- update to 0.73.0

* Thu Apr  7 2022 Remi Collet <remi@remirepo.net> - 0.72.0-1
- update to 0.72.0

* Fri Apr  1 2022 Remi Collet <remi@remirepo.net> - 0.71.1-1
- update to 0.71.1

* Tue Mar 15 2022 Remi Collet <remi@remirepo.net> - 0.71.0-1
- update to 0.71.0

* Thu Mar  3 2022 Remi Collet <remi@remirepo.net> - 0.70.1-1
- update to 0.70.1

* Mon Feb  7 2022 Remi Collet <remi@remirepo.net> - 0.70.0-1
- update to 0.70.0

* Tue Jan 25 2022 Remi Collet <remi@remirepo.net> - 0.69.0-1
- update to 0.69.0

* Mon Jan 10 2022 Remi Collet <remi@remirepo.net> - 0.68.2-1
- update to 0.68.2

* Fri Dec 31 2021 Remi Collet <remi@remirepo.net> - 0.68.1-1
- update to 0.68.1

* Thu Dec 23 2021 Remi Collet <remi@remirepo.net> - 0.68.0-1
- update to 0.68.0

* Tue Nov  2 2021 Remi Collet <remi@remirepo.net> - 0.67.0-1
- update to 0.67.0

* Tue Oct 12 2021 Remi Collet <remi@remirepo.net> - 0.66.0-1
- update to 0.66.0

* Wed Sep 22 2021 Remi Collet <remi@remirepo.net> - 0.65.1-1
- update to 0.65.1

* Tue Sep 21 2021 Remi Collet <remi@remirepo.net> - 0.65.0-1
- update to 0.65.0

* Wed Sep  8 2021 Remi Collet <remi@remirepo.net> - 0.64.1-1
- update to 0.64.1

* Fri Sep  3 2021 Remi Collet <remi@remirepo.net> - 0.64.0-1
- update to 0.64.0

* Mon Aug 23 2021 Remi Collet <remi@remirepo.net> - 0.63.0-1
- update to 0.63.0

* Thu Jul 29 2021 Remi Collet <remi@remirepo.net> - 0.62.1-1
- update to 0.62.1

* Wed Jul 21 2021 Remi Collet <remi@remirepo.net> - 0.62.0-1
- update to 0.62.0

* Tue Jul 13 2021 Remi Collet <remi@remirepo.net> - 0.61.0-1
- update to 0.61.0

* Sat May 29 2021 Remi Collet <remi@remirepo.net> - 0.60.0-1
- update to 0.60.0

* Fri May  7 2021 Remi Collet <remi@remirepo.net> - 0.59.0-1
- update to 0.59.0

* Thu Apr 22 2021 Remi Collet <remi@remirepo.net> - 0.58.0-1
- update to 0.58.0

* Fri Mar  5 2021 Remi Collet <remi@remirepo.net> - 0.56.0-1
- update to 0.56.0

* Fri Feb  5 2021 Remi Collet <remi@remirepo.net> - 0.55.0-1
- update to 0.55.0
- open https://github.com/DataDog/dd-trace-php/pull/1144
  add missing dependency on curl extension for PHP 8

* Thu Jan 21 2021 Remi Collet <remi@remirepo.net> - 0.54.0-1
- update to 0.54.0

* Thu Dec 31 2020 Remi Collet <remi@remirepo.net> - 0.53.0-1
- update to 0.53.0

* Tue Dec  1 2020 Remi Collet <remi@remirepo.net> - 0.52.0-1
- update to 0.52.0

* Wed Nov 18 2020 Remi Collet <remi@remirepo.net> - 0.51.0-1
- update to 0.51.0

* Fri Nov  6 2020 Remi Collet <remi@remirepo.net> - 0.50.0-1
- update to 0.50.0

* Thu Oct 15 2020 Remi Collet <remi@remirepo.net> - 0.49.0-1
- update to 0.49.0
- allow PHP 5.4 and 5.5

* Tue Sep 22 2020 Remi Collet <remi@remirepo.net> - 0.48.3-1
- update to 0.48.3

* Fri Sep 11 2020 Remi Collet <remi@remirepo.net> - 0.48.2-1
- update to 0.48.2

* Tue Sep  1 2020 Remi Collet <remi@remirepo.net> - 0.48.1-1
- update to 0.48.1

* Wed Aug 26 2020 Remi Collet <remi@remirepo.net> - 0.48.0-1
- update to 0.48.0
- drop patch merged upstream
- install PHP library
- raise dependency on PHP 5.6

* Sat Aug  1 2020 Remi Collet <remi@remirepo.net> - 0.47.1-1
- update to 0.47.1

* Thu Jul  9 2020 Remi Collet <remi@remirepo.net> - 0.47.0-1
- update to 0.47.0
- open https://github.com/DataDog/dd-trace-php/pull/952
  improve CGI usage in test suite
- open https://github.com/DataDog/dd-trace-php/issues/953
  missing files in 0.47.0 archive

* Wed Jun  3 2020 Remi Collet <remi@remirepo.net> - 0.46.0-1
- update to 0.46.0
- open https://github.com/DataDog/dd-trace-php/issues/912
  failed test and segfault in test suite
- open https://github.com/DataDog/dd-trace-php/issues/913
  missing file for 5.4

* Tue May 19 2020 Remi Collet <remi@remirepo.net> - 0.45.1-1
- update to 0.45.1

* Wed May 13 2020 Remi Collet <remi@remirepo.net> - 0.45.0-1
- update to 0.45.0

* Thu Apr 30 2020 Remi Collet <remi@remirepo.net> - 0.44.1-1
- update to 0.44.1

* Wed Apr 29 2020 Remi Collet <remi@remirepo.net> - 0.44.0-1
- update to 0.44.0

* Thu Apr 23 2020 Remi Collet <remi@remirepo.net> - 0.43.0-1
- update to 0.43.0

* Thu Apr  2 2020 Remi Collet <remi@remirepo.net> - 0.42.0-1
- update to 0.42.0

* Wed Mar 11 2020 Remi Collet <remi@remirepo.net> - 0.41.1-1
- update to 0.41.1
- re-enable ZTS build

* Tue Mar  3 2020 Remi Collet <remi@remirepo.net> - 0.41.0-1
- update to 0.41.0
- disable ZTS build reported as
  https://github.com/DataDog/dd-trace-php/issues/782

* Thu Feb 20 2020 Remi Collet <remi@remirepo.net> - 0.40.0-1
- update to 0.40.0

* Fri Jan 31 2020 Remi Collet <remi@remirepo.net> - 0.39.0-1
- update to 0.39.0

* Wed Jan 29 2020 Remi Collet <remi@remirepo.net> - 0.38.1-1
- update to 0.38.1

* Tue Jan 28 2020 Remi Collet <remi@remirepo.net> - 0.38.0-1
- update to 0.38.0
- fix License: (ASL 2.0 or BSD) and MIT

* Thu Jan  9 2020 Remi Collet <remi@remirepo.net> - 0.37.0-1
- update to 0.37.0
- open tests/ext/sandbox/memory_limit_graceful_bailout.phpt failed test

* Thu Dec 19 2019 Remi Collet <remi@remirepo.net> - 0.36.0-1
- update to 0.36.0

* Fri Dec  6 2019 Remi Collet <remi@remirepo.net> - 0.35.0-1
- update to 0.35.0

* Tue Nov 12 2019 Remi Collet <remi@remirepo.net> - 0.34.1-1
- update to 0.34.1

* Wed Oct 30 2019 Remi Collet <remi@remirepo.net> - 0.34.0-1
- update to 0.34.0

* Thu Oct 17 2019 Remi Collet <remi@remirepo.net> - 0.33.0-1
- update to 0.33.0 (no change)

* Thu Oct 10 2019 Remi Collet <remi@remirepo.net> - 0.32.1-1
- update to 0.32.1 (no change)

* Fri Oct  4 2019 Remi Collet <remi@remirepo.net> - 0.31.0-1
- update to 0.31.0

* Thu Sep 19 2019 Remi Collet <remi@remirepo.net> - 0.30.2-1
- update to 0.30.2
- add upstream patch for PHP 7.4

* Tue Sep 03 2019 Remi Collet <remi@remirepo.net> - 0.30.0-2
- rebuild for 7.4.0RC1

* Fri Aug  9 2019 Remi Collet <remi@remirepo.net> - 0.30.0-1
- update to 0.30.0

* Wed Jul  3 2019 Remi Collet <remi@remirepo.net> - 0.29.0-1
- update to 0.29.0

* Tue Jun 25 2019 Remi Collet <remi@remirepo.net> - 0.28.1-1
- update to 0.28.1
- update options in provided configuration

* Thu Jun 20 2019 Remi Collet <remi@remirepo.net> - 0.28.0-1
- update to 0.28.0

* Fri Jun  7 2019 Remi Collet <remi@remirepo.net> - 0.27.2-1
- update to 0.27.2

* Thu Jun  6 2019 Remi Collet <remi@remirepo.net> - 0.27.1-1
- update to 0.27.1

* Sat Jun  1 2019 Remi Collet <remi@remirepo.net> - 0.26.0-1
- update to 0.26.0
- drop patch merged upstream

* Mon May 27 2019 Remi Collet <remi@remirepo.net> - 0.23.0-2
- add patch for 7.4 from
  https://github.com/DataDog/dd-trace-php/pull/452

* Fri May 10 2019 Remi Collet <remi@remirepo.net> - 0.23.0-1
- update to 0.23.0 (no change)

* Wed May  8 2019 Remi Collet <remi@remirepo.net> - 0.22.0-1
- update to 0.22.0

* Thu May  2 2019 Remi Collet <remi@remirepo.net> - 0.21.0-1
- update to 0.21.0
- drop patch merged upstream

* Fri Apr 26 2019 Remi Collet <remi@remirepo.net> - 0.20.0-1
- update to 0.20.0
- add patch for test suite on 7.3 from
  https://github.com/DataDog/dd-trace-php/pull/412

* Sun Apr 14 2019 Remi Collet <remi@remirepo.net> - 0.19.0-1
- update to 0.19.0

* Tue Apr  9 2019 Remi Collet <remi@remirepo.net> - 0.18.0-2
- test build

* Tue Apr  9 2019 Remi Collet <remi@remirepo.net> - 0.18.0-1
- update to 0.18.0
- open https://github.com/DataDog/dd-trace-php/issues/393
  missing files in pecl archive, and open
  https://github.com/DataDog/dd-trace-php/pull/397
- open https://github.com/DataDog/dd-trace-php/issues/394
  failed test on 32-bit build
- open https://github.com/DataDog/dd-trace-php/issues/395
  build failure with old GCC, and open
  https://github.com/DataDog/dd-trace-php/pull/396

* Wed Mar 27 2019 Remi Collet <remi@remirepo.net> - 0.17.0-1
- update to 0.17.0 (no change)

* Thu Mar 21 2019 Remi Collet <remi@remirepo.net> - 1.16.1-1
- update to 1.16.1 (no change)

* Wed Mar 20 2019 Remi Collet <remi@remirepo.net> - 0.16.0-1
- update to 0.16.0
- update provided configuration

* Tue Mar 12 2019 Remi Collet <remi@remirepo.net> - 0.15.1-1
- update to 0.15.1 (stable)

* Wed Feb 27 2019 Remi Collet <remi@remirepo.net> - 0.14.1-1
- update to 0.14.1

* Wed Feb 27 2019 Remi Collet <remi@remirepo.net> - 0.14.0-1
- update to 0.14.0
- update provided configuration

* Mon Feb 25 2019 Remi Collet <remi@remirepo.net> - 0.13.4-1
- update to 0.13.4

* Tue Feb 19 2019 Remi Collet <remi@remirepo.net> - 0.13.3-1
- update to 0.13.3

* Tue Feb 19 2019 Remi Collet <remi@remirepo.net> - 0.13.2-1
- update to 0.13.2

* Wed Feb 13 2019 Remi Collet <remi@remirepo.net> - 0.13.1-1
- update to 0.13.1

* Wed Feb 13 2019 Remi Collet <remi@remirepo.net> - 0.13.0-1
- update to 0.13.0

* Sat Feb  2 2019 Remi Collet <remi@remirepo.net> - 0.12.0-1
- update to 0.12.0

* Wed Jan 30 2019 Remi Collet <remi@remirepo.net> - 0.11.0-1
- update to 0.11.0
- open https://github.com/DataDog/dd-trace-php/issues/271 PHP 5 ZTS

* Tue Jan 22 2019 Remi Collet <remi@remirepo.net> - 0.10.0-1
- update to 0.10.0
- open https://github.com/DataDog/dd-trace-php/issues/249 missing files
- open https://github.com/DataDog/dd-trace-php/issues/250 PHP compat
- open https://github.com/DataDog/dd-trace-php/issues/253 PHP 5.4 ZTS

* Sat Jan 12 2019 Remi Collet <remi@remirepo.net> - 0.9.1-1
- update to 0.9.1

* Tue Jan  8 2019 Remi Collet <remi@remirepo.net> - 0.9.0-1
- update to 0.9.0

* Fri Jan  4 2019 Remi Collet <remi@remirepo.net> - 0.8.1-1
- initial package, version 0.8.1 (beta)
- open https://github.com/DataDog/dd-trace-php/issues/213 PHP < 5.6
- open https://github.com/DataDog/dd-trace-php/issues/212 PHP 7.3
