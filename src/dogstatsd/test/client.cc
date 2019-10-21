#include <dogstatsd_client/client.h>
#include <sys/socket.h>
#include <unistd.h>
#include <catch2/catch.hpp>
#include <thread>

// dummy server
struct dogstatsd_server {
  int sock;
  struct sockaddr_in addr;

  ~dogstatsd_server() { close(sock); }
};

dogstatsd_server dogstatsd_server_make() {
  int sock = -1;
  REQUIRE((sock = socket(AF_INET, SOCK_DGRAM, IPPROTO_UDP)) >= 0);

  struct sockaddr_in server = {0};
  socklen_t len = sizeof server;
  memset(&server, 0, sizeof server);
  server.sin_family = AF_INET;
  server.sin_addr.s_addr = INADDR_ANY;
  server.sin_port = 0;  // pick any open port

  REQUIRE(bind(sock, (struct sockaddr *)&server, len) > -1);

  // find out which port we were given
  REQUIRE(getsockname(sock, (struct sockaddr *)&server, &len) > -1);

  return dogstatsd_server{sock, server};
}

void dogstatsd_server_listen(dogstatsd_server *server, dogstatsd_client *client,
                             const char *expected_string) {
  socklen_t client_addr_size = sizeof(client->address);
  // 60 bytes for the IP header
  // 8 bytes for the UDP overhead
  int buffer_len = client->msg_buffer_len + 60 + 8;
  char *buffer = (char *)malloc(buffer_len);

  ssize_t bytes_received =
      recvfrom(server->sock, buffer, buffer_len, 0,
               (struct sockaddr *)&client->address, &client_addr_size);

  REQUIRE(bytes_received > -1);
  REQUIRE(bytes_received < buffer_len);

  buffer[bytes_received] = '\0';

  // this may be too brittle; if so, may need to parse the full string
  std::string actual_string = buffer;
  REQUIRE(actual_string == expected_string);

  free(buffer);
}

void _test_tags(const char *tags, const char *const_tags, const char *expect) {
  dogstatsd_server server = dogstatsd_server_make();

  char buf[DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE];
  size_t len = DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE;
  char host[NI_MAXHOST];
  char port[NI_MAXSERV];

  getnameinfo((sockaddr *)&server.addr, server.addr.sin_len, host, NI_MAXHOST,
              port, NI_MAXSERV, NI_NUMERICHOST | NI_NUMERICSERV);

  dogstatsd_client client =
      dogstatsd_client_ctor(host, port, buf, len, const_tags);
  REQUIRE(!dogstatsd_client_is_default_client(&client));

  // start a thread for the server
  std::thread server_thread{dogstatsd_server_listen, &server, &client, expect};

  dogstatsd_client_status status =
      dogstatsd_client_count(&client, "metric.hello", "1", tags);
  REQUIRE(status == DOGSTATSD_CLIENT_OK);

  server_thread.join();

  dogstatsd_client_dtor(&client);
}

TEST_CASE("count with no tags, no const_tags", "[dogstatsd_client]") {
  _test_tags(nullptr, nullptr, "metric.hello:1|c|@1.000000");
}

TEST_CASE("count with no tags, with const_tags", "[dogstatsd_client]") {
  _test_tags(nullptr, "lang:c", "metric.hello:1|c|@1.000000|#lang:c");
}

TEST_CASE("count with with tags, no const_tags", "[dogstatsd_client]") {
  _test_tags("lang:c", nullptr, "metric.hello:1|c|@1.000000|#lang:c");
}

TEST_CASE("count with with tags, with const_tags", "[dogstatsd_client]") {
  _test_tags("lang:c", "hello:world",
             "metric.hello:1|c|@1.000000|#lang:c,hello:world");
}

// todo: test sending message that's too large
// todo: test configuring client with lens of 0 and < 0.
