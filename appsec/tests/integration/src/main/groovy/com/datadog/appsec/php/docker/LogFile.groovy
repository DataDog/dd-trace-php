package com.datadog.appsec.php.docker

/**
 * A log file inside an {@link AppSecContainer}. Supports marking the current
 * end-of-file position and then reading only what was appended since the mark,
 * which is the usual way a test isolates the log output of a single action.
 *
 * <pre>
 *   def lf = new LogFile(container, 'helper.log')
 *   lf.markEndPos()
 *   ... do something that writes to the log ...
 *   lf.linesSinceMark().any { it.contains('boom') }
 * </pre>
 *
 * A {@code name} without a leading {@code /} is resolved under {@link #LOG_DIR}.
 */
class LogFile {
    static final String LOG_DIR = '/tmp/logs'

    private final AppSecContainer container
    final String path
    private long markPos = 0

    LogFile(AppSecContainer container, String name) {
        this.container = container
        this.path = name.startsWith('/') ? name : "${LOG_DIR}/${name}"
    }

    /** Current size of the log in bytes (0 if it does not exist yet). */
    long size() {
        container.execInContainer('bash', '-c', "wc -c < ${path} 2>/dev/null || echo 0".toString())
                .stdout.trim() as long
    }

    /** Record the current end position; subsequent reads start from here. */
    void markEndPos() {
        markPos = size()
    }

    /** Raw text appended since the last {@link #markEndPos()}. */
    String getTextSinceMark() {
        container.execInContainer('bash', '-c', "tail -c +${markPos + 1} ${path}".toString()).stdout
    }

    /** Lines appended since the last {@link #markEndPos()}. */
    List<String> getLinesSinceMark() {
        getTextSinceMark().readLines()
    }
}
