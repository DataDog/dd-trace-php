#include <arpa/inet.h>
#include <netinet/in.h>
#include <php.h>
#include <string.h>
#include <zend_API.h>
#include <zend_smart_str.h>

#include "compatibility.h"
#include "configuration.h"
#include <components/log/log.h>

#define ARRAY_SIZE(x) (sizeof(x) / sizeof((x)[0]))

typedef struct _ipaddr {
    int af;
    union {
        struct in_addr v4;
        struct in6_addr v6;
    };
} ipaddr;

typedef bool (*extract_func_t)(zend_string *value, ipaddr *out);

static bool dd_parse_x_forwarded_for(zend_string *value, ipaddr *out);
static bool dd_parse_plain(zend_string *zvalue, ipaddr *out);
static bool dd_parse_plain_raw(zend_string *zvalue, ipaddr *out);
static bool dd_parse_forwarded(zend_string *zvalue, ipaddr *out);

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
static zend_string *remote_addr_key;

static zend_string *dd_fetch_arr_str(const zval *server, zend_string *key);
static bool dd_is_private(const ipaddr *addr);
static zend_string *dd_ipaddr_to_zstr(const ipaddr *ipaddr);
static zend_string *dd_try_extract(const zval *server, zend_string *key, extract_func_t extract_func);
static zend_string *dd_try_extract_ip_from_custom_header(const zval *server, zend_string *ipheader);

void dd_ip_extraction_startup() {
    priority_header_map[X_FORWARDED_FOR] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED_FOR"), 1),
                                                             zend_string_init_interned(ZEND_STRL("x-forwarded-for"), 1), &dd_parse_x_forwarded_for};
    priority_header_map[X_REAL_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_REAL_IP"), 1),
                                                       zend_string_init_interned(ZEND_STRL("x-real-ip"), 1), &dd_parse_plain};
    priority_header_map[TRUE_CLIENT_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_TRUE_CLIENT_IP"), 1),
                                                            zend_string_init_interned(ZEND_STRL("true-client-ip"), 1), &dd_parse_plain};
    priority_header_map[X_CLIENT_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_CLIENT_IP"), 1),
                                                         zend_string_init_interned(ZEND_STRL("x-client-ip"), 1), &dd_parse_plain};
    priority_header_map[X_FORWARDED] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_FORWARDED"), 1),
                                                         zend_string_init_interned(ZEND_STRL("x-forwarded"), 1), &dd_parse_forwarded};
    priority_header_map[FORWARDED_FOR] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_FORWARDED_FOR"), 1),
                                                           zend_string_init_interned(ZEND_STRL("forwarded-for"), 1), &dd_parse_x_forwarded_for};
    priority_header_map[X_CLUSTER_CLIENT_IP] =
        (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_X_CLUSTER_CLIENT_IP"), 1),
                          zend_string_init_interned(ZEND_STRL("x-cluster-client-ip"), 1), &dd_parse_x_forwarded_for};
    priority_header_map[FASTLY_CLIENT_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_FASTLY_CLIENT_IP"), 1),
                                                              zend_string_init_interned(ZEND_STRL("fastly-client-ip"), 1), &dd_parse_plain};
    priority_header_map[CF_CONNECTING_IP] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IP"), 1),
                                                              zend_string_init_interned(ZEND_STRL("cf-connecting-ip"), 1), &dd_parse_plain};
    priority_header_map[CF_CONNECTING_IPV6] = (header_map_node){zend_string_init_interned(ZEND_STRL("HTTP_CF_CONNECTING_IPV6"), 1),
                                                                zend_string_init_interned(ZEND_STRL("cf-connecting-ipv6"), 1), &dd_parse_plain};

    remote_addr_key = zend_string_init_interned(ZEND_STRL("REMOTE_ADDR"), 1);
}

