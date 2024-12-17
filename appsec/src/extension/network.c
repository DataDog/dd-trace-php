// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// In some cases it seems as though php.h already defines GNU_SOURCE

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>

#include <errno.h>
#include <fcntl.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <sys/uio.h>
#include <sys/un.h>
#include <time.h>

#define HELPER_PROCESS_C_INCLUDES
#include "ddappsec.h"
#include "dddefs.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"

struct PACKED _dd_header { // NOLINT
    char code[4];          // dds\0
    uint32_t size;
};

typedef struct PACKED _dd_header dd_header;

static const int CONNECT_TIMEOUT = 2500;    // ms
static const int CONNECT_RETRY_PAUSE = 100; // ms
static const uint32_t MAX_RECV_MESSAGE_SIZE = 4 * 1024 * 1024;

static void _timespec_add_ms(struct timespec *ts, long num_ms);
static long _timespec_delta_ms(struct timespec *ts1, struct timespec *ts2);

int dd_conn_init( // NOLINT(readability-function-cognitive-complexity)
    dd_conn *nonnull conn, const char *nonnull path, size_t path_len)
{
    if (path_len > sizeof(conn->addr.sun_path) - 1) {
        mlog(dd_log_error, "Socket path is too long");
        return dd_error;
    }

    // NOLINTNEXTLINE(android-cloexec-socket)
    int res = conn->socket = socket(AF_UNIX, SOCK_STREAM, 0);

    if (res == -1) {
        mlog_err(dd_log_error, "Error creating unix socket");
        return dd_error;
    }

    conn->addr.sun_family = AF_UNIX;

    // NOLINTNEXTLINE
    strncpy(conn->addr.sun_path, path, sizeof(conn->addr.sun_path) - 1);
    conn->addr.sun_path[sizeof(conn->addr.sun_path) - 1] = '\0';

    int flags_before = fcntl(conn->socket, F_GETFL, 0);
    if (flags_before == -1) {
        res = -1;
    } else {
        res = fcntl(conn->socket, F_SETFL, O_NONBLOCK);
    }
    if (res == -1) {
        dd_conn_destroy(conn);
        mlog(dd_log_error, "Failed to set socket to non-blocking mode");
        return dd_error;
    }

    mlog(dd_log_info, "Attempting to connect to UNIX socket %s", path);
    struct timespec deadline;
    clock_gettime(CLOCK_MONOTONIC, &deadline);
    _timespec_add_ms(&deadline, CONNECT_TIMEOUT);

try_again:
    res = connect(
        conn->socket, (struct sockaddr *)&conn->addr, sizeof(conn->addr));
    if (res == -1) {
        int errno_copy = errno;
        if (errno_copy == EINPROGRESS) {
            struct pollfd pfds[] = {
                {.fd = conn->socket, .events = POLLIN | POLLOUT}};
            struct timespec now;
            clock_gettime(CLOCK_MONOTONIC, &now);
            long time_left = _timespec_delta_ms(&deadline, &now);
            if (time_left <= 0) {
                dd_conn_destroy(conn);
                mlog(dd_log_info, "Connection to helper timed out");
                return dd_error;
            }

            res = poll(pfds, 1, (int)time_left);
            if (res == 0) {
                dd_conn_destroy(conn);
                mlog(dd_log_info, "Connection to helper timed out");
                return dd_error;
            }
            if (res == -1) {
                dd_conn_destroy(conn);
                mlog_err(
                    dd_log_info, "Error in connection to helper (poll() call)");
                return dd_error;
            }
            if (pfds[0].revents & POLLERR) { // NOLINT
                dd_conn_destroy(conn);
                mlog_err(
                    dd_log_info, "Error in connection to helper (POLLERR)");
                return dd_error;
            }
            if (pfds[0].revents & POLLHUP) {
                dd_conn_destroy(conn);
                mlog_err(
                    dd_log_info, "Error in connection to helper (POLLHUP)");
                return dd_error;
            }
            // else good
        } else {
            if (errno_copy == ENOENT || errno_copy == ECONNREFUSED) {
                // the socket does not exist or is not being listened on. Retry
                struct timespec now;
                clock_gettime(CLOCK_MONOTONIC, &now);
                long time_left = _timespec_delta_ms(&deadline, &now);
                if (time_left <= 0) {
                    dd_conn_destroy(conn);
                    mlog(dd_log_info, "Connection to helper timed out");
                    return dd_error;
                }

                mlog(dd_log_debug, "Socket %s.  Waiting %d ms for next retry",
                    errno_copy == ENOENT ? "does not exist"
                                         : "is not being listened on",
                    CONNECT_RETRY_PAUSE);
                int ret = usleep(CONNECT_RETRY_PAUSE * 1000); // NOLINT
                if (ret == 0 || errno == EINTR) {
                    goto try_again;
                } else {
                    mlog_err(dd_log_warning,
                        "Failed connecting to helper (usleep())");
                }
            }

            dd_conn_destroy(conn);
            errno = errno_copy; // restore for mlog_err
            mlog_err(
                dd_log_info, "Failed connecting to helper (connect() call)");
            return dd_error;
        }
    }

    fcntl(conn->socket, F_SETFL, flags_before & ~O_NONBLOCK);

    // no guarantee of accept() on the other side though
    mlog(dd_log_info, "connect() to helper socket succeeded");
    return dd_success;
}

