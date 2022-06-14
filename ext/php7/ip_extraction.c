// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "logging.h"

#include <arpa/inet.h>
#include <netinet/in.h>
#include <php.h>
#include <zend_API.h>

#define ARRAY_SIZE(x) (sizeof(x) / sizeof((x)[0]))

typedef struct _ipaddr {
    int af;
    union {
        struct in_addr v4;
        struct in6_addr v6;
    };
} ipaddr;

static zend_string *_x_forwarded_for_key, *_x_real_ip_key,
    *_client_ip_key, *_x_forwarded_key,
    *_x_cluster_client_ip_key, *_forwarded_for_key,
    *_forwarded_key, *_via_key, *_true_client_ip_key,
    *_remote_addr_key;

typedef bool (*extract_func_t)(zend_string *value, ipaddr *out);

void dd_ip_extraction_startup()
{
    _x_forwarded_for_key =
        zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED_FOR"), 1);
    _x_real_ip_key = zend_string_init_interned(ZEND_STRL("HTTP_X_REAL_IP"), 1);
    _client_ip_key = zend_string_init_interned(ZEND_STRL("HTTP_CLIENT_IP"), 1);
    _x_forwarded_key =
        zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED"), 1);
    _x_cluster_client_ip_key =
        zend_string_init_interned(ZEND_STRL("HTTP_X_CLUSTER_CLIENT_IP"), 1);
    _forwarded_for_key =
        zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED_FOR"), 1);
    _forwarded_key = zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED"), 1);
    _via_key = zend_string_init_interned(ZEND_STRL("HTTP_VIA"), 1);
    _true_client_ip_key =
        zend_string_init_interned(ZEND_STRL("HTTP_TRUE_CLIENT_IP"), 1);
    _remote_addr_key = zend_string_init_interned(ZEND_STRL("REMOTE_ADDR"), 1);
}

static zend_string *_fetch_arr_str(
    const zval *server, zend_string *key);
static bool _is_private(const ipaddr *addr);
static zend_string *_ipaddr_to_zstr(const ipaddr *ipaddr);
static zend_string *_try_extract(const zval *server,
    zend_string *key, extract_func_t extract_func);
static bool _parse_x_forwarded_for(
    zend_string *value, ipaddr *out);
static bool _parse_plain(zend_string *zvalue, ipaddr *out);
static bool _parse_forwarded(zend_string *zvalue, ipaddr *out);
static bool _parse_via(zend_string *zvalue, ipaddr *out);

zend_string *dd_ip_extraction_find(zval *server)
{
    zend_string *res;

    res = _try_extract(server, _x_forwarded_for_key, &_parse_x_forwarded_for);
    if (res) {
        return res;
    }

    res = _try_extract(server, _x_real_ip_key, &_parse_plain);
    if (res) {
        return res;
    }

    res = _try_extract(server, _client_ip_key, &_parse_plain);
    if (res) {
        return res;
    }

    res = _try_extract(server, _x_forwarded_key, &_parse_forwarded);
    if (res) {
        return res;
    }

    res =
        _try_extract(server, _x_cluster_client_ip_key, &_parse_x_forwarded_for);
    if (res) {
        return res;
    }

    res = _try_extract(server, _forwarded_for_key, &_parse_x_forwarded_for);
    if (res) {
        return res;
    }

    res = _try_extract(server, _forwarded_key, &_parse_forwarded);
    if (res) {
        return res;
    }

    res = _try_extract(server, _via_key, &_parse_via);
    if (res) {
        return res;
    }

    res = _try_extract(server, _true_client_ip_key, &_parse_plain);
    if (res) {
        return res;
    }

    res = _try_extract(server, _remote_addr_key, &_parse_plain);
    if (res) {
        return res;
    }

    return NULL;
}

static zend_string *_try_extract(const zval *server,
    zend_string *key, extract_func_t extract_func)
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

static zend_string *_fetch_arr_str(
    const zval *server, zend_string *key)
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

static zend_string *_ipaddr_to_zstr(const ipaddr *ipaddr)
{
    char buf[INET6_ADDRSTRLEN];
    const char *res =
        inet_ntop(ipaddr->af, (char *)&ipaddr->v4, buf, sizeof(buf));
    if (!res) {
        ddtrace_log_debug("inet_ntop failed");
        return NULL;
    }
    return zend_string_init(res, strlen(res), 0);
}

static bool _parse_ip_address(
    const char *_addr, size_t addr_len, ipaddr *out);
static bool _parse_ip_address_maybe_port_pair(
    const char *addr, size_t addr_len, ipaddr *out);