static zend_string *dd_get_ipheader(zend_string *value) {
    if (!value || !ZSTR_VAL(value)[0]) {
        return NULL;
    }

    size_t key_len = (sizeof("HTTP_") - 1) + ZSTR_LEN(value);
    zend_string *normalized_value = zend_string_alloc(key_len, 0);
    char *out = ZSTR_VAL(normalized_value);
    memcpy(out, ZEND_STRL("HTTP_"));
    out += (sizeof("HTTP_") - 1);
    const char *end = ZSTR_VAL(value) + ZSTR_LEN(value);
    for (const char *p = ZSTR_VAL(value); p != end; p++) {
        char c = *p;
        if (c >= 'a' && c <= 'z') {
            c = (char)(c - 'a' + 'A');
        } else if (c == '-') {
            c = '_';
        }
        *out++ = (char)c;
    }
    *out = '\0';

    return normalized_value;
}

void ddtrace_extract_ip_from_headers(zval *server, zend_array *meta) {
    zend_string *client_ip = NULL;

    zend_string *ipheader = dd_get_ipheader(get_DD_TRACE_CLIENT_IP_HEADER());
    if (ipheader) {
        client_ip = dd_try_extract_ip_from_custom_header(server, ipheader);
        if (!client_ip) {
            client_ip = dd_try_extract_ip_from_custom_header(server, get_DD_TRACE_CLIENT_IP_HEADER());
        }
        zend_string_release(ipheader);
    } else {
        for (unsigned i = 0; i < ARRAY_SIZE(priority_header_map); i++) {
            zval *val = zend_hash_find(Z_ARR_P(server), priority_header_map[i].key);
            if (!val) {
                val = zend_hash_find(Z_ARR_P(server), priority_header_map[i].name);
            }
            if (val && Z_TYPE_P(val) == IS_STRING && Z_STRLEN_P(val) > 0) {
                zend_string *headertag = zend_strpprintf(0, "http.request.headers.%s", ZSTR_VAL(priority_header_map[i].name));
                zval headerzv;
                ZVAL_STR_COPY(&headerzv, Z_STR_P(val));
                zend_hash_update(meta, headertag, &headerzv);
                zend_string_release(headertag);

                if (!client_ip) {
                    // We pick the most priority header only
                    ipaddr out;
                    client_ip = priority_header_map[i].parse_fn(Z_STR_P(val), &out) ? dd_ipaddr_to_zstr(&out) : NULL;
                }
            }
        }
        // We didn't find any valid IPs, extract from remote_addr
        if (client_ip == NULL) {
            client_ip = dd_try_extract(server, remote_addr_key, dd_parse_plain_raw);
        }
    }

    if (client_ip != NULL) {
        zval http_client_ip;
        ZVAL_STR(&http_client_ip, client_ip);
        zend_hash_str_add_new(meta, "http.client_ip", sizeof("http.client_ip") - 1, &http_client_ip);
    }
}

static zend_string *dd_try_extract_ip_from_custom_header(const zval *server, zend_string *ipheader) {
    zend_string *value = dd_fetch_arr_str(server, ipheader);
    if (value) {
        ipaddr out;
        bool succ;
        succ = dd_parse_forwarded(value, &out);
        if (succ) {
            return dd_ipaddr_to_zstr(&out);
        }

        succ = dd_parse_x_forwarded_for(value, &out);
        if (succ) {
            return dd_ipaddr_to_zstr(&out);
        }
    }

    LOG(Warn, "No available IP from header '%.*s'", ZSTR_LEN(ipheader), ZSTR_VAL(ipheader));

    return NULL;
}

