The files in `upstream/` are unmodified copies from
[Remi's PHP Datadog RPM packaging](https://git.remirepo.net/cgit/rpms/php/pecl/php-pecl-datadog-trace.git/),
pinned by `upstream/REVISION`. The spec retains its copyright, CeCILL-2.1
license notice, and changelog.

Run `tooling/bin/update-pecl-remi [commit]` to refresh them. Without a commit,
the script uses upstream HEAD. It checks that `parallel-build.patch` still
applies before replacing any files.

`tooling/bin/pecl-offline-vendored-build` runs the actual `makedeps.sh` and
the spec's prep, build, install, and load checks through `rpmbuild`. Its RPM
macros adapt paths and hardening flags to the Debian CI image; `--nodeps`
uses the tools already installed in that image instead of an RPM database.
The spec's module-presence checks disable tracing to avoid starting agent
traffic and detached sidecars inside the build's network audit. The installed
source-loading check and PHPTs run with tracing enabled afterward.
The only spec patch runs the NTS/ZTS builds concurrently and shares their
PHP-independent mock generator. The version comes from the PECL archive.

The test selects the spec's libddwaf-from-source mode, checks that the entire
build performs no IP networking, and removes rust-src from the toolchain.
After installation, it runs every PHPT bundled in the archive for both ABIs,
using separate test trees and the installed extension. A separate assertion
checks autoloading from the installed PHP sources; the PHPTs use the embedded
API, matching the existing PECL test job's `sources_path` setting.
Each ABI's suite uses all test workers; suites run successively because some
tests use fixed socket paths and ports that would collide across ABIs.
It does not use the spec's optional test exclusions.
The worker budget also controls parallel gzip for the dependency archive.
