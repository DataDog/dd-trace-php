<?php

function one() {
    \DDTrace\set_user($_GET['id'], []);
}

function two() {
    one();
}

function three() {
    two();
}

three();

echo "Done :)";
