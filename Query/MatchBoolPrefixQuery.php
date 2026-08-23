<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Query;

use OpenSearchDSL\Query\FullText\MatchQuery;

/**
 * Represents Elasticsearch "match_bool_prefix" query.
 *
 * Analyzes the input and creates a bool query from the terms, treating the last term as a prefix.
 * Unlike PrefixQuery (which uses ConstantScore), this uses BM25 scoring so that
 * similarity settings (like b=0 for disabling field-length normalization) actually affect the score.
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-match-bool-prefix-query.html
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
class MatchBoolPrefixQuery extends MatchQuery
{
    public function getType(): string
    {
        return 'match_bool_prefix';
    }
}
