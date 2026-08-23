<?php declare(strict_types=1);

namespace Contena\Elasticsearch\Blog;

use Contena\Core\Content\Blog\BlogDefinition;
use Contena\Core\Content\Blog\SearchKeyword\BlogSearchBuilderInterface;
use Contena\Core\Framework\Adapter\Request\RequestParamHelper;
use Contena\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Contena\Core\Framework\Routing\RoutingException;
use Contena\Core\System\Channel\ChannelContext;
use Contena\Elasticsearch\Framework\ElasticsearchHelper;
use Symfony\Component\HttpFoundation\Request;

class BlogSearchBuilder implements BlogSearchBuilderInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly BlogSearchBuilderInterface $decorated,
        private readonly ElasticsearchHelper $helper,
        private readonly BlogDefinition $blogDefinition,
        private readonly int $searchTermMaxLength = 300
    ) {
    }

    public function build(Request $request, Criteria $criteria, ChannelContext $context): void
    {
        if (!$this->helper->allowSearch($this->blogDefinition, $context->getContext(), $criteria)) {
            $this->decorated->build($request, $criteria, $context);

            return;
        }

        $search = RequestParamHelper::get($request, 'search');

        $term = \is_array($search) ? implode(' ', $search) : (string) $search;

        $term = mb_substr(trim($term), 0, $this->searchTermMaxLength);

        if ($term === '') {
            throw RoutingException::missingRequestParameter('search');
        }

        // reset queries and set term to criteria.
        $criteria->resetQueries();

        // elasticsearch will interpret this on demand
        $criteria->setTerm($term);
    }
}
