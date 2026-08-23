<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Framework\Exception;

use Contena\Elasticsearch\ElasticsearchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @codeCoverageIgnore
 */
class EmptyQueryException extends ElasticsearchException
{
    public function __construct()
    {
        parent::__construct(Response::HTTP_INTERNAL_SERVER_ERROR, self::EMPTY_QUERY, 'Empty query provided');
    }
}
