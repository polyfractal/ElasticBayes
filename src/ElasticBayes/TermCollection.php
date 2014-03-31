<?php
namespace ElasticBayes;


use Elasticsearch\Client;
use LRUCache\LRUCache;

/**
 * Class TermCollection
 * This class represents the bag of terms in a particular piece of text.
 * It provides functionality to turn that bag of terms into Naive Bayes
 * predictions
 */
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

    /**
     * sets the field that we will collect labels from.
     * In the reuters dataset, this will be either 'topics' or 'places'
     */
    public function setLabelField($labelField) {
        $this->labelField = $labelField;
    }

    /**
     * sets the field where we will collect terms from
     * In the reuters dataset, this will be 'body', 'title', or 'combined'
     */
    public function setTextField($textField) {
        $this->textField = $textField;
    }

    /**
     * given an input text, we need to analyze it and then collect
     * stats for each term
     */
    public function collectTerms($data) {

        // Get the post-analysis terms from this input text
        $terms = $this->getTerms($data);

        // And farm out the stat collection to TermStats object
        foreach ($terms as $term) {
            $t = new TermStats($this->client, $this->termLRU, $term['token'], count($this->labelStats));
            $t->collectStats( $this->labelField, $this->textField);
            $this->terms[] = $t;
        }
    }

    /**
     * This is where all the Naive Bayes magic happens.
     * This function returns the predicted label score for the
     * current collection of terms
     *
     * For a good 'layman' explanation, see: http://burakkanber.com/blog/machine-learning-naive-bayes-1/
     */
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
            // In reality, this term often hurts prediction accuracy, but I left it in to show hot it is done
            //$pH = $this->labelStats[$label]['prob'];
            $pH = 1;

            //$posteriori = ($pXH * $pH) / ($pXH + $pNotXH + 0.0001);
            // ^^^ Technically the 'accurate' formula, but the denominator is always 1
            $posteriori = ($pXH * $pH);

            // Normalize rarely seen terms.  This also tends to hurt prediction accuracy imo
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

    /**
     * Performs a call to /_analyze, so that we can retrieve
     * a collection of post-analysis terms
     */
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