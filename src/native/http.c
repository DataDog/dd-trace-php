#include <assert.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h> // ULONG_MAX
#include <netdb.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <poll.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#include "http.h"

int SockSetBit(int fd, int bit, bool v) {
  return setsockopt(fd, IPPROTO_TCP, bit, (const void *)&(int){v}, sizeof(int));
}

int TcpSockNew() {
  // NB This rather strongly assumes that the caller will ultimately make
  // monolithic write() calls.  We do that, since we stupidly copy the entire
  // gzipped, serialized protobuf to the stream--but it may be interesting to
  // try e.g., TCP_CORK or writev() to prevent some of the memory copies.
  // We're doing it this way because we'd need to set up on the order of 10x
  // buffers anyway, and do append-type operations on all of them to properly
  // set up the multipart boundaries ahead of writev() or whatever.  Since
  // we'll probably be transferring less than megabytes at a time
  // Another factor in the below is the presumption that the agent will be
  // local--on a different container--from the client(s), so prefer options
  // that usefully break assumptions about internet-level latency.
  int fd;
  if (-1 == (fd = socket(AF_INET, SOCK_STREAM, IPPROTO_IP)))
    return -1;
  if (-1 == SockSetBit(fd, TCP_NODELAY, 1))
    return close(fd), -1;
  if (-1 == SockSetBit(fd, TCP_QUICKACK, 1))
    return close(fd), -1;
  return fd;
}

bool SocketSetFcntlBit(int fd, int disp, bool enable) {
  int fflags = 0;
  if (fd < 0)
    return false;
  if (-1 == (fflags = fcntl(fd, F_GETFL, 0)))
    return false;
  return !fcntl(fd, F_SETFL, enable ? (fflags | disp) : fflags & (~disp));
}

bool SocketSetNonblocking(int fd, bool is_nonblocking) {
  return SocketSetFcntlBit(fd, O_NONBLOCK, is_nonblocking);
}

/********************************  HTTP Stuff  ********************************/
int HttpConnect(HttpConn *conn, const char *host, const char *port) {
  // The underlying machinery is a bit permissive, so these NULL pointers may
  // not bubble up during debuggin
  assert(host);
  assert(port);

  // If we are trying to connect but we're already connected, don't connect.
  if (conn->state == HCS_CONNECTED || conn->state == HCS_SENDREC)
    return HTTP_EALREADYCONNECTED;

  // TODO validate DNS cache?
  if (!conn->addr_cached) {
    if (getaddrinfo(host, port, NULL, &conn->addr)) {
      conn->addr = NULL;
      return HTTP_EADDR;
    }
    conn->addr_cached = true;
  }

  // Cleanup potential old sockets
  if (-1 != conn->fd)
    close(conn->fd), conn->fd = -1;

  // We have a valid Addr, now connect
  if (-1 == (conn->fd = TcpSockNew()))
    return HTTP_ESOCK;
  if (connect(conn->fd, conn->addr->ai_addr, conn->addr->ai_addrlen))
    return HTTP_ECONN;
  conn->state = HCS_CONNECTED;
  return 0;
}

/*******************************  HTTP Request  *******************************/
int HttpSend(HttpReq *req, const void *payload, size_t sz_payload) {
  int ret;
  assert(req->conn);
  if (req->conn->state != HCS_CONNECTED && req->conn->state != HCS_SENDREC)
    if ((ret = HttpConnect(req->conn, (const char *)req->host,
                           (const char *)req->port)))
      return ret;

  while (-1 == (ret = send(req->conn->fd, payload, sz_payload, MSG_DONTWAIT)))
    if (errno == EAGAIN) {
      req->conn->state = HCS_SENDREC;
      return HTTP_ESUCCESS;
    } else if (errno != EINTR) {
      return ret;
    }

  req->conn->state = HCS_SENDREC;
  return HTTP_ESUCCESS;
}

/******************************  HTTP Response  *******************************/
char *HRES_lookup[] = {HRES_PARAMS(HRES_LOOKUP)};

