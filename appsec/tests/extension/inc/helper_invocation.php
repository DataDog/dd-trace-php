<?php

fwrite(STDERR, "Checking open file descriptors\n");

(function() {
for ($i = 0; $i <= 2; $i++) {
	$f = @fopen("php://fd/$i", "w+");
	if ($f) {
		fwrite(STDERR, "* has file descriptor $i\n");
	}
	fclose($f);
}
echo "\n";
})();

pcntl_sigprocmask(SIG_SETMASK, array(), $oldset);

fwrite(STDERR, "Checking procmask\n");
fwrite(STDERR, var_export($oldset, true));
fwrite(STDERR, "\n");

fwrite(STDERR, "Checking umask\n");
$old_umask = umask(0);
fwrite(STDERR, var_export($old_umask, true));
fwrite(STDERR, "\n");

fwrite(STDERR, "Checking parent uid (should be 1)\n");
fwrite(STDERR, var_export(posix_getppid(), true));
fwrite(STDERR, "\n");


fwrite(STDERR, "Checking process group id == pid (is a process group leader)\n");
if (posix_getpgrp() != getmypid()) {
	fwrite(STDERR, 'mismatch grpid ' . posix_getpgrp() . ' pid ' . getmypid() . "\n");
} else {
	fwrite(STDERR, "OK\n");
}

fwrite(STDERR, "Checking session id == pid (is a session leader)\n");
if (posix_getsid(0) != getmypid()) {
	fwrite(STDERR, 'mismatch sid ' . posix_getsid(0) . ' pid ' . getmypid() . "\n");
} else {
	fwrite(STDERR, "OK\n");
}
fwrite(STDERR, "\n");


fwrite(STDERR, "Checking socket id\n");
$x = array_filter($_SERVER['argv'], function ($a) { return strpos($a, 'fd:') === 0; });
$x = reset($x);
$fd = substr($x, 3);
fwrite(STDERR, "file descriptor is $fd\n");

$sock = fopen("php://fd/$fd", 'w+');
if ($sock === false) {
	fwrite(STDERR, "error fopening fd $fd\n");
	die;
} else {
	fwrite(STDERR, "opened fd $fd\n");
}
fwrite(STDERR, "\n");

fwrite(STDERR, "Accepting a connection:\n");
$ssock = socket_import_stream($sock);
if ($ssock === false) {
	fwrite(STDERR, "error for socket_import_stream\n");
	die;
}
$csock = socket_accept($ssock);
fwrite(STDERR, "accepted a new connection\n");
$cstream = socket_export_stream($csock);

$header = fread($cstream, 8);
$len = unpack('L', substr($header, 4))[1];
fwrite(STDERR, "read initial message from extension (size $len)\n");
fread($cstream, $len);
fwrite(STDERR, "read remaining data");

fflush(STDERR);

$version = $argv[1];
//The message supposing version is 0.4.0 is [["client_init", ["ok", "0.4.0", [], {}, {}]]]
$message = "\x91\x92". //[[
            "\xABclient_init". //"client_init"
            "\x95". // [
            "\xA2ok". //"ok"
            chr(0xA0 + strlen($version)) . $version . //"0.4.0"
            "\x90\x80\x80"; // [], {}, {}]]]

$data = "dds\0" .
	chr(strlen($message)) . "\x00\x00\x00" . // length in little-endian
	$message;

fwrite($cstream, $data);
