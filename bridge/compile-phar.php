<?php
// The php.ini setting phar.readonly must be set to 0
$pharFile = 'app.phar';

// clean up
if (file_exists($pharFile)) {
    unlink($pharFile);
}
if (file_exists($pharFile . '.gz')) {
    unlink($pharFile . '.gz');
}

// create phar
$p = new Phar($pharFile);

$p->delMetadata();

// creating our library using whole directory
$p->buildFromDirectory('../src/DDTrace');

// pointing main file which requires all classes
// $p->setDefaultStub('index.php', 'index.php');

// plus - compressing it into gzip
// $p->compress(Phar::GZ);

echo "$pharFile successfully created\n";
