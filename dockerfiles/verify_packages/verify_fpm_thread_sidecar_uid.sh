#!/usr/bin/env sh

# Thread-mode sidecar under a privilege-dropping PHP-FPM.
#
# In thread mode the "sidecar" is not a separate process: it is a thread inside the PHP master,
# started from MINIT. On a stock Debian FPM that master runs as root while the pool workers drop
# to www-data - the configuration in which the sidecar would otherwise create every shared
# memory segment, and write its log file, as root.
#
# Three things are checked, in order:
#   a) one request is served and its trace actually reaches the agent;
#   b) the shared memory carrying the agent's response to that trace submission is owned by the
#      worker user, not by root;
#   c) the sidecar threads run as the worker user - while FPM's own main thread is still root,
#      which is the part that must not regress.
#
# (b) is not only about ownership. Both agent-response channels embed `geteuid()` in their
# *name* (`/ddinf<uid>-<hash>` for agent info, `/ddcfg-<uid>-<hash>` for the trace-submission
# response), and the sidecar writes them while the worker reads them. If the sidecar thread were
# still root the two sides would compute different names and the worker would never see a
# response at all, so the uid in the filename is as load-bearing as the file's owner.

set -e

fail() {
    echo "FAILURE: $1"
    shift
    for line in "$@"; do echo "  $line"; done
    exit 1
}

export DD_TRACE_SIDECAR_CONNECTION_MODE=thread
export DD_REMOTE_CONFIG_ENABLED=false

mkdir -p /var/www/html/
echo "<?php echo 'hi'; ?>" > /var/www/html/index.php

OS_ID=$(. /etc/os-release; echo "$ID")
sh "$(pwd)/dockerfiles/verify_packages/${OS_ID}/install.sh"
sleep 1

# install.sh starts php-fpm with -D. That will not do here: MINIT starts the listener thread,
# and -D then daemonizes by forking, which destroys it - the bound abstract socket survives in
# the child, so workers connect to a socket nobody accepts and their traces go nowhere. Observed
# directly: the MINIT pid was gone, no ddtrace thread existed in any process, and
# `@libdatadog/<ver>@pid<minit-pid>.sock` was still listed in /proc/net/unix.
#
# So run a dedicated foreground master, whose MINIT process is the one that stays. It uses its
# own ports and pool so it cannot collide with what install.sh started.
FPM_PORT=19000
NGINX_PORT=18080
WWW_CONF_DIR=$(dirname -- "$(find /etc/php* -name www.conf | head -1)")
# The Sury install path installs `php-fpm${PHP_VERSION}`, and only its own child process ever
# refers to it by that name; an unversioned `php-fpm` does not exist on those images. Resolve it
# here, and say so now rather than failing with "command not found" further down.
FPM_BIN=${PHP_FPM_BIN:-}
if [ -z "${FPM_BIN}" ]; then
    for candidate in "php-fpm${PHP_VERSION}" php-fpm; do
        if command -v "${candidate}" > /dev/null 2>&1; then
            FPM_BIN=${candidate}
            break
        fi
    done
fi
[ -n "${FPM_BIN}" ] || fail \
    "no php-fpm binary found" \
    "tried php-fpm${PHP_VERSION} and php-fpm; set PHP_FPM_BIN to override"

cat > /tmp/fpm-thread-verify.conf <<CONF
[global]
pid = /run/php/thread-verify.pid
error_log = /tmp/fpm-thread-verify-error.log
daemonize = no
[www]
user = www-data
group = www-data
listen = 127.0.0.1:${FPM_PORT}
pm = static
pm.max_children = 2
clear_env = no
CONF
mkdir -p /run/php
nohup "${FPM_BIN}" -F -y /tmp/fpm-thread-verify.conf > /tmp/fpm-thread-verify.log 2>&1 &
FPM_MASTER_PID=$!
sleep 3

