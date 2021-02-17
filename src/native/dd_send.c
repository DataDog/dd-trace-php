#include <stdio.h>

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
  }

  req->as_header = as_init(NULL);
  req->as_body = as_init(NULL);
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
  static char pprof_disp[] = "name=\"data[?]\"; filename=\"?.pprof\"";
  static char pprof_type[] = "application/octet-stream";
  static char value_type[] = "name=\"types[?]\"";
  static int data_idx = 11; // TODO better to use strcr
  static int file_idx = 26;
  static int val_idx = 12;

  // Extract the value information
  int sz_vt = 0, idx = 0, ret = DDRC_EFAILED;
  uint64_t i = 0;
  uint64_t sz;

  // Get the length of the overall value, for instance.
  // "samples,cpu"
  for (i = 0; i < dp->pprof.n_sample_type; i++) {
    idx = dp->pprof.sample_type[i]->type;
    (void)pprof_getstr(dp, idx, &sz); // Assume this is a cstr
    sz_vt += 1 + sz;
  }

  // Create the string holding the types
  char *types_str = malloc(sz_vt + 1);
  for (i = 0; i + 1 < dp->pprof.n_sample_type; i++) {
    idx = dp->pprof.sample_type[i]->type;
    strcat(types_str, (char *)pprof_getstr(dp, idx, NULL));
    strcat(types_str, ",");
  }
  idx = dp->pprof.sample_type[i]->type;
  strcat(types_str, (char *)pprof_getstr(dp, idx, NULL));
  value_type[val_idx] = '0' + req->pprof_count;

  // Serialize pprof
  if (!dp)
    return DDRC_EINVAL;
  unsigned char *pprof_str = pprof_flush(dp, &sz);
  if (!pprof_str) {
    free(types_str);
    return DDRC_ESERIAL;
  }

  // TODO this is ridiculous and hilarious, but also if you're sending
  //      more than 10 pprofs you are probably wrong.
  // Prepare to write the appropriate multipart sections
  if (req->pprof_count > 9)
    return DDRC_ETOOMANYPPROFS;
  pprof_disp[data_idx] = '0' + req->pprof_count;
  pprof_disp[file_idx] = '0' + req->pprof_count;

  // Emit the serialized pprof
  if ((ret = DDR_push(req, pprof_disp, pprof_type, pprof_str, sz))) {
    free(types_str);
    free(pprof_str);
    return ret;
  }

  // Emit the type information
  ret = DDR_push(req, value_type, NULL, (unsigned char *)types_str,
                 strlen(types_str));

  // Cleanup
  req->pprof_count++;
  free(pprof_str);
  free(types_str);
  return ret;
}

int DDR_finalize(DDReq *req) {
  static char fmt_name[] = "name=\"%s\"";
  static char fmt_tagval[] = "%s:%s";
  char *name_str = NULL;
  unsigned char *tagval_str = NULL;
  size_t sz = 0;

  // Validate info for connection
  if (!req->host || !req->port)
    return DDRC_EINVAL;
  as_sprintf(req->as_header, "POST http://%s%s HTTP/1.1\r\n", req->host,
             "/v1/input");
  as_sprintf(req->as_header, "Host: %s:%s\r\n", req->host, req->port);

  // Populate the boundary

  // Populate header and body elements
  for (int i = 0; i < DDR_VAL_LEN; i++) {
    int j = DDR_idx[i];
    char *val = req->values[j];

    // If we got a value, great!  If not, check for a default.  If there's
    // no value and no default, skip this entry.
    if (!val && DDR_defaults[j])
      val = (char *)DDR_defaults[j];
    else if (!val && !DDR_defaults[j] && DDR_reqd[i])
      return DDRC_EINVAL;
    else if (!val && !DDR_defaults[j])
      continue;
    switch (DDR_types[j]) {
    case 0: // This goes into the HTTP header
      printf("%s: %s\r\n", DDR_keys[j], val);
      as_sprintf(req->as_header, "%s: %s\r\n", DDR_keys[j], val);
      break;
    case 1: // This goes in as a multipart segment
      // TODO lots of ways to do this that avoid the alloc.  We could
      //      have a lookup table or a single large region or...
      sz = 1 + snprintf(NULL, 0, fmt_name, DDR_keys[j]);
      name_str = malloc(sz + 1);
      snprintf(name_str, sz, fmt_name, DDR_keys[j]);
      DDR_push(req, name_str, NULL, (unsigned char *)val, strlen(val));
      free(name_str);
      break;
    case 2: // Similar to 1, but different
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

  // Wrap up by populating the boundary
  as_sprintf(req->as_header,
             "Content-Type: multipart/form-data; "
             "boundary=%s\r\n",
             req->boundary);

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
    caseH2D(ETIMEOUT);
    caseH2D(ENOREQ);
    caseH2D(ECONN);
    caseH2D(EADDR);
    caseH2D(ESOCK);
    caseH2D(EALREADYCONNECTED);
    caseH2D(EPARADOX);
  }
  return DDRC_EFAILED;
}

int DDR_send(DDReq *req) {
  int ret;

  // Our last remaining thing to do is compute the content-length.
  as_sprintf(req->as_header, "Content-Length: %ld\r\n\r\n", req->as_body->n);

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
  return DDR_http2ddr(HttpResRecvTimedwait(&req->res, timeout));
}

int DDR_clear(DDReq *req) {
  req->pprof_count = 0;
  if (as_clear(req->as_body) || as_clear(req->as_header))
    return DDRC_EFAILED;
  return DDRC_ESUCCESS;
}
