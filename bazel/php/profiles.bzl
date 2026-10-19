"""Historical PHP source-build profiles retained only as CI provenance.

The Bazel matrix publishes OCI header SDKs.  The historical SAPI and extension
lists below describe the former CI build expectations; they are deliberately
separate from the empty runtime-artifact capability fields.
"""

_COMMON = ("--enable-cgi", "--enable-embed", "--enable-fpm", "--with-fpm-user=www-data", "--with-fpm-group=www-data", "--enable-option-checking=fatal", "--program-prefix=")
_FULL = ("--disable-phpdbg", "--enable-bcmath", "--enable-ftp", "--enable-mbstring", "--enable-pcntl", "--enable-soap", "--enable-sockets", "--with-curl", "--with-libedit", "--with-mhash", "--with-mysqli=mysqlnd", "--with-openssl", "--with-pdo-mysql=mysqlnd", "--with-pdo-pgsql", "--with-pdo-sqlite", "--with-pear", "--with-readline", "--with-xsl", "--with-zlib")
_SHARED = ("--disable-all", "--enable-phpdbg", "--enable-pcntl=shared", "--enable-mbstring=shared", "--without-pear")

SAPI_FULL = ("cli", "cgi", "fpm", "embed", "apache2handler")
SAPI_SHARED = ("cli", "cgi", "fpm", "embed", "apache2handler", "phpdbg")

_CORE_EXTENSIONS = ("ctype", "date", "dom", "fileinfo", "filter", "hash", "iconv", "json", "libxml", "pcre", "phar", "posix", "reflection", "session", "simplexml", "spl", "standard", "tokenizer", "xml", "xmlreader", "xmlwriter")

def _bundled_extensions(minor, shared, runtime_profile):
    numeric = int(minor.replace(".", ""))
    if runtime_profile == "alpine":
        extensions = list(_CORE_EXTENSIONS) + ["curl", "ftp", "mbstring", "mysqli", "mysqlnd", "pdo", "pdo_mysql", "zlib"]
        extensions.append("mcrypt" if numeric < 72 else "sodium")
        if numeric >= 74:
            extensions.append("ffi")
        return tuple(extensions)
    if runtime_profile == "release":
        extensions = list(_CORE_EXTENSIONS) + ["calendar", "curl", "exif", "ftp", "gettext", "mbstring", "mysqli", "mysqlnd", "openssl", "pcntl", "pdo", "pdo_mysql", "readline", "shmop", "sockets", "sysvmsg", "sysvsem", "sysvshm", "xsl", "zlib", "bz2"]
        if numeric <= 84:
            extensions.append("opcache")
        if numeric <= 73:
            extensions.extend(["pdo_pgsql", "pdo_sqlite", "zip"])
        else:
            extensions.extend(["ffi", "zip"])
        if numeric >= 72:
            extensions.append("sodium")
        return tuple(extensions)
    if shared:
        extensions = list(_CORE_EXTENSIONS) + ["mbstring", "pcntl"]
        if numeric <= 74 or numeric == 80:
            extensions.append("json")
        if numeric >= 74:
            extensions.append("ffi")
        return tuple(extensions)
    if runtime_profile == "alpine-legacy":
        return _CORE_EXTENSIONS + ("curl", "ftp", "mbstring", "mysqli", "mysqlnd", "openssl", "opcache", "pdo", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "sockets", "ffi", "sodium", "zip", "zlib")
    extensions = list(_CORE_EXTENSIONS) + ["bcmath", "curl", "ftp", "gd", "mbstring", "mysqli", "mysqlnd", "openssl", "pcntl", "pdo", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "readline", "soap", "sockets", "xsl", "zlib"]
    if numeric <= 84:
        extensions.append("opcache")
    if numeric >= 71:
        extensions.append("intl")
    if numeric <= 70:
        extensions.append("mcrypt")
    elif numeric >= 72:
        extensions.append("sodium")
    if numeric >= 74:
        extensions.extend(["ffi", "zip"])
    else:
        extensions.append("zip")
    if numeric >= 80:
        extensions.append("zend_test")
    return tuple(extensions)

def _version_args(minor, shared):
    numeric = int(minor.replace(".", ""))
    args = list(_SHARED if shared else _FULL)
    if not shared:
        if numeric <= 84:
            args.append("--enable-opcache")
        if numeric >= 71:
            args.append("--enable-intl")
        if numeric <= 73:
            args.append("--enable-zip")
        if numeric >= 74:
            args.extend(["--enable-gd", "--with-jpeg", "--with-freetype", "--with-webp", "--with-zip"])
        else:
            # `${SYSROOT}` records the former CI source-build substitution.
            args.extend(["--with-gd", "--with-png-dir=${SYSROOT}", "--with-jpeg-dir=${SYSROOT}/usr/include"])
        if numeric <= 70:
            args.append("--with-mcrypt")
        if numeric >= 72:
            args.append("--with-sodium")
    else:
        if numeric >= 74:
            args.append("--with-ffi=shared")
        if numeric <= 74:
            args.append("--enable-json=shared")
    if not shared and numeric >= 74:
        args.append("--with-ffi")
    if not shared and numeric >= 80:
        args.append("--enable-zend-test=shared")
    return tuple(args + list(_COMMON))

