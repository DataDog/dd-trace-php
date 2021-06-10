Run this command to print the list of different reasons why tests are skipped.

```
make test | grep 'SKIP ' | sed 's/.*reason://' | sort | uniq -c
```

Here is the current list of reasons why some tests are skipped (<200 out of ~1900):

```
 1  Atlas URIs not found
 1  DNS seedlist test must be run manually
 2  E_WARNING: file_get_contents(http://localhost:8889/v1): failed to open stream: Cannot assign requested address @ /home/mongodb-driver-1.9.1/tests/utils/skipif.php:437
 2  OCSP tests not wanted
 6  Only for 32-bit platform
 1  Server storage engine is 'wiredTiger' (needed 'mmapv1')
 7  Server version '4.4.6' >= '3.1'
 2  Server version '4.4.6' >= '3.4'
 3  Server version '4.4.6' >= '3.6'
 1  Server version '4.4.6' >= '4.2'
 1  Server version '4.4.6' >= '4.3.4'
15  URI is not using SSL
 3  URI is not using auth
 2  libmongocrypt is enabled
10  test commands are disabled
18  topology does not support transactions
 1  topology is a standalone
75  topology is not a replica set
26  topology is not a replica set or sharded cluster with replica set
 2  topology is not a sharded cluster
14  topology is not a sharded cluster with replica set
```
