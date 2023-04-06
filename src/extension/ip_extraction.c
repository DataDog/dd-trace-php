// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "ip_extraction.h"
#include "attributes.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"

#include <arpa/inet.h>
#include <netinet/in.h>
#include <php.h>
#include <zend_API.h>
#include <zend_smart_str.h>

typedef struct _ipaddr {
    int af;
    union {
        struct in_addr v4;
        struct in6_addr v6;
    };
} ipaddr;

typedef bool (*extract_func_t)(zend_string *nonnull value, ipaddr *nonnull out);

typedef struct _header_map_node {
    zend_string *key;
    zend_string *name;
    extract_func_t parse_fn;
} header_map_node;

typedef enum _priority_header_id {
    X_FORWARDED_FOR = 0,
    X_REAL_IP,
    TRUE_CLIENT_IP,
    X_CLIENT_IP,
    X_FORWARDED,
    FORWARDED_FOR,
    X_CLUSTER_CLIENT_IP,
    FASTLY_CLIENT_IP,
    CF_CONNECTING_IP,
    CF_CONNECTING_IPV6,
    MAX_HEADER_ID
} priority_header_id;

static header_map_node priority_header_map[MAX_HEADER_ID];

static zend_string *nonnull _remote_addr_key;
static THREAD_LOCAL_ON_ZTS zend_string *nullable client_ip;

static void _register_testing_objects(void);
static zend_string *nullable _fetch_arr_str(
    const zval *nonnull server, zend_string *nonnull key);
static bool _is_private(const ipaddr *nonnull addr);
static zend_string *nullable _ipaddr_to_zstr(const ipaddr *ipaddr);
static zend_string *_try_extract(const zval *nonnull server,
    zend_string *nonnull key, extract_func_t nonnull extract_func);
static bool _parse_x_forwarded_for(
    zend_string *nonnull value, ipaddr *nonnull out);
static bool _parse_plain(zend_string *nonnull zvalue, ipaddr *nonnull out);
static bool _parse_plain_raw(zend_string *nonnull zvalue, ipaddr *nonnull out);
static bool _parse_forwarded(zend_string *nonnull zvalue, ipaddr *nonnull out);

static void _init_relevant_ip_headers()
{
    priority_header_map[X_FORWARDED_FOR] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED_FOR"), 1),
        zend_string_init_interned(ZEND_STRL("x-forwarded-for"), 1),
        &_parse_x_forwarded_for};
    priority_header_map[X_REAL_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_X_REAL_IP"), 1),
        zend_string_init_interned(ZEND_STRL("x-real-ip"), 1), &_parse_plain};
    priority_header_map[TRUE_CLIENT_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_TRUE_CLIENT_IP"), 1),
        zend_string_init_interned(ZEND_STRL("true-client-ip"), 1),
        &_parse_plain};
    priority_header_map[X_CLIENT_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_X_CLIENT_IP"), 1),
        zend_string_init_interned(ZEND_STRL("x-client-ip"), 1), &_parse_plain};
    priority_header_map[X_FORWARDED] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED"), 1),
        zend_string_init_interned(ZEND_STRL("x-forwarded"), 1),
        &_parse_forwarded};
    priority_header_map[FORWARDED_FOR] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED_FOR"), 1),
        zend_string_init_interned(ZEND_STRL("forwarded-for"), 1),
        &_parse_x_forwarded_for};
    priority_header_map[X_CLUSTER_CLIENT_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_X_CLUSTER_CLIENT_IP"), 1),
        zend_string_init_interned(ZEND_STRL("x-cluster-client-ip"), 1),
        &_parse_x_forwarded_for};
    priority_header_map[FASTLY_CLIENT_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_FASTLY_CLIENT_IP"), 1),
        zend_string_init_interned(ZEND_STRL("fastly-client-ip"), 1),
        &_parse_plain};
    priority_header_map[CF_CONNECTING_IP] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IP"), 1),
        zend_string_init_interned(ZEND_STRL("cf-connecting-ip"), 1),
        &_parse_plain};
    priority_header_map[CF_CONNECTING_IPV6] = (header_map_node){
        zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IPV6"), 1),
        zend_string_init_interned(ZEND_STRL("cf-connecting-ipv6"), 1),
        &_parse_plain};
}

void dd_ip_extraction_startup()
{
    _remote_addr_key = zend_string_init_interned(ZEND_STRL("REMOTE_ADDR"), 1);

    _init_relevant_ip_headers();
    _register_testing_objects();
}