cat > /tmp/nginx-thread-verify.conf <<NGX
daemon on;
error_log /tmp/nginx-thread-verify-error.log;
pid /tmp/nginx-thread-verify.pid;
events { worker_connections 64; }
http {
  access_log off;
  server {
    listen 127.0.0.1:${NGINX_PORT};
    root /var/www/html;
    location ~ \\.php\$ {
      fastcgi_pass 127.0.0.1:${FPM_PORT};
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
  }
}
NGX

WORKER_USER=www-data
WORKER_UID=$(id -u "${WORKER_USER}")

echo "##########################################################################"
echo "Thread-mode sidecar under privilege-dropping PHP-FPM"
echo "Worker user: ${WORKER_USER} (uid ${WORKER_UID})"

# ---------------------------------------------------------------- premise ----
# The pid of the master started above, not a /proc scan: install.sh leaves its own daemonized
# master running, and a scan can pick that one instead. That daemon lost its listener thread to
# daemonization, so every assertion below would be inspecting the wrong process.
[ -n "${FPM_MASTER_PID}" ] || fail "the foreground php-fpm master was not started"
[ -r "/proc/${FPM_MASTER_PID}/cmdline" ] || fail \
    "the foreground php-fpm master (pid ${FPM_MASTER_PID}) is gone" \
    "$(tail -20 /tmp/fpm-thread-verify.log 2>/dev/null)"
# Anchored: the master's argv[0] *starts* with this, whereas a pool worker reads
# "php-fpm: pool www".
tr -d '\0' < "/proc/${FPM_MASTER_PID}/cmdline" | grep -q '^php-fpm: master' || fail \
    "pid ${FPM_MASTER_PID} is not an fpm master" \
    "argv0: $(tr -d '\0' < "/proc/${FPM_MASTER_PID}/cmdline")"

# Effective uid is the second field of the Uid: line.
master_uid=$(awk '/^Uid:/ {print $3}' "/proc/${FPM_MASTER_PID}/status")
echo "FPM master: pid ${FPM_MASTER_PID}, uid ${master_uid}"
[ "${master_uid}" = "0" ] || fail \
    "this test needs an FPM master running as root - the whole point is the downgrade" \
    "master uid is ${master_uid}"

# ------------------------------------------------------------- a) request ----
curl -s -L request-replayer/clear-dumped-data > /dev/null
curl -s -L -X POST request-replayer/set-agent-info \
    -d '{"endpoints":["/v0.4/traces"],"client_drop_p0s":true,"version":"7.99.0"}' > /dev/null

nginx -c /tmp/nginx-thread-verify.conf
sleep 1

OUTPUT=$(curl -s -L "127.0.0.1:${NGINX_PORT}/index.php")
[ "${OUTPUT}" = "hi" ] || fail "expected request output 'hi', got '${OUTPUT}'"
echo "a) request served correctly"

# Longer than DD_TRACE_AGENT_FLUSH_INTERVAL=1000, and the sidecar needs a moment to flush and
# to publish the agent's response into shared memory.
sleep 3

TRACES=$(curl -s -L request-replayer/replay)
if [ "${TRACES#*trace_id}" = "${TRACES}" ]; then
    fail "the trace did not reach the agent" "request replayer returned: ${TRACES}"
fi
echo "a) trace reached the agent"

# --------------------------------------------------------- b) shared memory ----
# Any dd* segment in /dev/shm belongs to either the sidecar thread or a worker; after the
# downgrade both are ${WORKER_USER}, so none of them may be root-owned.
SHM_FILES=$(find /dev/shm -maxdepth 1 -name 'dd*' 2>/dev/null || true)
[ -n "${SHM_FILES}" ] || fail "no dd* shared memory found in /dev/shm" \
    "$(ls -la /dev/shm 2>/dev/null)"

echo "b) shared memory found:"
for shm in ${SHM_FILES}; do
    echo "     $(stat -c '%U:%G %a %n' "${shm}")"
done

for shm in ${SHM_FILES}; do
    owner=$(stat -c '%U' "${shm}")
    [ "${owner}" = "${WORKER_USER}" ] || fail \
        "shared memory ${shm} is owned by ${owner}, expected ${WORKER_USER}" \
        "a root-owned segment here means the sidecar thread did not drop its privileges"
done
echo "b) all shared memory is owned by ${WORKER_USER}"

# The agent's response to the span-sending request: agent info and/or the remote-config
# payload returned with the trace submission. At least one must be present, and its name must
# embed the worker's uid - otherwise the worker is looking for a name nobody writes.
RESPONSE_SHM=$(find /dev/shm -maxdepth 1 \
    \( -name "ddinf${WORKER_UID}-*" -o -name "ddcfg-${WORKER_UID}-*" \) 2>/dev/null || true)
[ -n "${RESPONSE_SHM}" ] || fail \
    "no agent-response shared memory named for uid ${WORKER_UID}" \
    "expected /dev/shm/ddinf${WORKER_UID}-* or /dev/shm/ddcfg-${WORKER_UID}-*" \
    "present instead: ${SHM_FILES}" \
    "a name carrying a different uid means writer and reader disagree on the path"
echo "b) agent-response shared memory is named for uid ${WORKER_UID}:"
for shm in ${RESPONSE_SHM}; do echo "     ${shm}"; done

# ------------------------------------------------------------- c) threads ----
# The listener thread is named "ddtrace-sidecar-listener-<pid>"; comm is capped at 15
# characters, so it shows up as "ddtrace-sidecar". Tokio's blocking-pool threads inherit that
# comm from it, so this prefix is exactly the set that must have dropped. The watchdog is
# deliberately named "dd-watchdog" instead: it starts before any peer is authenticated, so it
# drops on a later tick rather than at birth, and matching it here would be a race.
SIDECAR_THREADS=
for task in "/proc/${FPM_MASTER_PID}/task/"*; do
    [ -r "${task}/comm" ] || continue
    case "$(cat "${task}/comm")" in
        ddtrace-sidecar*) SIDECAR_THREADS="${SIDECAR_THREADS} $(basename "${task}")" ;;
    esac
