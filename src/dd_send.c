#include <stdio.h>
#include <time.h>
#include <unistd.h> // TODO take this out and make a generic close in http.h

#include "dd_send.h"

/********************************  Constants  *********************************/
const char *DDRC_table[] = {DDRC_PARAMS(DDRCP_ELOOK) "Invalid code"};
const char *DDR_keys[] = {DDR_PARAMS(DDRP_KEYS)};
const char DDR_types[] = {DDR_PARAMS(DDRP_TYPE)};
const unsigned int DDR_idx[] = {DDR_PARAMS(DDRP_ENUM)};
const bool DDR_reqd[] = {DDR_PARAMS(DDRP_REQD)};
const char *DDR_defaults[] = {DDR_PARAMS(DDRP_DFLT)};

/********************************  Functions  *********************************/
const char *DDR_code2str(int c) {
  if (c < 0 || c > DDRC_VAL_LEN)
    c = DDRC_VAL_LEN;
  return DDRC_table[c];
}

inline static bool apikey_isvalid(char *key) {
  size_t n = 0;
  if (!key)
    return false;
  if (32 != ((n = strlen(key))))
    return false;

  for (size_t i = 0; i < n; i++)
    if (!islower(key[i]) && !isdigit(key[i]))
      return false;
  return true;
}

char *HTTP_RandomNameMake(char *s, int n) {
  static char tokens[] = "0123456789abcdef";
  s[n] = 0;
  for (int i = 0; i < n; i++)
    s[i] = tokens[rand() % (sizeof(tokens) - 1)];
  return s;
}

DDReq *DDR_init(DDReq *req) {
  if (!req) {
    req = calloc(1, sizeof(DDReq));
    if (!req)
      return NULL;
    req->ownership = 0x01;
  } else {
    req->ownership = 0;
  }

  req->as_header = as_init(NULL);
  req->as_body = as_init(NULL);
  req->res.as = as_init(NULL);
  req->req.conn = &req->conn;
  req->res.conn = &req->conn;

  // As a final step, make sure the boundary is populated
  HTTP_RandomNameMake(req->boundary, DDR_BLEN - 1);
  return req;
}

void DDR_free(DDReq *req) {
  as_free(req->as_header);
  as_free(req->as_body);
}

int DDR_push(DDReq *req, const char *disposition, const char *type,
             const unsigned char *payload, size_t sz_payload) {
  as_sprintf(req->as_body, "--%s\r\nContent-Disposition: form-data",
             req->boundary);

  if (disposition)
    as_sprintf(req->as_body, "; %s\r\n", disposition);
  else
    as_sprintf(req->as_body, "\r\n");

  if (type)
    as_sprintf(req->as_body, "Content-Type: %s\r\n", type);
  as_sprintf(req->as_body, "\r\n");
  as_add(req->as_body, payload, sz_payload);
  as_sprintf(req->as_body, "\r\n");

  return DDRC_ESUCCESS;
}

// Allocation can fail, so have to return state
int DDR_pprof(DDReq *req, DProf *dp) {
  static char pprof_disp[] =
      "name=\"data[auto.pprof]\"; filename=\"auto.pb.gz\"";
  static char pprof_type[] = "application/octet-stream";

  // Extract the value information
  int sz_vt = 0, idx = 0, ret = DDRC_EFAILED;
  uint64_t i = 0;
  uint64_t sz;

  // Get the length of the overall value, for instance, "samples,cpu"
  for (i = 0; i < dp->pprof.n_sample_type; i++) {
    idx = dp->pprof.sample_type[i]->type;
    (void)pprof_getstr(dp, idx, &sz); // Assume this is a cstr
    sz_vt += 1 + sz;
  }

  // Serialize pprof
  if (!dp)
    return DDRC_EINVAL;
  unsigned char *pprof_str = pprof_flush(dp, &sz);
  if (!pprof_str) {
    return DDRC_ESERIAL;
  }

  // TODO this is ridiculous and hilarious, but also if you're sending
  //      more than 10 pprofs you are probably wrong.
  // Prepare to write the appropriate multipart sections
  if (req->pprof_count > 9)
    return DDRC_ETOOMANYPPROFS;

  // Emit the serialized pprof
  if ((ret = DDR_push(req, pprof_disp, pprof_type, pprof_str, sz))) {
    free(pprof_str);
    return ret;
  }

  // Cleanup
  req->pprof_count++;
  free(pprof_str);
  return ret;
}

void DDR_setTimeNano(DDReq *req, int64_t ti, int64_t tf) {
  ti /= 1000000000; // time_t is in seconds
  tf /= 1000000000;
  char time_start[128] = {0};
  char time_end[128] = {0};

  struct tm *tm_start = localtime(&ti);
  struct tm *tm_end = localtime(&tf);

  strftime(time_start, 128, "%Y-%m-%dT%H:%M:%SZ", tm_start);
  strftime(time_end, 128, "%Y-%m-%dT%H:%M:%SZ", tm_end);

  DDR_push(req, "name=\"start\"", NULL, (const unsigned char *)time_start,
           strlen(time_start));
  DDR_push(req, "name=\"end\"", NULL, (const unsigned char *)time_end,
           strlen(time_end));
}

