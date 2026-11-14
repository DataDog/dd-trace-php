<?php
// Shared by php2shell_mitigation_multipart_file_part_name*.phpt: one
// multipart file part with a large name, crossing memory_limit while it is
// registered in $_FILES. Must stay valid on PHP 7.0.
//
// The part is `name="f[" + N x 'Z' + "]"; filename=""`. Up to its
// FILE_START callback the parser holds about 3 copies of the name (2 at
// FILE_START itself), so with memory_limit S above that peak the part is not
// skipped. Its registration then makes up to 13 copies of the name with
// opcache loaded (12 on PHP 7.x and 8.0, 8 without opcache), and the crossing
// happens there. The hard limit of a multipart window, with k = 11, covers
// this; the k = 6 of a urlencoded window does not:
//   H(k) = S + k x B + 8 MiB (B = post_max_size)
// N is calibrated on this build: two runs with a high memory_limit give the
// peak before FILE_START (file_uploads=0, whose native skip at FILE_START
// frees the same objects the mitigation's skip would) and the peak with the
// registration, each as a linear function of N. N is chosen so the
// registration peak is half way between H(6) and H(11), with S just above the
// FILE_START peak and B the body length, and both peaks are measured again at
// that N. The expected outcome is the deferred fatal (255). With a k of 6 for
// this window the process exits with 137.
require_once __DIR__ . '/php2shell_cgi.php';

const P2S_FPN_BOUNDARY = 'p2sNAME';
const P2S_FPN_FILES = 20;
// The headroom of S over the peak before FILE_START, and the least distance
// of the registration peak from H(6) and from H(11).
const P2S_FPN_MARGIN = 2 * P2S_MIB;
// N is at most this, so a child stays well below 512 MiB.
const P2S_FPN_MAX_N = 28 * P2S_MIB;

// Calibrate, check the precondition and run the case. Prints the lines the
// tests expect.
function p2s_fpn_case(array $env, $files_re) {
    $n1 = 2 * P2S_MIB;
    $n2 = 4 * P2S_MIB;
    $c1 = p2s_fpn_peaks($env, $n1, $files_re);
    $c2 = $c1 === null ? null : p2s_fpn_peaks($env, $n2, $files_re);
    if ($c2 === null) {
        return;
    }
    // peak(N) = a + s x N, for the peak before FILE_START (pre) and with the
    // registration (full); B = N + c.
    $s_pre = ($c2[0] - $c1[0]) / ($n2 - $n1);
    $s_full = ($c2[1] - $c1[1]) / ($n2 - $n1);
    $a_pre = $c1[0] - $s_pre * $n1;
    $a_full = $c1[1] - $s_full * $n1;
    $c = strlen(p2s_fpn_body($n1)) - $n1;
    // full(N) = (H(6) + H(11)) / 2 with S = pre(N) + margin.
    $den = $s_full - $s_pre - 8.5;
    $n = $den > 0
        ? ($a_pre - $a_full + P2S_FPN_MARGIN + 8.5 * $c + 8 * P2S_MIB) / $den
        : PHP_INT_MAX;
    $n = (int)min(max($n, $n2), P2S_FPN_MAX_N);
    $n -= $n % 65536;

    $peaks = p2s_fpn_peaks($env, $n, $files_re);
    if ($peaks === null) {
        return;
    }
    list($p_pre, $p_full) = $peaks;
    $soft_mib = (int)ceil(($p_pre + P2S_FPN_MARGIN) / P2S_MIB);
    $soft = $soft_mib * P2S_MIB;
    $bound = strlen(p2s_fpn_body($n));
    $h6 = p2s_fpn_hard($soft, $bound, 6);
    $h11 = p2s_fpn_hard($soft, $bound, 11);
    $above = $p_full >= $h6 + P2S_FPN_MARGIN;
    $below = $p_full <= $h11 - P2S_FPN_MARGIN;
    echo "precondition: peak_above_h6=", var_export($above, true),
        " peak_below_h11=", var_export($below, true), "\n";
    $diag = sprintf('n=%d s_pre=%.2f s_full=%.2f pre=%d full=%d soft=%d h6=%d h11=%d',
        $n, $s_pre, $s_full, $p_pre, $p_full, $soft, $h6, $h11);
    if (!$above || !$below) {
        echo "  $diag\n";
        return;
    }

    $r = p2s_fpn_run($env, $n, "{$soft_mib}M", true);
    $outcome = p2s_outcome($r, $soft);
    echo "outcome=$outcome status={$r['status']} continued=",
        var_export(strpos($r['out'], 'SCRIPT RAN') !== false, true), "\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r, $diag);
    }
}

function p2s_fpn_body($n) {
    return p2s_multipart_body([['f[' . str_repeat('Z', $n) . ']', '', '']], P2S_FPN_BOUNDARY);
}

function p2s_fpn_hard($soft, $bound, $k) {
    return $soft + $k * $bound + 8 * P2S_MIB;
}

// Run the child with a name of $n bytes. $env is the CGI environment of the
// request (its method and script); post_max_size is the body length.
function p2s_fpn_run(array $env, $n, $memory_limit, $file_uploads) {
    $body = p2s_fpn_body($n);
    return p2s_cgi_run(p2s_opcache_ini() + [
        'memory_limit' => (string)$memory_limit,
        'post_max_size' => (string)strlen($body),
        'max_file_uploads' => (string)P2S_FPN_FILES,
        'file_uploads' => $file_uploads ? '1' : '0',
    ], [], p2s_multipart_env(P2S_FPN_BOUNDARY) + $env, $body);
}

// The peaks [before FILE_START, with the registration] at a name of $n bytes,
// with memory_limit=512M, or null after printing a diagnostic. $files_re
// matches the child's report of one registered file part.
function p2s_fpn_peaks(array $env, $n, $files_re) {
    $pre = p2s_fpn_run($env, $n, '512M', false);
    $full = p2s_fpn_run($env, $n, '512M', true);
    $p_pre = p2s_peak($pre);
    $p_full = p2s_peak($full);
    if ($p_pre === null || $p_full === null || !preg_match($files_re, $full['out'])) {
        echo "calibration failed at n=$n\n";
        p2s_dump($pre, 'file_uploads=0');
        p2s_dump($full, 'file_uploads=1');
        return null;
    }
    return [$p_pre, $p_full];
}
