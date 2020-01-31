#ifndef DDTRACE_COMMS_PHP_H
#define DDTRACE_COMMS_PHP_H

#include <Zend/zend.h>
#include <curl/curl.h>
#include <stdbool.h>

struct curl_slist *ddtrace_convert_hashtable_to_curl_slist(HashTable *input);
bool ddtrace_memoize_http_headers(HashTable *input);

#endif  // DDTRACE_COMMS_PHP_H
