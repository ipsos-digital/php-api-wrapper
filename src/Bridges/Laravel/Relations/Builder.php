<?php

namespace Cristal\ApiWrapper\Bridges\Laravel\Relations;

use Cristal\ApiWrapper\Builder as CoreBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

class Builder extends CoreBuilder
{
    /**
     * @return array|CoreBuilder[]
     */
    public function get($columns = ['*'])
    {
        return collect(parent::get($columns));
    }

    /**
     * @param null $perPage
     * @param array $columns
     * @param string $pageName
     * @param null $page
     * @return LengthAwarePaginator
     */
    public function paginate(?int $perPage = null, ?int $page = 1)
    {
        $entities = parent::paginate($perPage, $page);

        return new LengthAwarePaginator(
            $entities['data'],
            $entities['total'],
            $entities['per_page'],
            $entities['current_page'],
            isset($entities['options']) ? $entities['options'] : []
        );
    }

    /**
     * @param $data
     * @return array|null
     */
    public function instanciateModels($data)
    {
        return parent::instanciateModels($data['hydra:member'] ?? $data['data'] ?? $data ?? null);
    }
}