bool dd_parse_client_ip_header_config(
    zai_string_view value, zval *nonnull decoded_value, bool persistent)
{
    if (!value.ptr[0]) {
        if (persistent) {
            ZVAL_EMPTY_PSTRING(decoded_value);
        } else {
            ZVAL_EMPTY_STRING(decoded_value);
        }
        return true;
    }

    size_t key_len = LSTRLEN("HTTP_") + value.len;

    ZVAL_STR(decoded_value, zend_string_alloc(key_len, persistent));
    char *out = Z_STRVAL_P(decoded_value);
    memcpy(out, ZEND_STRL("HTTP_"));
    out += LSTRLEN("HTTP_");
    const char *end = value.ptr + value.len;
    for (const char *p = value.ptr; p != end; p++) {
        char c = *p;
        if (c >= 'a' && c <= 'z') {
            c = (char)(c - 'a' + 'A');
        } else if (c == '-') {
            c = '_';
        }
        *out++ = (char)c;
    }
    *out = '\0';

    return true;
}

zend_string *nullable dd_ip_extraction_find(zval *nonnull server)
{
    // Extract ip from define customer header
    zend_string *ipheader = get_DD_TRACE_CLIENT_IP_HEADER();
    if (ipheader && ZSTR_LEN(ipheader) > 0) {
        zend_string *value = _fetch_arr_str(server, ipheader);
        if (!value) {
            return NULL;
        }

        ipaddr out;
        bool succ;
        succ = _parse_forwarded(value, &out);
        if (succ) {
            return _ipaddr_to_zstr(&out);
        }

        succ = _parse_x_forwarded_for(value, &out);
        if (succ) {
            return _ipaddr_to_zstr(&out);
        }

        return NULL;
    }

    if (!server || Z_TYPE_P(server) != IS_ARRAY) {
        return NULL;
    }

    // Lets get the ip on the first header found
    for (unsigned i = 0; i < ARRAY_SIZE(priority_header_map); i++) {
        zval *val = zend_hash_find(Z_ARR_P(server), priority_header_map[i].key);
        if (val && Z_TYPE_P(val) == IS_STRING && Z_STRLEN_P(val) > 0) {
            ipaddr out;
            if ((priority_header_map[i].parse_fn)(Z_STR_P(val), &out)) {
                return _ipaddr_to_zstr(&out);
            }
        }
    }

    // No ip header found try remote_addr
    return _try_extract(server, _remote_addr_key, &_parse_plain_raw);
}

void dd_ip_extraction_rinit(void)
{
    zval *_server =
        dd_php_get_autoglobal(TRACK_VARS_SERVER, LSTRARG("_SERVER"));
    if (!_server) {
        mlog(dd_log_info, "No SERVER autoglobal available");
        return;
    }
    client_ip = dd_ip_extraction_find(_server);
}

void dd_ip_extraction_rshutdown(void)
{
    if (client_ip) {
        zend_string_release(client_ip);
        client_ip = NULL;
    }
}

zend_string *nullable dd_ip_extraction_get_ip() { return client_ip; }

static zend_string *_try_extract(const zval *nonnull server,
    zend_string *nonnull key, extract_func_t nonnull extract_func)
{
    zend_string *value = _fetch_arr_str(server, key);
    if (!value) {
        return NULL;
    }
    ipaddr out;
    if ((*extract_func)(value, &out)) {
        return _ipaddr_to_zstr(&out);
    }
    return NULL;
}

static zend_string *nullable _fetch_arr_str(
    const zval *nonnull server, zend_string *nonnull key)
{
    zval *value = zend_hash_find(Z_ARR_P(server), key);
    if (!value) {
        return NULL;
    }
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) != IS_STRING) {
        return NULL;
    }
    return Z_STR_P(value);
}

static zend_string *nullable _ipaddr_to_zstr(const ipaddr *ipaddr)
{
    char buf[INET6_ADDRSTRLEN];
    const char *res =
        inet_ntop(ipaddr->af, (char *)&ipaddr->v4, buf, sizeof(buf));
    if (!res) {
        mlog_err(dd_log_warning, "inet_ntop failed");
        return NULL;
    }
    return zend_string_init(res, strlen(res), 0);
}

static bool _parse_ip_address(
    const char *nonnull _addr, size_t addr_len, ipaddr *nonnull out);
static bool _parse_ip_address_maybe_port_pair(
    const char *nonnull addr, size_t addr_len, ipaddr *nonnull out);

