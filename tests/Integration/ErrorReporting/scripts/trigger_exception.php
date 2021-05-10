<?php

// $a = new Exception("AAAAAAAAAA");
// echo "# 1";
// $b = new Exception();
// echo "# 2";
// throw new Exception("Exception generated in external file");

try {
    throw new Exception("Exception generated in external file");
} catch (Exception $ex) {
    echo "Hi";
}

echo "After";
