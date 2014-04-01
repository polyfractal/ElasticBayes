## Overview
This is a fun proof-of-concept Naive Bayes classifier built using Elasticsearch aggregations.  

Naive Bayes classifiers work based on term and document frequencies differences between class labels.  Elasticsearch is a search engine which is designed to handle term/doc frequencies and aggregating across features (such as labels), so this seemed like a natural fit.

Most of the code is plumbing...the actual classification only requires two different aggregation queries.

### Dataset
This project uses a subtree-split of the [Reuters-21578-JSON dataset](https://github.com/fergiemcdowall/reuters-21578-json).  This is the classic Reuters dataset which has been jsonified.

Note: the newer Reuters RCV1 would have been a better candidate for classification, but I did not feel like writing an XML parser for it :)

### Scripts

- `importReuters.php` : imports the Reuters dataset into Elasticsearch.  Data will go into `/reuters/train` and `/reuters/test`
- `testReuters.php` : begins the classification on the test data
- `/src/ElasticBayes/` : the NaiveBayes classification implementation using Elasticsearch

### Running this example locally

```shell
git clone https://github.com/polyfractal/ElasticBayes.git
curl -s http://getcomposer.org/installer | php
php composer.phar install
php importReuters.php
php testReuters.php
```

## Training
Training the classifier is simple.  Here are the steps:

1. Create an index with an appropriate mapping
2. Index the documents from your corpus
3. There is no step 3

Naive Bayes classifiers are "trained" by obtaining term and document frequencies for each label.  This is typically done by tokenizing your input, normalizing the tokens, then building an in-memory hash which holds all the data.

Elasticsearch does this natively out of the box.  Just create an analyzer and index documents.  Voila!  Trained Naive Bayes classifier

## Testing
Once the clssifier has been "trained", we can use it to predict the classes of new documents.  Rather than walk through all the code, I am going to show the salient Elasticsearch queries and explain their purpose.  The rest of the code should be trivial to understand, since it is just logistical plumbing.

There are three fields and two sets of labels that you can potentially test on:

**Labels**
- `topics`
- `places`

**Input Fields**
- `body`
- `title`
- `combined` - both `body` and `title` concatenated into a single field

#### Total label counts
This query obtains the set of labels and their counts.

```php
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
```

#### Text analysis
This query takes an input text and passes it through the analyzer for the field we are interested in.  This will return a list of post-analysis tokens that we can use to build statistics on.

For example, the input "The Quick brown fox" might return the tokens: [`quick`, `brown`, `fox`].

This step is important since we need the post-analysis version of the token.  For simple analyzers this is trivial to do in application code, but more advanced analyzers which include stemming, stopword remove, negation tagging, etc require this analysis step

```php
$params = [
    'index' => 'reuters',
    'field' => $this->textField,
    'body' => $data
];

$terms = $this->client->indices()->analyze($params);
```

#### Term Statistics
This query is executed once for each term in the input document.  It first filters
out all documents which do not have our term (note: term is post-analysis, so we can safely use a term filter and benefit from caching).

The query then performs an aggregation that collects the document counts for each label.

The data that this query returns is the label counts for the term we are interested in.
```php
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
```

#### Naive Bayes Math
Once we have all the data from the above queries, we can very easily calculate the probability that a term belongs to a certain label.

The code is fairly well commented and should be self-explanatory.  For a more laymen's explanation, [see this excellent tutorial](http://burakkanber.com/blog/machine-learning-naive-bayes-1/).

```php
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
```

### Performance (Speed)
The code in this project is almost certainly not optimized for maximum performance.  Firstly, it is written in PHP :P  It is evident while running tests that PHP is the bottleneck and not Elasticearch.

This code utilizes an LRU cache to keep frequently used terms cached in memory, which helps offest the PHP tax.

With that said, the classifier can crank out predictions at an appreciable rate, even with the PHP tax.  It would be perfectly usable as an online, one-pass classifier as data streams into your system.

### Classification Performance
Classification performance is moderate to good.  While playing with the dataset, I obtained accuracies ranging from 0.56 to 0.69.
Admittedly, I didn't spend much time fiddling...this was mostly a proof-of-concept to implement a NaiveBayes in Elasticsearch,
not to build a good classification model for Reuters.

Classification accuracy boils down to intelligent preprocessing and normalization.  Some potential routes to take:

- Stemming (kstem, snowball, porter stemmer)
- Stopword removal
- Shingles
- Frequency filtering
- Negation tagging

Note: Accuracy is generally a poor metric to optimize, especially in an unbalanced dataset.  Double-especially on multi-class datasets like Reuters.  This script simply calculates the accuracy of the top prediction and label.  It does not inspect the rest of the labels, or the ordering of predictions.

Since this is not a rigorous paper, accuracy was the easiest/fastest to calculate.  Better metrics would include AUC, micro/macro F-measures and Balanced Error Rate.