static bool _parse_x_forwarded_for(
    zend_string *zvalue, ipaddr *out)
{
    const char *value = ZSTR_VAL(zvalue);
    const char *end = value + ZSTR_LEN(zvalue);
    bool succ;
    do {
        for (; value < end && *value == ' '; value++) {}
        const char *comma = memchr(value, ',', end - value);
        const char *end_cur = comma ? comma : end;
        succ = _parse_ip_address(value, end_cur - value, out);
        if (succ) {
            succ = !_is_private(out);
        }
        value = (comma && comma + 1 < end) ? (comma + 1) : NULL;
    } while (!succ && value);
    return succ;
}

static bool _parse_forwarded(zend_string *zvalue, ipaddr *out)
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

static const char *_skip_non_ws(
    const char *p, const char *end)
{
    for (; p < end && *p != ' ' && *p != '\t'; p++) {}
    return p;
}
static const char *_skip_ws(
    const char *p, const char *end)
{
    for (; p < end && (*p == ' ' || *p == '\t'); p++) {}
    return p;
}

static bool _parse_via(zend_string *zvalue, ipaddr *out)
{
    const char *p = ZSTR_VAL(zvalue);
    const char *end = p + ZSTR_LEN(zvalue);
    bool succ = false;
    do {
        const char *comma = memchr(p, ',', end - p);
        const char *end_cur = comma ? comma : end;

        // skip initial whitespace, after a comma separating several
        // values for instance
        p = _skip_ws(p, end_cur);
        if (p == end_cur) {
            goto try_next;
        }

        // https://httpwg.org/specs/rfc7230.html#header.via
        // skip over protocol/version
        p = _skip_non_ws(p, end_cur);
        p = _skip_ws(p, end_cur);
        if (p == end_cur) {
            goto try_next;
        }

        // we can have a trailing comment, so try find next whitespace
        end_cur = _skip_non_ws(p, end_cur);

        succ = _parse_ip_address_maybe_port_pair(p, end_cur - p, out);
        if (succ) {
            succ = !_is_private(out);
            if (succ) {
                return out;
            }
        }
    try_next:
        p = (comma && comma + 1 < end) ? (comma + 1) : NULL;
    } while (!succ && p);

    return succ;
}

static bool _parse_plain(zend_string *zvalue, ipaddr *out)
{
    return _parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), out) &&
           !_is_private(out);
}

static bool _parse_ip_address(
    const char *_addr, size_t addr_len, ipaddr *out)
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
            ddtrace_log_debugf("Not recognized as IP address: \"%s\"", addr);
            res = false;
            goto err;
        }

        uint8_t *s6addr = out->v6.s6_addr;
        static const uint8_t ip4_mapped_prefix[12] = {[10 ... 11] = 0xFF};
        if (memcmp(s6addr, ip4_mapped_prefix, sizeof(ip4_mapped_prefix)) == 0) {
            // IPv4 mapped
            ddtrace_log_debugf("Parsed as IPv4 mapped address: %s", addr);
            uint8_t s4addr[4];
            memcpy(s4addr, s6addr + sizeof(ip4_mapped_prefix), 4);
            memcpy(&out->v4.s_addr, s4addr, sizeof(s4addr));
            out->af = AF_INET;
        } else {
            ddtrace_log_debugf("Parsed as IPv6 address: %s", addr);
            out->af = AF_INET6;
        }
    } else {
        ddtrace_log_debugf("Parsed as IPv4 address: %s", addr);
        out->af = AF_INET;
    }

err:
    efree(addr);
    return res;
}

static bool _parse_ip_address_maybe_port_pair(
    const char *addr, size_t addr_len, ipaddr *out)
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
    if (colon) {
        return _parse_ip_address(addr, colon - addr, out);
    }

    return _parse_ip_address(addr, addr_len, out);
}

#define CT_HTONL(x)                                                            \
    ((((x) >> 24) & 0x000000FFU) | (((x) >> 8) & 0x0000FF00U) |                \
        (((x) << 8) & 0x00FF0000U) | (((x) << 24) & 0xFF000000U))

static bool _is_private_v4(const struct in_addr *addr)
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

static bool _is_private_v6(const struct in6_addr *addr)
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
            .base.s6_addr = {[15] = 1},
            .mask.s6_addr = {[0 ... 15] = 0xFF}, // /128
        },
        {
            .base.s6_addr = {0xFE, 0x80},
            .mask.s6_addr = {0xFF, 0xC0}, // /10
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

static bool _is_private(const ipaddr *addr)
{
    if (addr->af == AF_INET) {
        return _is_private_v4(&addr->v4);
    }
    return _is_private_v6(&addr->v6);
}
