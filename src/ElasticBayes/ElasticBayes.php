<?php

namespace ElasticBayes;


use Elasticsearch\Client;

class ElasticBayes {
    /** @var  Client */
    private $client;
    private $totalDocCount = 0;
    private $labels = [];
    private $field;

    /** @var \LRUCache\LRUCache  */
    private $termLRU;

    public function __construct($labelField) {
        $this->client = new Client();
        $this->field = $labelField;
        $this->getLabelCounts();
        $this->termLRU = new \LRUCache\LRUCache(10000);
    }

    public function predict($data, $textField, $normalize = true) {
        $termCollection = new TermCollection($this->client, $this->termLRU, $this->labels, $data);
        $termCollection->setLabelField($this->field);
        $termCollection->setTextField($textField);
        $termCollection->collectTerms($data);

        $scores = [];
        foreach ($this->labels as $label => $labelStats) {
            $scores[$label] = $termCollection->scoreLabel($label, $textField);
        }

        arsort($scores);
        return $normalize ? $this->normalize($scores) : $scores;

    }

    private function normalize($data) {
        $max = max($data);
        if ($max == 0) {
            $evenDistro = 100 / count($data);
            foreach ($data as $i => $v) {
                $data[$i] = $evenDistro;
            }
        } else {
            foreach ($data as $i => $v) {
                $data[$i] = ($v / $max) * 100;
            }
        }

        return $data;
    }

    private function getLabelCounts() {

        $params = [
            'index' => 'reuters',
            'type' => 'train',
            'search_type' => 'count',
            'body' => [
                'aggs' => [
                    'counts' => [
                        'terms' => [
                            'field' => $this->field,
                            'size' => 200
                        ]
                    ]
                ]
            ]
        ];

        $results = $this->client->search($params);

        $this->totalDocCount = $results['hits']['total'];
        foreach ($results['aggregations']['counts']['buckets'] as $bucket) {
            $this->labels[$bucket['key']]['count'] = $bucket['doc_count'];
            $this->labels[$bucket['key']]['prob'] = $bucket['doc_count'] / $this->totalDocCount;
        }
    }

}