static bool _parse_x_forwarded_for(
    zend_string *nonnull zvalue, ipaddr *nonnull out)
{
    const char *value = ZSTR_VAL(zvalue);
    const char *end = value + ZSTR_LEN(zvalue);
    bool succ;
    do {
        for (; value < end && *value == ' '; value++) {}
        const char *comma = memchr(value, ',', end - value);
        const char *end_cur = comma ? comma : end;
        succ = _parse_ip_address_maybe_port_pair(value, end_cur - value, out);
        if (succ) {
            succ = !_is_private(out);
        }
        value = (comma && comma + 1 < end) ? (comma + 1) : NULL;
    } while (!succ && value);
    return succ;
}

static bool _parse_forwarded(zend_string *nonnull zvalue, ipaddr *nonnull out)
{
    enum {
        between,
        key,
        before_value,
        value_token,
        value_quoted,
    } state = between;
    const char *r = ZSTR_VAL(zvalue);
    const char *end = r + ZSTR_LEN(zvalue);
    const char *start;
    bool consider_value = false;

    // https://datatracker.ietf.org/doc/html/rfc7239#section-4
    // we parse with some leniency
    while (r < end) {
        switch (state) { // NOLINT
        case between:
            if (*r == ' ' || *r == ';' || *r == ',') {
                break;
            }
            start = r;
            state = key;
            break;
        case key:
            if (*r == '=') {
                consider_value = (r - start == 3) &&
                                 (start[0] == 'f' || start[0] == 'F') &&
                                 (start[1] == 'o' || start[1] == 'O') &&
                                 (start[2] == 'r' || start[2] == 'R');
                state = before_value;
            }
            break;
        case before_value:
            if (*r == '"') {
                start = r + 1;
                state = value_quoted;
            } else if (*r == ' ' || *r == ';' || *r == ',') {
                // empty value; we disconsider it
                state = between;
            } else {
                start = r;
                state = value_token;
            }
            break;
        case value_token: {
            const char *token_end;
            if (*r == ' ' || *r == ';' || *r == ',') {
                token_end = r;
            } else if (r + 1 == end) {
                token_end = end;
            } else {
                break;
            }
            if (consider_value) {
                bool succ = _parse_ip_address_maybe_port_pair(
                    start, token_end - start, out);
                if (succ && !_is_private(out)) {
                    return true;
                }
            }
            state = between;
            break;
        }
        case value_quoted:
            if (*r == '"') {
                if (consider_value) {
                    // ip addresses can't contain quotes, so we don't try to
                    // unescape them
                    bool succ = _parse_ip_address_maybe_port_pair(
                        start, r - start, out);
                    if (succ && !_is_private(out)) {
                        return true;
                    }
                }
                state = between;
            } else if (*r == '\\') {
                r++;
            }
            break;
        }
        r++;
    }

    return false;
}

static bool _parse_plain_raw(zend_string *nonnull zvalue, ipaddr *nonnull out)
{
    return _parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), out);
}

static bool _parse_plain(zend_string *nonnull zvalue, ipaddr *nonnull out)
{
    return _parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), out) &&
           !_is_private(out);
}

static bool _parse_ip_address(
    const char *nonnull _addr, size_t addr_len, ipaddr *nonnull out)
{
    if (addr_len == 0) {
        return false;
    }
    char *addr = safe_emalloc(addr_len, 1, 1);
    memcpy(addr, _addr, addr_len);
    addr[addr_len] = '\0';

    bool res = true;

    int ret = inet_pton(AF_INET, addr, &out->v4);
    if (ret != 1) {
        ret = inet_pton(AF_INET6, addr, &out->v6);
        if (ret != 1) {
            mlog(dd_log_info, "Not recognized as IP address: \"%s\"", addr);
            res = false;
            goto err;
        }

        uint8_t *s6addr = out->v6.s6_addr;
        static const uint8_t ip4_mapped_prefix[12] = {[10 ... 11] = 0xFF};
        if (memcmp(s6addr, ip4_mapped_prefix, sizeof(ip4_mapped_prefix)) == 0) {
            // IPv4 mapped
            mlog(dd_log_debug, "Parsed as IPv4 mapped address: %s", addr);
            uint8_t s4addr[4];
            memcpy(s4addr, s6addr + sizeof(ip4_mapped_prefix), 4);
            memcpy(&out->v4.s_addr, s4addr, sizeof(s4addr));
            out->af = AF_INET;
        } else {
            mlog(dd_log_debug, "Parsed as IPv6 address: %s", addr);
            out->af = AF_INET6;
        }
    } else {
        mlog(dd_log_debug, "Parsed as IPv4 address: %s", addr);
        out->af = AF_INET;
    }

err:
    efree(addr);
    return res;
}

