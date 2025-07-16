#ifndef _WIN32
#include <arpa/inet.h>
#include <components/log/log.h>
#include <netinet/in.h>
#endif
#include <php.h>
#include <string.h>
#include <zend_API.h>
#include <zend_smart_str.h>

#include "compatibility.h"
#include "configuration.h"

#define ARRAY_SIZE(x) (sizeof(x) / sizeof((x)[0]))

typedef struct _ipaddr {
    int af;
    union {
        struct in_addr v4;
        struct in6_addr v6;
    };
} ipaddr;
static inline bool dd_is_ipaddr_defined(const ipaddr *addr) { return addr->af == AF_INET || addr->af == AF_INET6; }

struct extract_res {
    bool success;
    bool is_private;
};
#define EXTRACT_SUCCESS_PUBLIC \
    (struct extract_res) { .success = true, .is_private = false }
#define EXTRACT_SUCCESS_PRIVATE \
    (struct extract_res) { .success = true, .is_private = true }
#define EXTRACT_FAILURE \
    (struct extract_res) { .success = false }
typedef struct extract_res (*extract_func_t)(zend_string *value, ipaddr *out);

typedef struct _header_map_node {
    zend_string *key;
    zend_string *name;
    extract_func_t parse_fn;
} header_map_node;

typedef enum _priority_header_id {
    X_FORWARDED_FOR = 0,
    X_REAL_IP,
    FORWARDED,
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

static zend_string *_remote_addr_key;

static bool dd_parse_ip_address(const char *_addr, size_t addr_len, ipaddr *out);
static bool dd_parse_ip_address_maybe_port_pair(const char *addr, size_t addr_len, ipaddr *out);
static zend_string *dd_fetch_arr_str(const zval *server, zend_string *key);
static bool dd_is_private(const ipaddr *addr);
static zend_string *dd_ipaddr_to_zstr(const ipaddr *ipaddr);
static struct extract_res dd_parse_multiple_maybe_port(zend_string *value, ipaddr *out);
static struct extract_res dd_parse_plain(zend_string *zvalue, ipaddr *out);
static struct extract_res dd_parse_forwarded(zend_string *zvalue, ipaddr *out);

void dd_ip_extraction_startup() {
    _remote_addr_key = zend_string_init_interned(ZEND_STRL("REMOTE_ADDR"), 1);

    priority_header_map[X_FORWARDED_FOR] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED_FOR"), 1),
                                                             zend_string_init_interned(ZEND_STRL("x-forwarded-for"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[X_REAL_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_REAL_IP"), 1),
                                                       zend_string_init_interned(ZEND_STRL("x-real-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[FORWARDED] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED"), 1),
                                                        zend_string_init_interned(ZEND_STRL("forwarded"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[TRUE_CLIENT_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_TRUE_CLIENT_IP"), 1),
                                                            zend_string_init_interned(ZEND_STRL("true-client-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[X_CLIENT_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_CLIENT_IP"), 1),
                                                         zend_string_init_interned(ZEND_STRL("x-client-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[X_FORWARDED] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED"), 1),
                                                         zend_string_init_interned(ZEND_STRL("x-forwarded"), 1), &dd_parse_forwarded};
    priority_header_map[FORWARDED_FOR] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED_FOR"), 1),
                                                           zend_string_init_interned(ZEND_STRL("forwarded-for"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[X_CLUSTER_CLIENT_IP] =
        (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_CLUSTER_CLIENT_IP"), 1),
                          zend_string_init_interned(ZEND_STRL("x-cluster-client-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[FASTLY_CLIENT_IP] =
        (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_FASTLY_CLIENT_IP"), 1),
                          zend_string_init_interned(ZEND_STRL("fastly-client-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[CF_CONNECTING_IP] =
        (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IP"), 1),
                          zend_string_init_interned(ZEND_STRL("cf-connecting-ip"), 1), &dd_parse_multiple_maybe_port};
    priority_header_map[CF_CONNECTING_IPV6] =
        (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IPV6"), 1),
                          zend_string_init_interned(ZEND_STRL("cf-connecting-ipv6"), 1), &dd_parse_multiple_maybe_port};
}

bool ddtrace_parse_client_ip_header_config(zai_str value, zval *decoded_value, bool persistent) {
    if (!value.ptr[0]) {
        if (persistent) {
            ZVAL_EMPTY_PSTRING(decoded_value);
        } else {
            ZVAL_EMPTY_STRING(decoded_value);
        }
        return true;
    }

    size_t key_len = (sizeof("HTTP_") - 1) + value.len;

    ZVAL_STR(decoded_value, zend_string_alloc(key_len, persistent));
    char *out = Z_STRVAL_P(decoded_value);
    memcpy(out, ZEND_STRL("HTTP_"));
    out += sizeof("HTTP_") - 1;
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

DDTRACE_PUBLIC zend_string *ddtrace_ip_extraction_find(zval *server) {
    if (!server || Z_TYPE_P(server) != IS_ARRAY) {
        return NULL;
    }

    // Extract ip from define customer header
    zend_string *ipheader = get_DD_TRACE_CLIENT_IP_HEADER();
    if (ipheader && ZSTR_LEN(ipheader) > 0) {
        zend_string *value = dd_fetch_arr_str(server, ipheader);
        if (!value) {
            return NULL;
        }

        ipaddr out;
        struct extract_res res = dd_parse_forwarded(value, &out);
        if (res.success) {
            return dd_ipaddr_to_zstr(&out);
        }

        res = dd_parse_multiple_maybe_port(value, &out);
        if (res.success) {
            return dd_ipaddr_to_zstr(&out);
        }

        return NULL;
    }

    // Lets get the ip on the first header found
    ipaddr cur_private = {0};
    for (unsigned i = 0; i < ARRAY_SIZE(priority_header_map); i++) {
        zval *val = zend_hash_find(Z_ARR_P(server), priority_header_map[i].key);
        if (val && Z_TYPE_P(val) == IS_STRING && Z_STRLEN_P(val) > 0) {
            ipaddr out;
            struct extract_res res = (priority_header_map[i].parse_fn)(Z_STR_P(val), &out);
            if (res.success) {
                if (!res.is_private) {
                    return dd_ipaddr_to_zstr(&out);
                }
                if (!dd_is_ipaddr_defined(&cur_private)) {
                    memcpy(&cur_private, &out, sizeof cur_private);
                }
            }
        }
    }

    // Try remote_addr. If it's public we'll use it
    zend_string *value = dd_fetch_arr_str(server, _remote_addr_key);
    if (value) {
        ipaddr out;
        struct extract_res res = dd_parse_plain(value, &out);
        if (res.success) {
            if (!res.is_private) {
                return dd_ipaddr_to_zstr(&out);
            }

            // if it's private, we'll only use it we didn't find a private one
            // in the headers
            if (!dd_is_ipaddr_defined(&cur_private)) {
                return dd_ipaddr_to_zstr(&out);
            }
        }
    }

    if (dd_is_ipaddr_defined(&cur_private)) {
        return dd_ipaddr_to_zstr(&cur_private);
    }
    return NULL;
}

static zend_string *dd_fetch_arr_str(const zval *server, zend_string *key) {
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

void ddtrace_extract_ip_from_headers(zval *server, zend_array *meta)
{
    zend_string *val = ddtrace_ip_extraction_find(server);
    if (!val) {
        return;
    }
    zval http_client_ip;
    ZVAL_STR(&http_client_ip, val);
    zend_hash_str_update(meta, ZEND_STRL("http.client_ip"), &http_client_ip);
}

static zend_string *dd_ipaddr_to_zstr(const ipaddr *ipaddr) {
    char buf[INET6_ADDRSTRLEN];
    const char *res = inet_ntop(ipaddr->af, (char *)&ipaddr->v4, buf, sizeof(buf));
    if (!res) {
        return NULL;
    }
    return zend_string_init(res, strlen(res), 0);
}

static struct extract_res dd_parse_multiple_maybe_port(zend_string *zvalue, ipaddr *out) {
    const char *value = ZSTR_VAL(zvalue);
    const char *end = value + ZSTR_LEN(zvalue);
    ipaddr first_private = {0};
    do {
        for (; value < end && *value == ' '; value++) {
        }
        if (end - value < 0) {
            ZEND_UNREACHABLE();
        }
        const char *comma = memchr(value, ',', end - value);
        const char *end_cur = comma ? comma : end;
        ipaddr cur;
        bool succ = dd_parse_ip_address_maybe_port_pair(value, end_cur - value, &cur);
        if (succ) {
            if (!dd_is_private(&cur)) {
                memcpy(out, &cur, sizeof *out);
                return EXTRACT_SUCCESS_PUBLIC;
            }
            if (!dd_is_ipaddr_defined(&first_private)) {
                memcpy(&first_private, &cur, sizeof first_private);
            }
        }
        value = (comma && comma + 1 < end) ? (comma + 1) : NULL;
    } while (value);

    if (dd_is_ipaddr_defined(&first_private)) {
        memcpy(out, &first_private, sizeof *out);
        return EXTRACT_SUCCESS_PRIVATE;
    }
    return EXTRACT_FAILURE;
}

static struct extract_res dd_parse_forwarded(zend_string *zvalue, ipaddr *out) {
    ipaddr first_private = {0};
    enum {
        between,
        key,
        before_value,
        value_token,
        value_quoted,
    } state = between;
    const char *r = ZSTR_VAL(zvalue);
    const char *end = r + ZSTR_LEN(zvalue);
    const char *start = r;  // meaningless assignment to satisfy static analysis
    bool consider_value = false;

    // https://datatracker.ietf.org/doc/html/rfc7239#section-4
    // we parse with some leniency
    while (r < end) {
        switch (state) {  // NOLINT
            case between:
                if (*r == ' ' || *r == ';' || *r == ',') {
                    break;
                }
                start = r;
                state = key;
                break;
            case key:
                if (*r == '=') {
                    consider_value = (r - start == 3) && (start[0] == 'f' || start[0] == 'F') && (start[1] == 'o' || start[1] == 'O') &&
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
                    ipaddr cur = {0};
                    bool succ = dd_parse_ip_address_maybe_port_pair(start, token_end - start, &cur);
                    if (succ && !dd_is_private(&cur)) {
                        memcpy(out, &cur, sizeof *out);
                        return EXTRACT_SUCCESS_PUBLIC;
                    }
                    if (succ && dd_is_private(&cur) && !dd_is_ipaddr_defined(&first_private)) {
                        memcpy(&first_private, &cur, sizeof first_private);
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
                        ipaddr cur;
                        bool succ = dd_parse_ip_address_maybe_port_pair(start, r - start, &cur);
                        if (succ && !dd_is_private(&cur)) {
                            memcpy(out, &cur, sizeof *out);
                            return EXTRACT_SUCCESS_PUBLIC;
                        }
                        if (succ && dd_is_private(&cur) && !dd_is_ipaddr_defined(&first_private)) {
                            memcpy(&first_private, &cur, sizeof first_private);
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

    if (dd_is_ipaddr_defined(&first_private)) {
        memcpy(out, &first_private, sizeof *out);
        return EXTRACT_SUCCESS_PRIVATE;
    }
    return EXTRACT_FAILURE;
}

static struct extract_res dd_parse_plain(zend_string *zvalue, ipaddr *out) {
    bool ok = dd_parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), out);
    return (struct extract_res){.success = ok, .is_private = ok && dd_is_private(out)};
}

static bool dd_parse_ip_address(const char *_addr, size_t addr_len, ipaddr *out) {
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
            res = false;
            goto err;
        }

        uint8_t *s6addr = out->v6.s6_addr;
        static const uint8_t ip4_mapped_prefix[12] = {[10] = 0xFF, [11] = 0xFF};
        if (memcmp(s6addr, ip4_mapped_prefix, sizeof(ip4_mapped_prefix)) == 0) {
            // IPv4 mapped
            memcpy(&out->v4.s_addr, s6addr + sizeof(ip4_mapped_prefix), 4);
            out->af = AF_INET;
        } else {
            out->af = AF_INET6;
        }
    } else {
        out->af = AF_INET;
    }

err:
    efree(addr);
    return res;
}

static bool dd_parse_ip_address_maybe_port_pair(const char *addr, size_t addr_len, ipaddr *out) {
    if (addr_len == 0) {
        return false;
    }
    if (addr[0] == '[') {  // ipv6
        const char *pos_close = memchr(addr + 1, ']', addr_len - 1);
        if (!pos_close) {
            return false;
        }
        return dd_parse_ip_address(addr + 1, pos_close - (addr + 1), out);
    }
    const char *colon = memchr(addr, ':', addr_len);
    if (colon && zend_memrchr(addr, ':', addr_len) == colon) {
        return dd_parse_ip_address(addr, colon - addr, out);
    }

    return dd_parse_ip_address(addr, addr_len, out);
}

#define CT_HTONL(x) ((((x) >> 24) & 0x000000FFU) | (((x) >> 8) & 0x0000FF00U) | (((x) << 8) & 0x00FF0000U) | (((x) << 24) & 0xFF000000U))

static bool dd_is_private_v4(const struct in_addr *addr) {
    static const struct {
        struct in_addr base;
        struct in_addr mask;
    } priv_ranges[] = {
        {
            .base.s_addr = CT_HTONL(0x0A000000U),  // 10.0.0.0
            .mask.s_addr = CT_HTONL(0xFF000000U),  // 255.0.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xAC100000U),  // 172.16.0.0
            .mask.s_addr = CT_HTONL(0xFFF00000U),  // 255.240.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xC0A80000U),  // 192.168.0.0
            .mask.s_addr = CT_HTONL(0xFFFF0000U),  // 255.255.0.0
        },
        {
            .base.s_addr = CT_HTONL(0x7F000000U),  // 127.0.0.0
            .mask.s_addr = CT_HTONL(0xFF000000U),  // 255.0.0.0
        },
        {
            .base.s_addr = CT_HTONL(0xA9FE0000U),  // 169.254.0.0
            .mask.s_addr = CT_HTONL(0xFFFF0000U),  // 255.255.0.0
        },
        {
            .base.s_addr = CT_HTONL(0x64400000U),  // 100.64.0.0 (RFC6598, CGNAT, K8s pod IPs)
            .mask.s_addr = CT_HTONL(0xFFC00000U),  // 255.192.0.0 (/10)
        },
    };

    for (unsigned i = 0; i < ARRAY_SIZE(priv_ranges); i++) {
        if ((addr->s_addr & priv_ranges[i].mask.s_addr) == priv_ranges[i].base.s_addr) {
            return true;
        }
    }
    return false;
}

static bool dd_is_private_v6(const struct in6_addr *addr) {
    // clang-format off
    static const struct {
        union {
            struct in6_addr base;
            uint64_t base_i[2];
        };
        union {
            struct in6_addr mask;
            uint64_t mask_i[2];
        };
    } priv_ranges[] = {
        {
            .base.s6_addr = {[15] = 1}, // loopback
            .mask.s6_addr = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF}, // /128
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

    uint64_t addr_i[2];
    memcpy(&addr_i[0], addr->s6_addr, sizeof(addr_i));

    for (unsigned i = 0; i < ARRAY_SIZE(priv_ranges); i++) {
        if ((addr_i[0] & priv_ranges[i].mask_i[0]) == priv_ranges[i].base_i[0] &&
            (addr_i[1] & priv_ranges[i].mask_i[1]) == priv_ranges[i].base_i[1]) {
            return true;
        }
    }
    return false;
}

static bool dd_is_private(const ipaddr *addr) {
    if (addr->af == AF_INET) {
        return dd_is_private_v4(&addr->v4);
    }
    return dd_is_private_v6(&addr->v6);
}
