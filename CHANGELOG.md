Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Fixed
- Fix double free at sidecar connection (#3407)
- Fix crash with freed resource (#3402)
- Fix invalid user headers injection (#3403)
- Exclude /vendor from code origins (#3399)
