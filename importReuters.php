<?php
$loader = require 'vendor/autoload.php';

$client = new \Elasticsearch\Client();


$client->indices()->delete(['index' => 'reuters', 'ignore' => 404]);
$params = ['index' => 'reuters', 'body' => [
    'settings' => [
        'number_of_shards' => 1,
        'number_of_replicas' => 0,
        'analysis' => [
            'filter' => [
                'shingle' => [
                    'type' => 'shingle'
                ]
            ],
            'char_filter' => [
                'pre_negs' => [
                    'type' => 'pattern_replace',
                    'pattern' => '(\\w+)\\s+((?i:never|no|nothing|nowhere|noone|none|not|havent|hasnt|hadnt|cant|couldnt|shouldnt|wont|wouldnt|dont|doesnt|didnt|isnt|arent|aint))\\b',
                    'replacement' => '~$1 $2'
                ],
                'post_negs' => [
                    'type' => 'pattern_replace',
                    'pattern' => '\\b((?i:never|no|nothing|nowhere|noone|none|not|havent|hasnt|hadnt|cant|couldnt|shouldnt|wont|wouldnt|dont|doesnt|didnt|isnt|arent|aint))\\s+(\\w+)',
                    'replacement' => '$1 ~$2'
                ]
            ],
            'analyzer' => [
                'reuters' => [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase', 'stop', 'kstem']
                ]
            ]
        ]
    ],
    'mappings' => [
        '_default_' => [
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'analyzer' => 'reuters',
                    'term_vector' => 'yes',
                    'copy_to' => 'combined'
                ],
                'body' => [
                    'type' => 'string',
                    'analyzer' => 'reuters',
                    'term_vector' => 'yes',
                    'copy_to' => 'combined'
                ],
                'combined' => [
                    'type' => 'string',
                    'analyzer' => 'reuters',
                    'term_vector' => 'yes'
                ],
                'topics' => [
                    'type' => 'string',
                    'index' => 'not_analyzed'
                ],
                'places' => [
                    'type' => 'string',
                    'index' => 'not_analyzed'
                ]
            ]
        ]
    ]
]];
$client->indices()->create($params);

$params = [];
for ($i = 0; $i < 17; ++$i) {
    $dir = realpath(dirname(__FILE__));
    $fileNum = sprintf('%03d', $i);
    $data = file_get_contents("$dir/reuters-21578-json/reuters-$fileNum.json");
    $data = json_decode($data, true);

    foreach ($data as $doc) {
        $params['body'][] = array('index' => array());
        $params['body'][] = $doc;
    }

    $params['index'] = 'reuters';
    $params['type'] = 'train';
    $client->bulk($params);
    echo "\n$i";
    $params = array();

}

for ($i = 17; $i < 22; ++$i) {
    $dir = realpath(dirname(__FILE__));
    $fileNum = sprintf('%03d', $i);
    $data = file_get_contents("$dir/reuters-21578-json/reuters-$fileNum.json");
    $data = json_decode($data, true);

    foreach ($data as $doc) {
        $params['body'][] = array('index' => array());
        $params['body'][] = $doc;
    }

    $params['index'] = 'reuters';
    $params['type'] = 'test';
    $client->bulk($params);
    echo "\n$i";
    $params = array();

}
