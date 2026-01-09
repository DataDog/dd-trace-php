#pragma once

#include <php.h>
#include "attributes.h"
#include "request_abort.h"

struct req_info {
    const char *nullable command_name; // for logging
    zend_object *nullable root_span;
    zend_string *nullable client_ip;
    struct block_params block_params; // out
};