static size_t _iovecs_total_size(zend_llist *nonnull iovecs)
{
    zend_llist_position pos;
    size_t total = 0;
    for (struct iovec *iov = zend_llist_get_first_ex(iovecs, &pos); iov;
         iov = zend_llist_get_next_ex(iovecs, &pos)) {
        total += iov->iov_len;
    }
    return total;
}

dd_result dd_conn_sendv(dd_conn *nonnull conn, zend_llist *nonnull iovecs)
{
    size_t data_len = _iovecs_total_size(iovecs);
    size_t iovecs_count = zend_llist_count(iovecs);

    if (!dd_conn_connected(conn) || data_len > SSIZE_MAX - sizeof(dd_header) ||
        iovecs_count > INT_MAX - 1) {
        return dd_error;
    }

    dd_header h = {"dds", data_len};
    struct iovec *iovs =
        safe_emalloc(iovecs_count, sizeof(*iovs), sizeof(struct iovec));
    iovs[0].iov_base = &h;
    iovs[0].iov_len = sizeof(h);

    zend_llist_position pos;
    size_t i = 1;
    for (struct iovec *iov = zend_llist_get_first_ex(iovecs, &pos); iov;
         iov = zend_llist_get_next_ex(iovecs, &pos), i++) {
        iovs[i] = *iov;
    }

    size_t total = sizeof(dd_header) + data_len;
    mlog_g(dd_log_debug, "About to send %zu + %zu bytes to helper",
        sizeof(dd_header), data_len);

    ssize_t sent_bytes = writev(conn->socket, iovs, (int)iovecs_count + 1);
    efree(iovs);
    if (sent_bytes == -1) {
        mlog_err(dd_log_info, "Error writing %zu bytes to helper", total);
        return dd_network;
    }
    mlog_g(dd_log_debug, "Wrote %zu bytes", (size_t)sent_bytes);

    if ((size_t)sent_bytes != total) {
        mlog(dd_log_info,
            "Could not send the desired number of bytes. Total sent was %zu, "
            "wanted %zu",
            (size_t)sent_bytes, total);
        return dd_network;
    }

    return dd_success;
}
#ifdef SO_PASSCRED
dd_result dd_conn_sendv_cred(dd_conn *nonnull conn, zend_llist *nonnull iovecs)
{
    // set SO_PASSCRED before sending the message. This is to try to
    // ensure that the helper does not send a response ahead of our having
    // had the chance to set SO_PASSCRED before calling recvmsg(), resulting in
    // the credentials received having the overflowuid
    int res = setsockopt(
        conn->socket, SOL_SOCKET, SO_PASSCRED, &(int){1}, sizeof(int));
    if (res == -1) {
        mlog_err(
            dd_log_warning, "Call to setsockopt to get credentials failed");
        return dd_error;
    }

    return dd_conn_sendv(conn, iovecs);
}
#else // no SO_PASSCRED
dd_result dd_conn_sendv_cred(dd_conn *nonnull conn, zend_llist *nonnull iovecs)
{
    return dd_conn_sendv(conn, iovecs);
}
#endif

static dd_result _recv_message_body(int sock, char *nullable *nonnull data,
    size_t *nonnull data_len, size_t expected_size);
