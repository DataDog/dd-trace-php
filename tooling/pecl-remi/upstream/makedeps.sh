#!/bin/sh
EXT=datadog_trace

if [ $# -lt 1 ]; then
	echo usage $0 version
	exit 0
fi
if [ -f $EXT-$1.tgz ]; then
	echo "+ Unpack"
	tar xf $EXT-$1.tgz  $EXT-$1

	pushd  $EXT-$1
		echo "+ Fetch"
		cargo vendor mycargo

		echo "+ Pack"
		tar czf ../$EXT-deps-$1.tgz mycargo
	popd

	echo "+ Clean"
	rm -rf $EXT-$1
else
	echo $EXT-$version.tgz missing
fi