done

if [ -z "${SIDECAR_THREADS}" ]; then
    threads=$(for t in "/proc/${FPM_MASTER_PID}/task/"*; do
        printf '%s(%s) ' "$(basename "$t")" "$(cat "$t/comm" 2>/dev/null)"
    done)
    fail "no sidecar thread in the FPM master - is thread mode actually active?" \
        "threads present: ${threads}"
fi

for tid in ${SIDECAR_THREADS}; do
    tid_uid=$(awk '/^Uid:/ {print $3}' "/proc/${FPM_MASTER_PID}/task/${tid}/status")
    echo "c) sidecar thread ${tid} ($(cat "/proc/${FPM_MASTER_PID}/task/${tid}/comm")): uid ${tid_uid}"
    [ "${tid_uid}" = "${WORKER_UID}" ] || fail \
        "sidecar thread ${tid} runs as uid ${tid_uid}, expected ${WORKER_UID}"
done
echo "c) all sidecar threads run as ${WORKER_USER}"

# The uid is only half of it. A root master normally carries supplementary groups (gid 0 among
# them), and setresuid/setresgid do not touch those: a thread left in group root still reaches
# everything that group grants, which is exactly the residual the drop exists to remove. The
# drop therefore clears them with setgroups(0, NULL) first, and /proc reports the result.
for tid in ${SIDECAR_THREADS}; do
    tid_groups=$(awk '/^Groups:/ {$1=""; print}' "/proc/${FPM_MASTER_PID}/task/${tid}/status" | xargs)
    [ -z "${tid_groups}" ] || fail \
        "sidecar thread ${tid} still holds supplementary groups: ${tid_groups}" \
        "a thread dropped to ${WORKER_USER} but still in the master's groups is not dropped"
done
echo "c) no sidecar thread retains supplementary groups"

