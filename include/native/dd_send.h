#ifndef _H_DD_SEND
#define _H_DD_SEND

#include "append_string.h"
#include "http.h"
#include "pprof.h"

/*****************************  DDR Return Codes  *****************************/
#define DDRCP_ENUM(a, b) DDRC_##a,
#define DDRCP_ELOOK(a, b) b,
// clang-format off
#define DDRC_PARAMS(X)                                                         \
  X(ESUCCESS,          "successful")                                           \
  X(EFAILED,           "operation failed")                                     \
  X(ECONN,             "failed to connect")                                    \
  X(EDISCO,            "disconnected during operation")                        \
  X(EINVAL,            "invalid input value")                                  \
  X(ETOOMANYPPROFS,    "too many pprofs (max 10)")                             \
  X(ESERIAL,           "couldn't serialize payload")                           \
  X(ENOT200,           "HTTP did not return 200 code")                         \
  X(ETIMEOUT,          "call timed out")                                       \
  X(ENOREQ,            "invalid wait, no response pending")                    \
  X(EADDR,             "invalid host or port")                                 \
  X(ESOCK,             "socket error")                                         \
  X(EALREADYCONNECTED, "already connected")                                    \
  X(EPARADOX,          "something that shouldn't happen happened")

// Defines the valid returns of DDR functions
typedef enum DDRCode {
  DDRC_PARAMS(DDRCP_ENUM)
  DDRC_VAL_LEN,
} DDRCode;

extern const char *DDRC_table[];

/**********************************  DDReq  ***********************************/
#define DDRP_SRCT(a, b, c, d, e, f) char *b;
#define DDRP_KEYS(a, b, c, d, e, f) d,
#define DDRP_ENUM(a, b, c, d, e, f) DDR_##a,
#define DDRP_TYPE(a, b, c, d, e, f) c,
#define DDRP_INTR(a, b, c, d, e, f) #b, // Introsection
#define DDRP_REQD(a, b, c, d, e, f) e,
#define DDRP_DFLT(a, b, c, d, e, f) f,

// A - enum name
// B - key name, also string representation
// C - Type
//     0 - HTTP header
//     1 - Multipart upload form value
//     2 - Tag-encoded multipart upload value
// D - Encoded keyname
// E - Is this value required?
// F - Default (NB, some keys provide their own defaults)
//  A                B                 C  D                   E  F
#define DDR_PARAMS(X)                                                          \
  X(USERAGENT,       user_agent,       0, "User-Agent",       0, "ddprof")     \
  X(ACCEPT,          accept,           0, "Accept",           0, "*/*")        \
  X(APIKEY,          apikey,           0, "DD-API-KEY",       0, NULL)         \
  X(ACCEPTENCODING,  accept_encoding,  0, "Accept-Encoding",  0, NULL)         \
  X(RECORDINGSTART,  recording_start,  1, "recording-start",  0, NULL)         \
  X(RECORDINGEND,    recording_end,    1, "recording-end",    0, NULL)         \
  X(HOSTTAG,         host_tag,         2, "machine_host",     0, NULL)         \
  X(SERVICE,         service,          2, "service",          0, "myservice")  \
  X(LANGUAGE,        language,         2, "language",         1, "ILLEGAL")    \
  X(RUNTIME,         runtime,          2, "runtime",          1, "ILLEGAL")    \
  X(PROFILERVERSION, profiler_version, 2, "profiler-version", 0, NULL)         \
  X(RUNTIMEOS,       runtime_os,       2, "runtime-os",       0, NULL)
// clang-format on

typedef enum DDRVals {
  DDR_PARAMS(DDRP_ENUM) DDR_VAL_LEN,
} DDRVals;

#define DDR_BLEN 65
typedef struct DDReq {
  struct HttpConn conn;
  struct HttpReq req;
  struct HttpRes res;
  char *host;
  char *port;
  char boundary[DDR_BLEN];
  union {
    struct {
      DDR_PARAMS(DDRP_SRCT)
    };
    char *values[DDR_VAL_LEN];
  };
  int fd;
  AppendString *as_header;
  AppendString *as_body;
  char pprof_count;
  uint8_t ownership : 1, // Do I clean myself?
      initialized   : 1, // Have internal structs been created?
      _reserved     : 6;
} DDReq;
// clang-format on

/********************************  Constants  *********************************/
extern const char *DDR_keys[];
extern const char DDR_types[];
extern const char *DDR_defaults[];
extern const unsigned int DDR_idx[];
extern const bool DDR_reqd[];

DDReq *DDR_init(DDReq *);
void DDR_free(DDReq *);
int DDR_push(DDReq *, const char *, const char *, const unsigned char *,
             size_t);
int DDR_pprof(DDReq *, DProf *);
int DDR_finalize(DDReq *req);
int DDR_send(DDReq *);
int DDR_watch(DDReq *, int);
int DDR_clear(DDReq *);
const char *DDR_code2str(int);

#endif
