INCLUDE = -Iinclude/native
CFLAGS = -ggdb3 -std=c11 -O2 -D_GNU_SOURCE
WARNS := -Wall -Wextra -Wpedantic
ANALYZER := -fanalyzer
CC := gcc-10 # feel free to change this

pprof:
	$(CC) -DKNOCKOUT_UNUSED $(WARNS) $(ANALYZER) $(CFLAGS) $(LDFLAGS) $(INCLUDE) -fPIC -shared -o bin/pprof.so src/native/pprof.c src/native/string_table.c src/native/proto/profile.pb-c.c
