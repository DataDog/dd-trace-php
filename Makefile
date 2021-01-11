INCLUDE = -Iinclude/native
CFLAGS = -ggdb3 -std=c11 -O2 -D_GNU_SOURCE
WARNS := -Wall -Wextra -Wpedantic
ANALYZER := -fanalyzer
CC := gcc-10 # feel free to change this

strings:
	$(CC) -DD_ALLOC_DBG -DD_LOGGING_ENABLE $(WARNS) $(ANALYZER) $(CFLAGS) $(LDFLAGS) $(INCLUDE) -fPIC -shared -o bin/string_table.so src/native/string_table.c

pprof:
	$(CC) -DKNOCKOUT_UNUSED -DD_ALLOC_DBG -DD_LOGGING_ENABLE $(WARNS) $(ANALYZER) $(CFLAGS) $(LDFLAGS) $(INCLUDE) -fPIC -shared -o bin/pprof.so src/native/pprof.c src/native/string_table.c src/native/proto/profile.pb-c.c