static zend_string *dd_try_extract(const zval *server, zend_string *key, extract_func_t extract_func) {
    zend_string *value = dd_fetch_arr_str(server, key);
    if (!value) {
        return NULL;
    }
    ipaddr out;
    if ((*extract_func)(value, &out)) {
        return dd_ipaddr_to_zstr(&out);
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

static zend_string *dd_ipaddr_to_zstr(const ipaddr *ipaddr) {
    char buf[INET6_ADDRSTRLEN];
    const char *res = inet_ntop(ipaddr->af, (char *)&ipaddr->v4, buf, sizeof(buf));
    if (!res) {
        LOG(Warn, "inet_ntop failed");
        return NULL;
    }
    return zend_string_init(res, strlen(res), 0);
}

static bool dd_parse_ip_address(const char *_addr, size_t addr_len, bool ip_or_error, ipaddr *out);
static bool dd_parse_ip_address_maybe_port_pair(const char *addr, size_t addr_len, bool ip_or_error, ipaddr *out);

static bool dd_parse_x_forwarded_for(zend_string *zvalue, ipaddr *out) {
    const char *value = ZSTR_VAL(zvalue);
    const char *end = value + ZSTR_LEN(zvalue);
    bool succ;
    do {
        while (value < end && *value == ' ') ++value;
        const char *comma = memchr(value, ',', end - value);
        const char *end_cur = comma ? comma : end;
        succ = dd_parse_ip_address_maybe_port_pair(value, end_cur - value, true, out);
        if (succ) {
            succ = !dd_is_private(out);
        }
        value = (comma && comma + 1 < end) ? (comma + 1) : NULL;
    } while (!succ && value);
    return succ;
}

static bool dd_parse_forwarded(zend_string *zvalue, ipaddr *out) {
    enum {
        between,
        key,
        before_value,
        value_token,
        value_quoted,
    } state = between;
    const char *r = ZSTR_VAL(zvalue);
    const char *end = r + ZSTR_LEN(zvalue);
    const char *start = NULL;
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
                    bool succ = dd_parse_ip_address_maybe_port_pair(start, token_end - start, true, out);
                    if (succ && !dd_is_private(out)) {
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
                        bool succ = dd_parse_ip_address_maybe_port_pair(start, r - start, true, out);
                        if (succ && !dd_is_private(out)) {
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

static bool dd_parse_plain(zend_string *zvalue, ipaddr *out) {
    return dd_parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), true, out) && !dd_is_private(out);
}

static bool dd_parse_plain_raw(zend_string *zvalue, ipaddr *out) { return dd_parse_ip_address(ZSTR_VAL(zvalue), ZSTR_LEN(zvalue), true, out); }

static bool dd_parse_ip_address(const char *_addr, size_t addr_len, bool ip_or_error, ipaddr *out) {
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
            if (ip_or_error) {
                LOG(Error, "Not recognized as IP address: \"%s\"", addr);
            }
            res = false;
            goto err;
        }

        uint8_t *s6addr = out->v6.s6_addr;
        static const uint8_t ip4_mapped_prefix[12] = {[10 ... 11] = 0xFF};
        if (memcmp(s6addr, ip4_mapped_prefix, sizeof(ip4_mapped_prefix)) == 0) {
            // IPv4 mapped
            LOG(Warn, "Parsed as IPv4 mapped address: %s", addr);
            memcpy(&out->v4.s_addr, s6addr + sizeof(ip4_mapped_prefix), 4);
            out->af = AF_INET;
        } else {
            LOG(Warn, "Parsed as IPv6 address: %s", addr);
            out->af = AF_INET6;
        }
    } else {
        LOG(Warn, "Parsed as IPv4 address: %s", addr);
        out->af = AF_INET;
    }

err:
    efree(addr);
    return res;
}

static bool dd_parse_ip_address_maybe_port_pair(const char *addr, size_t addr_len, bool ip_or_error, ipaddr *out) {
    if (addr_len == 0) {
        return false;
    }
    if (addr[0] == '[') {  // ipv6
        const char *pos_close = memchr(addr + 1, ']', addr_len - 1);
        if (!pos_close) {
            return false;
        }
        return dd_parse_ip_address(addr + 1, pos_close - (addr + 1), ip_or_error, out);
    }

    const char *colon = memchr(addr, ':', addr_len);
    if (colon && zend_memrchr(addr, ':', addr_len) == colon) {  // There is one and only one colon
        return dd_parse_ip_address(addr, colon - addr, ip_or_error, out);
    }

    return dd_parse_ip_address(addr, addr_len, ip_or_error, out);
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
    };

    for (unsigned i = 0; i < ARRAY_SIZE(priv_ranges); i++) {
        __auto_type range = priv_ranges[i];
        if ((addr->s_addr & range.mask.s_addr) == range.base.s_addr) {
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