# And the master's own thread must be untouched: dropping it would de-privilege PHP-FPM itself,
# which is why the drop uses the per-thread syscall rather than libc's broadcasting wrapper.
main_uid=$(awk '/^Uid:/ {print $3}' "/proc/${FPM_MASTER_PID}/task/${FPM_MASTER_PID}/status")
[ "${main_uid}" = "0" ] || fail \
    "the FPM master's own thread dropped to uid ${main_uid} - it must stay root" \
    "libc's setresuid() broadcasts to every thread; only the raw syscall is per-thread"
echo "c) FPM master's own thread is still root"

# ------------------------------------------------ d) worker cannot reach the master ----
# The filesystem hardening (constrained crash-attachment reader, procfs/magic-link/device
# rejection, and constrained worker-selected outputs) protects resources the worker cannot reach
# directly. Establish that baseline: as the worker user, the root master's map table and a
# root-owned secret behind a 0700 directory are both out of reach. Anything the sidecar - running
# a worker's request inside this same root master - then discloses would be an escalation. The
# RPC-level attacks that try exactly that (additional_files=/proc/self/maps, descriptor magic
# links, symlink aliases, file:// and log redirection) are asserted to be *rejected* by the Rust
# reproducer in reproducers/sidecar-security-20260921, which can speak the sidecar IPC codec;
# this shell test cannot craft those messages, so it verifies the boundary they rely on instead.
SECRET_DIR=/tmp/thread-verify-root-only
SECRET_FILE=${SECRET_DIR}/secret
rm -rf "${SECRET_DIR}"
mkdir -p "${SECRET_DIR}"
echo "ROOT_ONLY_THREAD_VERIFY_SECRET" > "${SECRET_FILE}"
chmod 700 "${SECRET_DIR}"
chmod 600 "${SECRET_FILE}"

if su -s /bin/sh "${WORKER_USER}" -c "cat /proc/${FPM_MASTER_PID}/maps" >/dev/null 2>&1; then
    fail "the worker user could read the root master's /proc/<pid>/maps directly" \
        "the sidecar's crash receiver must not become a proxy for this"
fi
echo "d) worker user cannot read the root master's maps directly"

if su -s /bin/sh "${WORKER_USER}" -c "cat ${SECRET_FILE}" >/dev/null 2>&1; then
    fail "the worker user could read the root-owned secret directly" \
        "the constrained reader must not become a proxy for this either"
fi
echo "d) worker user cannot read the root-owned 0600 secret directly"
rm -rf "${SECRET_DIR}"

# --------------------------------------------- e) legitimate operation still works ----
# The output-restriction policy is active in this configuration (root master, worker-uid
# listener). It must not disturb legitimate SHM, logging or trace delivery: serve another
# request and confirm its trace still reaches the agent and the shared memory is still worker
# owned - the "post-attack healthy request" check.
curl -s -L request-replayer/clear-dumped-data > /dev/null
OUTPUT2=$(curl -s -L "127.0.0.1:${NGINX_PORT}/index.php")
[ "${OUTPUT2}" = "hi" ] || fail "post-policy request output was '${OUTPUT2}', expected 'hi'"
sleep 3
TRACES2=$(curl -s -L request-replayer/replay)
if [ "${TRACES2#*trace_id}" = "${TRACES2}" ]; then
    fail "the post-policy trace did not reach the agent" "request replayer returned: ${TRACES2}"
fi
echo "e) post-policy request served and its trace reached the agent"

for shm in $(find /dev/shm -maxdepth 1 -name 'dd*' 2>/dev/null || true); do
    owner=$(stat -c '%U' "${shm}")
    [ "${owner}" = "${WORKER_USER}" ] || fail \
        "after the policy, shared memory ${shm} is owned by ${owner}, expected ${WORKER_USER}"
done
echo "e) shared memory still owned by ${WORKER_USER} after the policy is active"

echo "Thread-mode sidecar under privilege-dropping PHP-FPM: SUCCESS"
echo "##########################################################################"
