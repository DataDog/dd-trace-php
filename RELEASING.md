# Releasing DD Trace PHP

## Packagist Package

1. Make sure that all the PR in the current milestone are merged. Move remaining PRs that will not make into the release to the next milestone.
1. Make sure that there is a 1-to-1 correlation between commits and PRs. This is easy thanks to the squash merge strategy that we adopted.
1. Create the version bump commit:
    1. Make sure that the changelog is up to date, if not fix it. Make sure bottom links are up to date.
    1. Update the version number in `src/DDTrace/Version.php`.
    1. Update the version number in `src/ext/version.h`.
    1. Update `package.xml` and run `$ pear package-validate package.xml`.
    1. Create the PR and ask for code review.
    1. Merge it to master
1. Create the release named after the release number, e.g. `0.9.0`, (initially as a draft) and copy there the changelog
   for the current release. Most of the times you will have two sections: `Added` and `Fixed`.
1. Head to CircleCi workflow's page for the master branch and from the job `build_packages --> packages` downloads the
   artifacts `datadog-php-tracer-<VERSION>-beta.x86_64.tar.gz`, `datadog-php-tracer-<VERSION>_beta-1.x86_64.rpm`,
   `datadog-php-tracer_<VERSION>-beta_amd64.deb`, `datadog-php-tracer_<VERSION>-beta_noarch.apk` and upload them to
   the release. Make sure the version number matches.
1. Click `Update` from the admin view of the [datadog/dd-trace][packagist] package.
1. Run `pear package` & upload release to PECL: https://pecl.php.net/release-upload.php
1. Once the process is completed, close the milestone.

[packagist]: https://packagist.org/packages/datadog/dd-trace