bool HRESFun_content_length(HttpRes *res, char *val) {
  res->content_length = strtoul(val, NULL, 10);
  return true;
}

// Comes after the HRESFun_* definitions
bool (*HRES_Funs[])(HttpRes *, char *) = {HRES_PARAMS(HRES_FUNSLIST)};

// User functions
/*
 * TODO save incremental state, since this strategy forces the client to hold
 *      onto the entire raw message until the header can be processed.
 *
 * The below follows RFC-2616, except when it doesn't.
 *
 */
ssize_t HttpResProcess(HttpRes *res) {
  char *p, *q; // current, end of current scope, next line
  uint64_t ret_uint = 0;
  p = res->as->str;

  // Extract the Status-Line
  if (!(p = strstr(p, "\r\n")))
    return -1; // Incomplete

  // TODO don't skip HTTP version
  if (!(p = strchr(p, '/')))
    return -1; // Malformed
  if (!(p = strchr(p, ' ')))
    return -1; // Malformed

  // Extract Status-Code
  res->code = 0;
  if (!(strchr(p + 1, ' ')))
    return -1; // Malformed
  if (99 > (ret_uint = strtoul(p + 1, NULL, 10)) || ULONG_MAX == ret_uint)
    return -1; // MALFORMED
  res->code = ret_uint;
  if (!(p = 2 + strstr(p, "\r\n")))
    return -1; // Malformed

  res->content_length = -1;
  while (true) {
    if (!(q = strstr(p, "\r\n")))
      return -1;
    q += 2;                             // q points to next line
    if (q[2] == '\r' && q[3] == '\n') { // Done with header
      p = q + 4;
      break;
    }

    // Some day, replace this with a dictionary.  Or don't.
    for (int i = 0; i < HRES_VAL_LEN; i++) {
      int n_cmp = strlen(HRES_lookup[i]);
      if (q - p - 2 < n_cmp)
        continue;
      if (!strncasecmp(HRES_lookup[i], p, n_cmp)) {
        if (!(p = strchr(p, ':')))
          return -1; // Malformed, after all that!
        HRES_Funs[i](res, p + 1);
        break;
      }
    }
  }

  // If we're here, we're processing the body of the message.
  // TODO lol
  return 0;
}

ssize_t HttpResRecv(HttpRes *res) {
  ssize_t n = 0;
  AppendString *A = res->as;
  as_grow(A, 256);

  // If this recv() was interrupted by a signal, do it over again
  while (true) {
    if (-1 ==
        (n = recv(res->conn->fd, &A->str[A->n], A->sz - A->n, MSG_DONTWAIT))) {
      if (errno == EINTR || errno == EWOULDBLOCK) {
        continue;
      } else {
        return -1;
      }
    }
    A->n += n;
    if (n > 0)
      continue;
  }
  return n;
}

/*
 * Note: This function only times the initial poll(), it should also drain the
 *       socket and re-enter poll for up to the specified amount of time if
 *       the end of the HTTP response has yet to be seen.
 *
 *       Also note that if poll() is interrupted by a signal and has to resume,
 *       the time spent in previous poll()s is lost.
 */
int HttpResRecvTimedwait(HttpRes *res, int timeout) {
  int ret = 0;
  if (res->conn->state != HCS_SENDREC)
    return HTTP_ENOREQ;

  struct pollfd fds = {.fd = res->conn->fd, POLLIN};
  while (true) {
    switch (poll(&fds, 1, timeout)) {
    case 1: // Something is up with the FD we submitted
      if ((ret = HttpResRecv(res)))
        return ret;
      return HttpResProcess(res);
    case 0: // timed out
      return HTTP_ETIMEOUT;
    default: // An error happened
      if (errno == EINTR || errno == EWOULDBLOCK)
        break; // Retry
      close(res->conn->fd);
      res->conn->fd = -1;
      res->conn->state = HCS_INIT;
      return HTTP_ECONN;
    }
  }
  return HTTP_EPARADOX;
}