int DDR_finalize(DDReq *req) {
  static char fmt_name[] = "name=\"%s\"";
  static char fmt_tagval[] = "%s:%s";
  char *name_str = NULL;
  unsigned char *tagval_str = NULL;
  size_t sz = 0;

  // Preflight check--if the user supplied an apikey, check for validity
  if (req->apikey && !apikey_isvalid(req->apikey))
    return DDRC_EBADKEY;

  // Validate info for connection
  if (!req->host || !req->port)
    return DDRC_EINVAL;
  if (req->apikey)
    as_sprintf(req->as_header, "POST %s HTTP/1.1\r\n", "/v1/input");
  else
    as_sprintf(req->as_header, "POST %s HTTP/1.1\r\n", "/profiling/v1/input");
  as_sprintf(req->as_header, "Host: %s:%s\r\n", req->host, req->port);

  // Populate header and body elements
  for (int i = 0; i < DDR_VAL_LEN; i++) {
    int j = DDR_idx[i];
    char *val = req->values[j];

    // If the request specified a value, then use that.
    if (!val) {
      if (DDR_reqd[i])
        return (long)DDR_defaults[j];
      else if (DDR_defaults[j])
        val = (char *)DDR_defaults[j];
      else
        continue;
    }
    switch (DDR_types[j]) {
    case 0: // Skipit
      break;
    case 1: // This goes into the HTTP header
      as_sprintf(req->as_header, "%s:%s\r\n", DDR_keys[j], val);
      break;
    case 2: // This goes in as a multipart segment
      // TODO lots of ways to do this that avoid the alloc.  We could
      //      have a lookup table or a single large region or...
      sz = 1 + snprintf(NULL, 0, fmt_name, DDR_keys[j]);
      name_str = malloc(sz + 1);
      snprintf(name_str, sz, fmt_name, DDR_keys[j]);
      DDR_push(req, name_str, NULL, (unsigned char *)val, strlen(val));
      free(name_str);
      break;
    case 3: // Similar to 1, but different
      sz = 1 + snprintf(NULL, 0, fmt_tagval, DDR_keys[j], val);
      tagval_str = malloc(sz);
      snprintf((char *)tagval_str, sz, fmt_tagval, DDR_keys[j], val);
      DDR_push(req, "name=\"tags[]\"", NULL, tagval_str, sz - 1);
      free(tagval_str);
      break;
    default:
      // TODO put an assert here or something, idk
      break;
    }
  }

  // Tell the server that we don't want to hang out after this
  as_sprintf(req->as_header, "Connection: close\r\n");

  // Wrap up by populating the boundary header
  as_sprintf(req->as_header,
             "Content-Type: multipart/form-data; "
             "boundary=%s\r\n",
             req->boundary);

  // ... and also pushing a blank boundary line
  as_sprintf(req->as_body, "--%s\r\n", req->boundary);

  return DDRC_ESUCCESS;
}

#define caseH2D(x)                                                             \
  case (HTTP_##x):                                                             \
    return DDRC_##x

// technically this could be done programmatically, but whatever
inline static int DDR_http2ddr(int http) {
  switch (http) {
    caseH2D(ESUCCESS);
    caseH2D(ENOT200);
    caseH2D(EPARSE);
    caseH2D(ETIMEOUT);
    caseH2D(ENOREQ);
    caseH2D(ECONN);
    caseH2D(EADDR);
    caseH2D(ESOCK);
    caseH2D(ERES);
    caseH2D(EALREADYCONNECTED);
    caseH2D(EPARADOX);
  }
  printf("HTTP thingy failed (%d)\n", http);
  return DDRC_EFAILED;
}

int DDR_send(DDReq *req) {
  int ret;

  // Our last remaining thing to do is compute the content-length.
  as_sprintf(req->as_header, "Content-Length:%ld\r\n\r\n", req->as_body->n);

  // JIT population, as HttpSend() will perform a connect under-the-hood
  req->req.host = (unsigned char *)req->host;
  req->req.port = (unsigned char *)req->port;
  if ((ret = HttpSend(&req->req, req->as_header->str, req->as_header->n)))
    return DDR_http2ddr(ret);
  if ((ret = HttpSend(&req->req, req->as_body->str, req->as_body->n)))
    return DDR_http2ddr(ret);

  return DDRC_ESUCCESS;
}

int DDR_watch(DDReq *req, int timeout) {
  int ret = DDR_http2ddr(HttpResRecvTimedwait(&req->res, timeout));

  // TODO we should formalize the list of states to close the connection against
  //      and possibly also generalize connection semantics.  This is a sloppy
  //      way of emulating tail-end enforcement of `connection: close`
  if (ret != DDRC_ENOREQ && ret != DDRC_ETIMEOUT) {
    close(req->conn.fd);
    req->conn.fd = -1;
    req->conn.state = HCS_INIT;
  }
  return ret;
}

int DDR_clear(DDReq *req) {
  req->pprof_count = 0;
  if (as_clear(req->as_body) || as_clear(req->as_header) ||
      as_clear(req->res.as)) {
    printf("Failed to clear\n");
    return DDRC_EFAILED;
  }
  return DDRC_ESUCCESS;
}