dd_result dd_conn_recv(dd_conn *nonnull conn, char *nullable *nonnull data,
    size_t *nonnull data_len)
{
    if (conn == NULL || conn->socket <= 0 || data == NULL) {
        return dd_error;
    }

    dd_header h;
    ssize_t recv_bytes = recv(conn->socket, (void *)&h, sizeof(dd_header), 0);
    if (recv_bytes == -1) {
        mlog_err(dd_log_info, "Error receiving the header");
        return dd_network;
    }
    if (recv_bytes != sizeof(dd_header)) {
        mlog(dd_log_info, "Could not read the full header. Read %zd",
            recv_bytes);
        return dd_network;
    }

    if (strncmp(h.code, "dds", 3) != 0) {
        mlog(dd_log_warning, "Invalid message header from helper");
        // to force the connection closed. It may be we half-read a previous
        // message, so a reconnection can help
        return dd_network;
    }
    // size is in machine order
    if (h.size > MAX_RECV_MESSAGE_SIZE) {
        mlog(dd_log_warning,
            "Rejecting helper message with size %" PRIu32
            " larger than max %" PRIu32,
            h.size, MAX_RECV_MESSAGE_SIZE);
        return dd_network; // force reconnect, we don't want to read it all
    }

    return _recv_message_body(conn->socket, data, data_len, h.size);
}

static dd_result _recv_message_body(int sock, char *nullable *nonnull data,
    size_t *nonnull data_len, size_t expected_size)
{
    char *buffer = malloc(expected_size);
    if (!buffer) {
        return dd_error;
    }
    *data = buffer;
    *data_len = expected_size;

    size_t remaining_bytes = expected_size;
    mlog(dd_log_debug, "Will receive message body. Expected size: %zu",
        expected_size);
    while (remaining_bytes > 0) {
        ssize_t recv_bytes = recv(sock, buffer, remaining_bytes, 0);
        if (recv_bytes == -1) {
            mlog_err(dd_log_info, "Error receiving the body of a message");
            goto error;
        }
        if (recv_bytes == 0) {
            mlog(dd_log_info,
                "recv() call yielded no data. Total received %zu out of %zu",
                expected_size - remaining_bytes, expected_size);
            goto error;
        }

        buffer += recv_bytes;
        remaining_bytes -= recv_bytes;
    }
    mlog(dd_log_debug, "Got full response. Size %zu", expected_size);

    return dd_success;
error:
    free(buffer);
    return dd_network;
}

