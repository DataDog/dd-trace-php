<?php

function aaaa($password)
{
    $ex = new Exception("Exception generated in external file");
    // echo sprintf("----> %s in %s:%d\n", $ex->getMessage(), $ex->getFile(), $ex->getLine());
    // echo $ex->getTraceAsString() . "\n";
    // echo sprintf("#################### %s in %s:%d\n", $ex->getMessage(), $ex->getFile(), $ex->getLine());
    // echo ">>>>>>>>>>>>>>>" . var_export($ex->getTrace(), 1) . "<<<<<<<<<<<<<<<<<<<<<<\n";
    error_log('Traceeeeeeeeeee: ' . var_export($ex->getTrace(), 1));
    throw $ex;
}


aaaa("secret");

// // $a = new Exception("AAAAAAAAAA");
// // echo "# 1";
// // $b = new Exception();
// // echo "# 2";
// // throw new Exception("Exception generated in external file");

// try {
//     throw new Exception("Exception generated in external file");
// } catch (Exception $ex) {
//     echo "Hi";
// }

// echo "After";
