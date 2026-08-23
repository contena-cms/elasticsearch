<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use OpenSearch\Exception\BadRequestHttpException;
use Contena\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class ElasticsearchBlogException extends HttpException
{
    public const ES_BLOG_CONFIG_NOT_FOUND = 'ELASTICSEARCH_BLOG__CONFIGURATION_NOT_FOUND';
    public const ES_BLOG_CANNOT_CHANGE_CUSTOM_FIELD_TYPE = 'ELASTICSEARCH_BLOG__CANNOT_CHANGE_CUSTOM_FIELD_TYPE';
    public const ES_BLOG_CANNOT_CHANGE_FIELD_TYPE = 'ELASTICSEARCH_BLOG__CANNOT_CHANGE_FIELD_TYPE';

    public static function configNotFound(): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::ES_BLOG_CONFIG_NOT_FOUND,
            'Configuration for blog elasticsearch definition not found',
        );
    }

    public static function cannotChangeFieldType(BadRequestHttpException $previous): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ES_BLOG_CANNOT_CHANGE_FIELD_TYPE,
            'One or more fields already exist in the index with different types. Please reset the index and rebuild it.',
            [],
            $previous,
        );
    }

    public static function cannotChangeCustomFieldType(BadRequestHttpException $previous): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ES_BLOG_CANNOT_CHANGE_CUSTOM_FIELD_TYPE,
            'One or more custom fields already exist in the index with different types. Please reset the index and rebuild it.',
            [],
            $previous,
        );
    }
}