#ifdef SO_PASSCRED
static dd_result _check_credentials(struct cmsghdr *cmsgp);
dd_result dd_conn_recv_cred(dd_conn *nonnull conn, char *nullable *nonnull data,
    size_t *nonnull data_len)
{
    if (conn == NULL || conn->socket <= 0 || data == NULL) {
        mlog(dd_log_warning, "Invalid arguments. Bug");
        return dd_error;
    }

    union {
        char buf[CMSG_SPACE(sizeof(struct ucred))];
        struct cmsghdr _align;
    } control;

    dd_header h;
    struct iovec iov = {
        .iov_base = &h,
        .iov_len = sizeof h,
    };
    struct msghdr msgh = {
        .msg_iov = &iov,
        .msg_iovlen = 1,
        .msg_control = control.buf,
        .msg_controllen = CMSG_LEN(sizeof(struct ucred)),
    };

    ssize_t recv_bytes = recvmsg(conn->socket, &msgh, 0);
    if (recv_bytes == -1) {
        mlog_err(dd_log_info, "Error receviving data from helper");
        // will return after setsockopt() call
    }

    setsockopt(conn->socket, SOL_SOCKET, SO_PASSCRED, &(int){0}, sizeof(int));

    if (recv_bytes == 0) {
        mlog(dd_log_info, "No data received");
        return dd_network;
    }
    if ((size_t)recv_bytes < sizeof(h)) {
        mlog(dd_log_info, "Not enough data received for the header");
        return dd_network;
    }

    // check credentials
    if (msgh.msg_flags & MSG_CTRUNC) { // NOLINT
        mlog(dd_log_info, "Truncated ancillary data");
    }
    dd_result ddres = _check_credentials(CMSG_FIRSTHDR(&msgh));
    if (ddres) {
        return ddres;
    }

    if (strncmp(h.code, "dds", 3) != 0) {
        mlog(dd_log_warning, "Invalid message header from helper");
        return dd_network;
    }

    return _recv_message_body(conn->socket, data, data_len, h.size);
}
static dd_result _check_credentials(struct cmsghdr *cmsgp)
{
    if (!cmsgp || cmsgp->cmsg_len != CMSG_LEN(sizeof(struct ucred))) {
        mlog(dd_log_warning,
            "Helper credentials: no ancillary data or incorrect size");
        return dd_network;
    }
    if (cmsgp->cmsg_level != SOL_SOCKET ||
        cmsgp->cmsg_type != SCM_CREDENTIALS) {
        mlog(dd_log_warning, "Unexpect type of ancillary data");
        return dd_network;
    }

    struct ucred creds;
    memcpy(&creds, CMSG_DATA(cmsgp), sizeof(struct ucred)); // NOLINT
    mlog_g(dd_log_debug, "Credentials: pid %d, uid %d, gid %d", (int)creds.pid,
        (int)creds.uid, (int)creds.gid);

    tsrm_env_lock();
    char *use_zend_alloc = getenv("USE_ZEND_ALLOC"); // NOLINT
    tsrm_env_unlock();

    if (use_zend_alloc && atoi(use_zend_alloc) == 0) { // NOLINT
        mlog(dd_log_debug, "Skipping helper uid check (valgrind tests)");
        return dd_success;
    }

    if (creds.uid != geteuid()) {
        mlog(dd_log_error,
            "Mismatch of effective uid between helper and this process. "
            "Helper's uid is %d, ours is %d",
            (int)creds.uid, (int)geteuid());
        return dd_network;
    }

    mlog(dd_log_debug, "Helper's process credentials are correct");
    return dd_success;
}
#else // no SO_PASSCRED
dd_result dd_conn_recv_cred(dd_conn *nonnull conn, char *nullable *nonnull data,
    size_t *nonnull data_len)
{
    return dd_conn_recv(conn, data, data_len);
}
#endif

int dd_conn_destroy(dd_conn *nonnull conn)
{
    if (conn->socket == -1) {
        return 0;
    }
    int ret = close(conn->socket);
    conn->socket = -1;
    return ret;
}
dd_result dd_conn_set_timeout(
    dd_conn *nonnull conn, enum comm_type comm_type, int milliseconds) // NOLINT
{
    int type;
    if (comm_type == comm_type_recv) {
        type = SO_RCVTIMEO;
    } else if (comm_type == comm_type_send) {
        type = SO_SNDTIMEO;
    } else {
        return dd_error;
    }
    if (!dd_conn_connected(conn)) {
        return dd_error;
    }

    int time_seconds = milliseconds / 1000;               // NOLINT
    int time_microseconds = (milliseconds % 1000) * 1000; // NOLINT

    /* 200 ms = 200 * 1000 us */
    struct timeval timeout;
    timeout.tv_sec = time_seconds;
    timeout.tv_usec = time_microseconds;

    mlog(dd_log_debug, "setting timeout to %u.%06u", time_seconds,
        time_microseconds);

    int res =
        setsockopt(conn->socket, SOL_SOCKET, type, &timeout, sizeof(timeout));
    if (res) {
        mlog_err(dd_log_warning, "setsockopt (%d) error: (%d ms)", type,
            milliseconds);
    }

    return dd_success;
}

#define ONE_E3 1000
#define ONE_E6 1000000
#define ONE_E9 1000000000
static void _timespec_add_ms(struct timespec *ts, long num_ms)
{
    long seconds = num_ms / ONE_E3;
    long nanoseconds = (num_ms % ONE_E3) * ONE_E6;

    ts->tv_sec += seconds;
    ts->tv_nsec += nanoseconds;

    if (ts->tv_nsec >= ONE_E9) {
        ts->tv_sec += ts->tv_nsec / ONE_E9;
        ts->tv_nsec %= ONE_E9;
    }
}

static long _timespec_delta_ms(struct timespec *ts1, struct timespec *ts2)
{
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    long res = (ts1->tv_sec - ts2->tv_sec) * 1000;
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    res += (ts1->tv_nsec - ts2->tv_nsec) / 1000000;
    return res;
}
