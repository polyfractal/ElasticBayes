<?php
namespace ElasticBayes;


use Elasticsearch\Client;
use LRUCache\LRUCache;

class TermCollection {
    /** @var  \Elasticsearch\Client */
    private $client;

    /** @var TermStats[] */
    private $terms = [];

    private $labelStats = [];
    private $textField;
    private $labelField;

    /** @var \LRUCache\LRUCache  */
    private $termLRU;

    public function __construct(Client &$client, LRUCache &$termLRU, $labelStats) {
        $this->client = $client;
        $this->labelStats = $labelStats;
        $this->termLRU = $termLRU;
    }

    public function setLabelField($labelField) {
        $this->labelField = $labelField;
    }

    public function setTextField($textField) {
        $this->textField = $textField;
    }

    public function collectTerms($data) {
        $terms = $this->getTerms($data);

        foreach ($terms as $term) {
            $t = new TermStats($this->client, $this->termLRU, $term['token'], count($this->labelStats));
            $t->collectStats( $this->labelField, $this->textField);
            $this->terms[] = $t;
        }
    }

    public function scoreLabel($label) {

        $logSum = 0;
        foreach ($this->terms as $term) {
            $termCount = $term->getTermCount();
            if ($termCount === 0) {
                // Ignore terms that we have never seen before
                continue;
            }

            // a posteriori probability of $term conditioned on $label
            // (how often does $term occur in $label ?)
            $pXH = $term->getLabelProb($label);

            // a posteriori probability of $term conditioned on all other labels
            // (how often does $term occur in other labels?)
            $pNotXH = $term->getInverseLabelProb($label);

            // a priori probability of $label
            // (how many docs have this label?)
            //$pH = $this->labelStats[$label]['prob'];
            $pH = 1;

            //$posteriori = ($pXH * $pH) / ($pXH + $pNotXH + 0.0001);
            $posteriori = ($pXH * $pH);

            // Normalize rarely seen terms
            //$posteriori = ( ( 5 * 0.5) + ($termCount * $posteriori) ) / ( 5 + $termCount);
            if ($posteriori === 0) {
                $posteriori = 0.00001;
            } elseif ($posteriori === 1) {
                $posteriori = 0.99999;
            }

            $logSum += log(1 - $posteriori) - log($posteriori);
        }

        return 1 / ( 1 + exp($logSum));
    }

    private function getTerms($data) {
        $params = [
            'index' => 'reuters',
            'field' => $this->textField,
            'body' => $data
        ];

        $terms = $this->client->indices()->analyze($params);

        return $terms['tokens'];

    }
}