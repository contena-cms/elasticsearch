<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\DataAbstractionLayer;

use Contena\Core\Framework\Context;
use Contena\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Contena\Core\Framework\Plugin\Exception\DecorationPatternException;

class ElasticsearchEntitySearchHydrator extends AbstractElasticsearchSearchHydrator
{
    public function getDecorated(): AbstractElasticsearchSearchHydrator
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @param array{
     *     hits?: array{
     *         hits: array<int, array{
     *             _id?: string,
     *             _score?: float,
     *             _source?: array<mixed>,
     *             matched_queries?: array<string, float>,
     *             inner_hits?: array{inner?: array<mixed>}
     *         }>,
     *         total?: array{value: int}
     *     },
     *     aggregations?: array<string, array<string, mixed>>
     *  } $result
     */
    public function hydrate(EntityDefinition $definition, Criteria $criteria, Context $context, array $result): IdSearchResult
    {
        if (!isset($result['hits'])) {
            return new IdSearchResult(0, [], $criteria, $context);
        }

        $hits = $this->extractHits($result);

        $data = [];
        foreach ($hits as $hit) {
            $id = $hit['_id'];

            $data[$id] = [
                'primaryKey' => $id,
                'data' => array_merge(
                    $hit['_source'] ?? [],
                    ['id' => $id, '_score' => $hit['_score']],
                    // In explain mode ES reports the per-clause scores
                    // (include_named_queries_score) — pass them through so the admin
                    // live-search preview can explain the ranking. Gated on the state so a
                    // third-party named query in a normal search can't leak its clause list
                    // into the store-api search extension.
                    $context->hasState(Context::ELASTICSEARCH_EXPLAIN_MODE) && isset($hit['matched_queries'])
                        ? ['matched_queries' => $hit['matched_queries']]
                        : [],
                ),
            ];
        }

        $total = $this->getTotalValue($criteria, $result);
        if ($criteria->useIdSorting()) {
            $data = $this->sortByIdArray($criteria->getIds(), $data);
        }

        return new IdSearchResult($total, $data, $criteria, $context);
    }

    /**
     * @param array{ hits: array{ hits: array<int, array<string, mixed>>, total?: array{value: int}}, aggregations?: array<string, array<string, mixed>>} $result
     *
     * @return array<mixed>
     */
    private function extractHits(array $result): array
    {
        $records = [];
        $hits = $result['hits']['hits'];

        foreach ($hits as $hit) {
            if (!isset($hit['inner_hits']['inner'])) {
                $records[] = $hit;

                continue;
            }

            /** @var array{ hits: array{ hits: array<int, array<string, mixed>>}} $inner */
            $inner = $hit['inner_hits']['inner'];

            $innerHits = $this->extractHits($inner);

            foreach ($innerHits as $innerHit) {
                $records[] = $innerHit;
            }
        }

        return $records;
    }

    /**
     * @param array{ hits: array{ hits: array<mixed>, total?: array{ value: int } }, aggregations?: array<string, array<string, mixed>>} $result
     */
    private function getTotalValue(Criteria $criteria, array $result): int
    {
        if ($criteria->getTotalCountMode() !== Criteria::TOTAL_COUNT_MODE_EXACT) {
            return \count($result['hits']['hits'] ?? []);
        }

        if (!$criteria->getGroupFields()) {
            return (int) ($result['hits']['total']['value'] ?? 0);
        }

        if (!$criteria->getPostFilters()) {
            return (int) ($result['aggregations']['total-count']['value'] ?? 0);
        }

        return (int) ($result['aggregations']['total-filtered-count']['total-count']['value'] ?? 0);
    }

    /**
     * @param array<string|array<string>> $ids
     * @param array<string, array{primaryKey: string|array<string, string>, data: array<string, mixed>}> $data
     *
     * @return array<string, array{primaryKey: string|array<string, string>, data: array<string, mixed>}>
     */
    private function sortByIdArray(array $ids, array $data): array
    {
        $sorted = [];

        foreach ($ids as $id) {
            if (\is_array($id)) {
                $id = implode('-', $id);
            }

            if (\array_key_exists($id, $data)) {
                $sorted[$id] = $data[$id];
            }
        }

        return $sorted;
    }
}
