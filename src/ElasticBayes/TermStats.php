<?php

namespace ElasticBayes;


use Elasticsearch\Client;
use LRUCache\LRUCache;

class TermStats {
    /** @var  \Elasticsearch\Client */
    private $client;
    private $term;
    private $labelCounts = [];
    private $numDocsWithTerm;
    private $labelCardinality;

    /** @var \LRUCache\LRUCache  */
    private $termLRU;

    public function __construct(Client &$client, LRUCache &$termLRU, $term, $cardinality) {
        $this->term = $term;
        $this->client = $client;
        $this->labelCardinality = $cardinality;
        $this->termLRU = $termLRU;
    }

    public function collectStats($labelField, $textField) {


        $cached = $this->termLRU->get($this->term);
        if ($cached !== null) {
            $cached = unserialize($cached);
            $this->numDocsWithTerm = $cached['hits']['total'];
            $this->labelCounts = array_fill(0, $this->labelCardinality, 0);
            foreach ($cached['aggregations']['counts']['buckets'] as $bucket) {
                $this->labelCounts[$bucket['key']] = $bucket['doc_count'];
            }
            return;
        }

        $params = [
            'index' => 'reuters',
            'type' => 'train',
            'search_type' => 'count',
            'body' => [
                'query' => [
                    'filtered' => [
                        'filter' => [
                            'term' => [
                                $textField => $this->term
                            ]
                        ]
                    ]
                ],
                'aggs' => [
                    'counts' => [
                        'terms' => [
                            'field' => $labelField,
                            'size' => 200
                        ]
                    ]
                ]
            ]
        ];
        $results = $this->client->search($params);

        $this->numDocsWithTerm = $results['hits']['total'];
        $this->labelCounts = array_fill(0, $this->labelCardinality, 0);
        foreach ($results['aggregations']['counts']['buckets'] as $bucket) {
            $this->labelCounts[$bucket['key']] = $bucket['doc_count'];
        }

        $this->termLRU->put($this->term, serialize($results));
    }

    public function getLabelProb($label) {
        if (isset($this->labelCounts[$label]) === false) {
            return 0;
        }
        return $this->labelCounts[$label] / $this->numDocsWithTerm;
    }

    public function getInverseLabelProb($label) {
        if (isset($this->labelCounts[$label]) === false) {
            return 0;
        }
        return ($this->numDocsWithTerm - $this->labelCounts[$label]) / $this->numDocsWithTerm;
    }

    public function getTermCount() {
        return $this->numDocsWithTerm;
    }


}