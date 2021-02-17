#ifndef _H_HTTP
#define _H_HTTP

#include <ctype.h>
#include <stdbool.h>
#include <sys/types.h>

#include "append_string.h"

/******************************************************************************\
|*                                Socket Stuff                                *|
\******************************************************************************/
int TcpSockNew();
bool SocketSetFcntlBit(int, int, bool);
bool SocketSetNonblocking(int, bool);

/******************************************************************************\
|*                                 HTTP Stuff                                 *|
\******************************************************************************/
/*****************************  HTTP Connection  ******************************/
typedef enum HttpConnState {
  HCS_FREE,
  HCS_INIT,
  HCS_CONNECTED,
  HCS_SENDREC,
} HttpConnState;

typedef enum HTTP_RET {
  HTTP_ESUCCESS = 0,
  HTTP_ENOT200, // Success or failure is contextual
  HTTP_ETIMEOUT,
  HTTP_ENOREQ,
  HTTP_ECONN,
  HTTP_EADDR,
  HTTP_ESOCK,
  HTTP_EALREADYCONNECTED, // Tried to connect, but already connected.
  HTTP_EPARADOX,          // Special error
} HTTP_RET;

typedef struct HttpConn {
  int fd;                // The file descriptor underneath this connection
  HttpConnState state;   // socket rather than HTTP
  char mode;             // 0 - async, 1 - forced sync (blocking)tt
  struct addrinfo *addr; // cache
  bool addr_cached;
} HttpConn;

int HttpConnect(HttpConn *, const char *, const char *);

/******************************   HTTP Request  *******************************/
// TODO who frees the host and port?  Since we have no (con/de)structor, it's
//      up to the caller to figure it out
typedef struct HttpReq {
  unsigned char *host;
  unsigned char *port;
  HttpConn *conn;
  unsigned char *payload;
  size_t sz;
} HttpReq;

int HttpSend(HttpReq *, const void *, size_t);

/******************************  HTTP Response  *******************************/
// clang-format on
#define HRES_STRUCT(a, b, c, d, e) e d;
#define HRES_ENUM(a, b, c, d, e) HRES_##a,
#define HRES_LOOKUP(a, b, c, d, e) #c,
#define HRES_FUNSDEC(a, b, c, d, e) extern bool HRESFun_##d(HttpRes *, char *);
#define HRES_FUNSLIST(a, b, c, d, e) HRESFun_##d,
// clang-format off
// A - Pedantic name
// B - Match name
// C - struct name (enum name)
//  A               B                C
#define HRES_PARAMS(X)                                                         \
  X(CONTENT_LENGTH, Content-Length, content-length, content_length, ssize_t)
typedef enum HRESVals { HRES_PARAMS(HRES_ENUM) HRES_VAL_LEN } HRESVals;

typedef struct HttpRes {
  HttpConn *conn;
  char *version;
  unsigned int code;
  union {
    struct {
      HRES_PARAMS(HRES_STRUCT)
    };
    char *values[HRES_VAL_LEN];
  };
  AppendString *as;
} HttpRes;

// Constants
extern char *HRES_lookup[];
HRES_PARAMS(HRES_FUNSDEC)
extern bool (*HRES_Funs[])(HttpRes *, char *);

// User functions
ssize_t HttpResProcess(HttpRes *);
ssize_t HttpResRecv(HttpRes *);
int HttpResRecvTimedwait(HttpRes *, int);

#endif