def _alpine_args(minor):
    numeric = int(minor.replace(".", ""))
    args = ["--enable-fpm", "--enable-ftp", "--enable-mbstring", "--with-curl", "--with-libedit", "--with-mhash", "--with-mysqli=mysqlnd", "--without-openssl", "--without-pdo-sqlite", "--without-sqlite3", "--with-zlib", "--with-fpm-user=www-data", "--with-fpm-group=www-data"]
    args.append("--with-mcrypt" if numeric < 72 else "--with-sodium=shared")
    if numeric >= 74:
        args.append("--with-ffi")
    return tuple(args)

def _release_args(minor):
    numeric = int(minor.replace(".", ""))
    args = ["--enable-option-checking=fatal", "--enable-calendar", "--enable-cgi", "--enable-exif", "--enable-fpm", "--enable-ftp", "--enable-mbstring", "--enable-mysqlnd", "--enable-phpdbg", "--enable-pcntl", "--enable-shmop", "--enable-sockets", "--enable-sysvmsg", "--enable-sysvsem", "--enable-sysvshm", "--with-apxs2", "--with-bz2", "--with-curl", "--with-fpm-user=www-data", "--with-fpm-group=www-data", "--with-gettext", "--with-libedit", "--with-mhash", "--with-mysqli=mysqlnd", "--with-openssl", "--with-pdo-mysql=mysqlnd", "--with-pear", "--with-readline", "--with-xsl", "--with-zlib"]
    if numeric <= 84:
        args.append("--enable-opcache")
    if numeric <= 73:
        args.extend(["--enable-zip", "--with-pdo-pgsql", "--with-pdo-sqlite"])
    else:
        args.extend(["--with-ffi", "--with-zip"])
    if numeric >= 72:
        args.append("--with-sodium")
    return tuple(args)

def _legacy_alpine_args():
    return ("--enable-option-checking=fatal", "--enable-cgi", "--enable-embed", "--enable-fpm", "--enable-ftp", "--enable-mbstring", "--enable-opcache", "--enable-phpdbg", "--enable-sockets", "--with-curl", "--with-ffi", "--with-fpm-user=www-data", "--with-fpm-group=www-data", "--with-libedit", "--with-mhash", "--with-mysqli=mysqlnd", "--with-openssl", "--with-pdo-mysql=mysqlnd", "--with-pdo-pgsql", "--with-pdo-sqlite", "--with-pear", "--with-readline", "--with-sodium", "--with-zip", "--with-zlib")

def profile(minor, name, shared = False, runtime_profile = "bookworm"):
    """Returns ABI configure inputs and former CI inventory provenance."""
    numeric = int(minor.replace(".", ""))
    args = list(_alpine_args(minor) if runtime_profile == "alpine" else _release_args(minor) if runtime_profile == "release" else _legacy_alpine_args() if runtime_profile == "alpine-legacy" else _version_args(minor, shared))
    debug = name in ("debug", "debug-zts", "debug-zts-asan")
    zts = name in ("zts", "debug-zts", "debug-zts-asan")
    asan = name in ("debug-zts-asan", "nts-asan")
    if debug:
        args.append("--enable-debug")
    if zts:
        args.extend(["--enable-maintainer-zts" if numeric <= 74 else "--enable-zts", "--disable-zend-signals"])
        if numeric >= 82:
            args.append("--enable-zend-max-execution-timers")
    if asan:
        args.append("--without-pcre-jit")
    historical_sapis = ("cli", "cgi", "fpm") if runtime_profile == "alpine" else (("cli", "cgi", "fpm", "apache2handler", "phpdbg") if runtime_profile == "release" else (("cli", "cgi", "fpm", "embed", "phpdbg") if runtime_profile == "alpine-legacy" else (SAPI_SHARED if shared else SAPI_FULL)))
    historical_shared = (("ffi", "json", "mbstring", "pcntl") if shared and numeric == 74 else ("ffi", "mbstring", "pcntl") if shared else (("sodium",) if runtime_profile == "alpine" and numeric >= 72 else (("zend_test",) if numeric >= 80 and runtime_profile == "bookworm" else ())))
    return struct(
        configure_args = tuple(args),
        debug = debug,
        zts = zts,
        asan = asan,
        shared = shared,
        sapis = (),
        bundled_extensions = (),
        shared_extensions = (),
        historical_sapis = historical_sapis,
        historical_bundled_extensions = _bundled_extensions(minor, shared, runtime_profile),
        historical_shared_extensions = historical_shared,
    )