static bool _parse_ip_address_maybe_port_pair(
    const char *nonnull addr, size_t addr_len, ipaddr *nonnull out)
{
    if (addr_len == 0) {
        return false;
    }
    if (addr[0] == '[') { // ipv6
        const char *pos_close = memchr(addr + 1, ']', addr_len - 1);
        if (!pos_close) {
            return false;
        }
        return _parse_ip_address(addr + 1, pos_close - (addr + 1), out);
    }
    const char *colon = memchr(addr, ':', addr_len);
    if (colon && zend_memrchr(addr, ':', addr_len) == colon) {
        return _parse_ip_address(addr, colon - addr, out);
    }

    return _parse_ip_address(addr, addr_len, out);
}

#define CT_HTONL(x)                                                            \
    ((((x) >> 24) & 0x000000FFU) | (((x) >> 8) & 0x0000FF00U) |                \
        (((x) << 8) & 0x00FF0000U) | (((x) << 24) & 0xFF000000U))

static bool _is_private_v4(const struct in_addr *nonnull addr)
{
    static const struct {
        struct in_addr base;
        struct in_addr mask;
    } priv_ranges[] = {
        {
            .base.s_addr = CT_HTONL(0x0A000000U), // 10.0.0.0
            .mask.s_addr = CT_HTONL(0xFF000000U), // 255.0.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xAC100000U), // 172.16.0.0
            .mask.s_addr = CT_HTONL(0xFFF00000U), // 255.240.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xC0A80000U), // 192.168.0.0
            .mask.s_addr = CT_HTONL(0xFFFF0000U), // 255.255.0.0
        },
        {
            .base.s_addr = CT_HTONL(0x7F000000U), // 127.0.0.0
            .mask.s_addr = CT_HTONL(0xFF000000U), // 255.0.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xA9FE0000U), // 169.254.0.0
            .mask.s_addr = CT_HTONL(0xFFFF0000U), // 255.255.0.0
        },
    };

    for (unsigned i = 0; i < ARRAY_SIZE(priv_ranges); i++) {
        __auto_type range = priv_ranges[i];
        if ((addr->s_addr & range.mask.s_addr) == range.base.s_addr) {
            return true;
        }
    }
    return false;
}

static bool _is_private_v6(const struct in6_addr *nonnull addr)
{
    // clang-format off
    static const struct {
        union {
            struct in6_addr base;
            unsigned __int128 base_i;
        };
        union {
            struct in6_addr mask;
            unsigned __int128 mask_i;
        };
    } priv_ranges[] = {
        {
            .base.s6_addr = {[15] = 1}, // loopback
            .mask.s6_addr = {[0 ... 15] = 0xFF}, // /128
        },
        {
            .base.s6_addr = {0xFE, 0x80}, // link-local
            .mask.s6_addr = {0xFF, 0xC0}, // /10
        },
        {
            .base.s6_addr = {0xFE, 0xC0}, // site-local
            .mask.s6_addr = {0xFF, 0xC0}, // /10
        },
        {
            .base.s6_addr = {0xFD}, // unique local address
            .mask.s6_addr = {0xFF}, // /8
        },
        {
            .base.s6_addr = {0xFC},
            .mask.s6_addr = {0xFE}, // /7
        },
    };
    // clang-format on

    unsigned __int128 addr_i;
    memcpy(&addr_i, addr->s6_addr, sizeof(addr_i));

    for (unsigned i = 0; i < ARRAY_SIZE(priv_ranges); i++) {
        __auto_type range = &priv_ranges[i];
        if ((addr_i & range->mask_i) == range->base_i) {
            return true;
        }
    }
    return false;
}

static bool _is_private(const ipaddr *nonnull addr)
{
    if (addr->af == AF_INET) {
        return _is_private_v4(&addr->v4);
    }
    return _is_private_v6(&addr->v6);
}

static PHP_FUNCTION(datadog_appsec_testing_extract_ip_addr)
{
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &arr) == FAILURE) {
        return;
    }

    zend_string *res = dd_ip_extraction_find(arr);
    if (!res) {
        return;
    }

    RETURN_STR(res);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(extract_ip_addr, 0, 1, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "extract_ip_addr", PHP_FN(datadog_appsec_testing_extract_ip_addr), extract_ip_addr, 0)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects()
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}
