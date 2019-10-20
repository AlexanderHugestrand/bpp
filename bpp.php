<?php

require_once __DIR__.'/source/preprocessor.php';

if (count($argv) < 2) {
    echo "Not enough arguments given.\n";
    echo "Usage: preprocessor.php inputfile\n";
    exit -1;
}

$preProcessor = new Preprocessor();
$output = $preProcessor->parseFile($argv[1]);

echo "Preprocessed:\n";
echo "'$output'\n";
