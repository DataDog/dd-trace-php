#include "dd_send.h"

int main() {
  int ret = 0;

  // Even though the req object represents top-level keys in a uniform space,
  // internally the library knows whether to represent the key as an HTTP
  // header, a named HTTP multipart segment, or a tags[]-encoded HTTP multipart
  // segment
  // TODO, at some point accept a URI--although not much reason to do that until
  //       we actually use something more than just host and port
  DDReq *req = &(DDReq){
      .port = "1234",
      .host = "localhost", // recipient of request
      .user_agent = "MY AGENT",
      .apikey = "yesthisisanapikey",
      .accept_encoding = "gzip",
      .accept = "*/*",
      .host_tag = "sharedlib_test", // client host tag (local mach hostname)
      .language = "AMERICAN, BABY PEW PEW PEW",
      .runtime = "0630",
      .profiler_version = "2.71828",
      .runtime_os = "Sunway RaiseOS 2.0.5"};
  DDR_init(req);

  // A DDReq object gets initialized on first use, so you don't need a special
  // initialization step.
  // It works by pushing data into the multipart segments.  For instance, here's
  // how you hardcode the inclusion of a pprof
  const unsigned char *buf = (unsigned char *)"THIS IS ACTUALLY A VALID PPROF";
  size_t sz_buf = strlen((char *)buf);
  DDR_push(req, "name=\"data[0]\"; filename=\"pprof.data\"",
           "application/octet-stream", buf, sz_buf);
  DDR_push(req, "name=\"types[0]\"", NULL, (unsigned char *)"samples,cpu",
           strlen("samples,cpu"));
  DDR_push(req, "name=\"format\"", NULL, (unsigned char *)"pprof",
           strlen("pprof"));

  // The flow of the calling application may require the DDReq to become
  // invalid.  In that case, you dan discard the buffer and start over.
  DDR_clearbody(req);

  // You can also send a DPRof object and DDReq will add it in a standard way.
  // You can add multiple pprofs and it'll enumerate them in a way that is
  // accepted by the backend (at the time of writing)
  DProf *dp =
      pprof_Init(NULL, (const char **)&(const char *[]){"samples", "cpu-time"},
                 (const char **)&(const char *[]){"count", "nanoseconds"}, 2);
  // Do other stuff with dp
  DDR_pprof(req, dp);

  // When you're ready to send, just finalize the request and send it off.
  // Finalizing flushes the rest of the headers and multipart elements.
  // Send returns immediately, without blocking.
  DDR_finalize(req);
  if ((ret = DDR_send(req))) {
    // A nonzero return is an error, such as being unable to connect
    printf("Tried to send() with error (%s), but I'm ignoring it.\n",
           DDR_code2str(ret));
  }

  // You can reap the current status of the DDReq.
  // Timeout values:
  //   * positive - timeout in ms
  //   * zero     - return immediately
  //   * negative - block indefinitely
  printf("%d\n", DDR_watch(req, 0));
  printf("%s\n", DDR_code2str(DDR_watch(req, 0)));

  // At a certain point, you may be ready to block until a request is ready.
  // The timeout is specified in integral milliseconds, where 0 is effectively
  // the same as DDR_check().  You can also block indefinitely by giving a
  // negative value.
  if (0 > (ret = DDR_watch(req, 100))) {
    printf("The result was %s\n", DDR_code2str(ret)); // You can also convert an
                                                      // rc into a string
  }

  // At a future point in time, DDReq will offer some kind of retry strategy,
  // but for now it's up to the application to structure a valid approach.
  // As such, `DDR_send()` does not clear its buffers until you tell it to.
  DDR_clear(req);

  // Even though a DDReq does JIT initialization, you may still want to return
  // its internal buffers to the OS.  The caller is responsible for doing the
  // right thing with the DDR itself.
  DDR_free(req);
  return 0;
}
