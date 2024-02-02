#pragma once

#include "attributes.h"
#include <php.h>

struct req_info {
    const char *nullable command_name; // for logging
    zend_object *nullable root_span;
    zend_string *nullable client_ip;
};
