<?php

require 'vendor/autoload.php';

$client = new \Elasticsearch\Client();
$bayes = new \ElasticBayes\ElasticBayes('topics');

$correctPredictions = 0;
$numTest = 0;
for ($i = 17; $i < 22; ++$i) {
    $dir = realpath(dirname(__FILE__));
    $fileNum = sprintf('%03d', $i);
    $data = file_get_contents("$dir/reuters-21578-json/reuters-$fileNum.json");
    $data = json_decode($data, true);

    foreach ($data as $doc) {
        if (isset($doc['topics']) !== true || isset($doc['body']) !== true) {
            continue;
        }

        $numTest += 1;
        $testText = $doc['body'];


        $scores = $bayes->predict($testText, 'body');

        $predicted = implode(",", array_keys(array_slice($scores, 0, count($doc['topics'])+5)));
        $actual = implode(",",$doc['topics']);

        if ($actual[0] == $predicted[0]) {
            $correctPredictions += 1;
        }

        echo "$numTest:  P:[$predicted]  A:[$actual]\n";
    }

    $accuracy = $correctPredictions / $numTest;
    echo "Final Accuracy: $accuracy\n";


}


