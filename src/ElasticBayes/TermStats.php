<?php

namespace ElasticBayes;

use Elasticsearch\Client;
use LRUCache\LRUCache;

/**
 * Class TermStats
 * This class represents a single term and it's associated statistics
 */
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

    /**
     * Collect the statistics for this term.
     * Stats include total doc count and count-per-label
     */
    public function collectStats($labelField, $textField) {

        // We are using an LRU cache so we don't have to whack ES on every term
        // If it is in the cache, use it, otherwise ask ES politely
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

        /**
         * This query is the main guts of the NaiveBayes statistics.  It:
         *  - finds all documents containing the term (note: post-analysis term filter)
         *  - terms agg over the labels to get label counts for this term
         */
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

        // Logistics to make the counts usable
        $this->numDocsWithTerm = $results['hits']['total'];
        $this->labelCounts = array_fill(0, $this->labelCardinality, 0);
        foreach ($results['aggregations']['counts']['buckets'] as $bucket) {
            $this->labelCounts[$bucket['key']] = $bucket['doc_count'];
        }

        // Stick it in the cache for future use
        $this->termLRU->put($this->term, serialize($results));
    }

    /**
     * Return the probability that this label has this term
     */
    public function getLabelProb($label) {
        if (isset($this->labelCounts[$label]) === false) {
            return 0;
        }
        return $this->labelCounts[$label] / $this->numDocsWithTerm;
    }

    /**
     * Return the probability that all other labels have this term
     */
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