<?php

namespace APPA\Crud;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CrudService
{
    // Repository class that used for this service
    protected CrudRepository $repo;

    // Only relations defined is allowed
    protected array $allowedRelations = [];

    // Only relations defined is allowed to used for filters
    protected array $filterable = [];

    // Filters that used for each query
    protected Collection $filters;

    // Limit per page used for pagination
    protected int $limit = 50;

    // Sort columns: eg: id => desc, name => asc
    protected array $sorts = [];

    // Path that used for image uploading
    protected string $uploadPath = '';

    // Disk that it uploads image to
    protected string $uploadDisk = 'public';

    // Available filter operators
    private array $allowedOperators = [
        '=', '>', '<', '>=', '<=', 'like', 'contain', 'between', 'date', 'exists', 'not_has'
    ];

    // Request parameter that used to query filters
    private string $filterOptionKey = 'filter_options';

    // Request parameter that used to query relations
    private string $relationsKey = 'relations';

    // Request parameter that used to query relation counts
    private string $countsKey = 'counts';

    /**
     * Constructor of CurdService
     */
    public function __construct(CrudRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * This value will we used to filter in every query using the service
     */
    public function excludes()
    {
        return [];
    }

    /**
     * Get filters
     *
     * @return Collection
     */
    public function getFilters(): array
    {
        return $this->filters->toArray();
    }

    /**
     * Check if operator is valid
     *
     * @param string $operator
     * @return bool
     */
    protected function isValidOperator(string $operator): bool
    {
        return in_array($operator, $this->allowedOperators);
    }

    /**
     * Get query operator
     *
     * @param string $value
     * @return string
     */
    protected function getOperator(string $value = '='): string
    {
        return $this->isValidOperator($value) ? $value : $this->isValidOperator($value);
    }

    /**
     * Append one filter option to current filters
     *
     * @param string $field
     * @param string $operator
     * @param string|null $value
     * @return Collection
     */
    protected function appendFilters(string $field, string $operator, $value = null): Collection
    {
        if (!$this->isValidOperator($operator)) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Invalid filter operator');
        }

        $this->filters->push([
            'field' => $field,
            'value' => $value,
            'operator' => $operator,
        ]);

        return $this->filters;
    }

    /**
     * If $filterable has content, only field within $filterable is allowed
     * @param string $field
     */
    private function hasValidFilterableField(string $field)
    {
        return empty($this->filterable) || ($this->filterable && in_array($field, array_keys($this->filterable)));
    }

    /**
     * Return array of default filters
     *
     * @return array
     */
    public function defaultFilters(): array
    {
        $defaultFilters = [];
        $excludes = $this->excludes();
        foreach ($excludes  as $field => $value) {
            $defaultFilters[] = [
                'field' => $field,
                'value' => $value,
                'operator' => '!='
            ];
        }

        return $defaultFilters;
    }

    /**
     * Check and transform values
     *
     * @param mixed $value
     */
    private function value(mixed $value = null): mixed
    {
        if (str_contains($value, ',')) {
            return explode(',', $value);
        }

        return $value;
    }

    /**
     * Set filters to the right format to be used in CrudRepository
     *
     * @param array $field
     * @param array $options
     * @return void
     */
    protected function setFilters(array $fields = [], array $options = []): void
    {
        $defaultFilters = $this->defaultFilters();
        $filters = collect($defaultFilters);
        foreach ($fields as $field => $value) {
            if ($this->hasValidFilterableField($field)) {
                $filters->push([
                    'field' => $this->filterable ? $this->filterable[$field] : $field,
                    'value' => $this->value($value),
                    'operator' => $this->getOperator($options[$field] ?? '='),
                ]);
            }
        }

        $this->filters = $filters;
    }

    /**
     * Remove one field from filter options
     *
     * @param string $field
     * @return Collection filter options
     */
    protected function removeFilteredField(string $field): Collection
    {
        $this->filters->forget($field);
        return $this->filters;
    }

    /**
     * Get all filter options
     *
     * @param array $options
     * @return array
     */
    public function getFilterOptions(array $options): array
    {
        $data = $options[$this->filterOptionKey] ?? [];
        if (empty($data)) {
            return  [$this->filterOptionKey => []];
        }

        $filterOptions = explode(',', $data);
        $result = [];
        foreach ($filterOptions as $filterOption) {
            $arrFilterOption = explode(':', $filterOption);
            if (!empty($arrFilterOption[1])) {
                $result[$arrFilterOption[0]] = $arrFilterOption[1];
            }
        }

        return $result;
    }

    /**
     * Get all fields from user
     *
     * @param array $options
     * @return array
     */
    public function getFilterFields(array $options): array
    {
        return collect($options)->except([
            '_token', '_method', 'sorts', 'limit', 'page', 'fields', 'search', 'search_fields',
            'no_pagination', $this->relationsKey, $this->countsKey, $this->filterOptionKey
        ])->toArray() ?? [];
    }

    /**
     * Available relations for this service
     *
     * @return array
     */
    public function relations(): array
    {
        return $this->allowedRelations ?? [];
    }

    /**
     * Format relations
     *
     * @param array $params
     * @return array of formatted relations
     */
    public function formatRelations(array $params = []): array
    {
        $allowedRelations = $this->relations();

        // Convert relations from under_score to camelCase
        $relations = array_map(fn ($relation) => Str::camel($relation), $params);

        // Filter only allowed relations
        return array_reduce(
            array_keys($allowedRelations),
            function ($carry, $relation) use ($allowedRelations, $relations) {
                if (in_array($relation, $relations) || in_array($allowedRelations[$relation], $relations)) {
                    if (is_callable($allowedRelations[$relation])) {
                        $carry[$relation] = $allowedRelations[$relation];
                    } else {
                        $carry[] = $allowedRelations[$relation];
                    }
                }

                return $carry;
            },
            []
        );
    }

    /**
     * Remove all relations from filter options
     *
     * @param array $options
     * @return array relations
     */
    public function getRelations(array $options): array
    {
        $relations = $options[$this->relationsKey] ?? [];
        if (empty($relations)) {
            return [];
        }

        if (!is_string($relations)) {
            abort(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Incorrect relations parameter. Expected format: relations=relation1,relation2'
            );
        }

        $relations = explode(',', $relations);

        return $this->formatRelations($relations);
    }

    /**
     * Remove all relations from filter options
     *
     * @param array $options
     * @return array relations
     */
    public function getCounts(array $options): array
    {
        $counts = $options[$this->countsKey] ?? [];
        if (empty($counts)) {
            return [];
        }

        if (!is_string($counts)) {
            abort(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Incorrect counts parameter. Expected format: relations=relation1,relation2'
            );
        }

        $counts = explode(',', $counts);

        return $this->formatRelations($counts);
    }

    /**
     * Get sorts from request
     *
     * @param array $options
     * @return array
     */
    public function getSorts(array $options): array
    {
        if (empty($options['sorts'])) {
            return [];
        }

        $sorts = explode(',', $options['sorts']);
        $result = [];

        // Transform format column:desc to array format [column => desc]
        foreach ($sorts as $sort) {
            $sortOption = explode(':', $sort);
            if (!empty($sortOption[1])) {
                $result[$sortOption[0]] = $sortOption[1];
            }
        }

        return $result;
    }

    /**
     * Get all page/pagination options from users
     *
     * @param array $options
     * @return array page options
     */
    public function getSelectedFields(array $options): array
    {
        return $options['fields'] ?? ['*'];
    }

    /**
     * Prepare options
     *
     * @param array $options
     * @return array Options [filters, relations, pageOptions]
     */
    public function prepareOptions(?array $options = null): array
    {
        if (empty($options)) {
            return [
                'filters' => [],
                'relations' => [],
                'counts' => [],
                'fields' => ['*'],
                'page' => 1,
                'limit' => $this->limit,
                'sorts' => [],
                'search' => null,
                'search_fields' => [],
            ];
        }

        $this->setFilters(
            $this->getFilterFields($options),
            $this->getFilterOptions($options),
        );

        return [
            'filters' => $this->getFilters(),
            'relations' => $this->getRelations($options),
            'counts' => $this->getCounts($options),
            'fields' => $this->getSelectedFields($options),
            'page' => (int) ($options['page'] ?? 1),
            'limit' => (int) ($options['limit'] ?? $this->limit),
            'sorts' => $this->getSorts($options),
            'search' => $options['search'] ?? null,
            'search_fields' => $options['search_fields'] ?? $this->searchable(),
        ];
    }

    /**
     * @return array list of searchable fields
     */
    public function searchable(): array
    {
        return [];
    }

    /**
     * This query will run with every CRUD retrieving data methods
     *
     * @return null|callable
     */
    public function baseQuery(): ?callable
    {
        return null;
    }

    /**
     * Get records into pagination
     *
     * @param array $options
     * @return Collection|LengthAwarePaginator
     */
    public function paginate(?array $options = null): Collection|LengthAwarePaginator
    {
        if ($options['no_pagination'] ?? null) {
            return $this->getMany($options);
        }

        return $this->repo->paginate(
            $this->prepareOptions($options),
            $this->baseQuery()
        );
    }

    /**
     * Get records including items in trash into pagination
     *
     * @param array $options
     * @return Collection|LengthAwarePaginator
     */
    public function paginateWithTrashed(?array $options = null): Collection|LengthAwarePaginator
    {
        if ($options['no_pagination'] ?? null) {
            return $this->getMany($options);
        }

        return $this->repo->paginateWithTrashed(
            $this->prepareOptions($options),
            $this->baseQuery()
        );
    }

    /**
     * Get records only items in trash into pagination
     *
     * @param array $options
     * @return Collection|LengthAwarePaginator
     */
    public function paginateFromTrash(?array $options = null): Collection|LengthAwarePaginator
    {
        if ($options['no_pagination'] ?? null) {
            return $this->getManyFromTrash($options);
        }

        return $this->repo->paginateFromTrash(
            $this->prepareOptions($options),
            $this->baseQuery()
        );
    }

    /**
     * Get all records
     *
     * @param array $options
     * @return Collection
     */
    public function getMany(?array $options = null): Collection
    {
        return $this->repo->getMany($this->prepareOptions($options), $this->baseQuery());
    }

    /**
     * Get all records
     *
     * @param array $options
     * @return Collection
     */
    public function getManyWithTrashed(?array $options = null): Collection
    {
        [$filters, $relations, $pageOptions, $fields] = $this->prepareOptions($options);
        return $this->repo->getManyWithTrashed($filters, $relations, $pageOptions, $fields);
    }

    /**
     * Get all records
     *
     * @param array $options
     * @return Collection
     */
    public function getManyFromTrash(?array $options = null): Collection
    {
        $options = $this->prepareOptions($options);
        return $this->repo->getManyFromTrash($options);
    }

    /**
     * Get one record which is not deleted by specified field (default = "id")
     *
     * @param mixed|null $id
     * @param null|array $options
     * @return null|Model
     */
    public function getOne(mixed $id, ?array $options = null): ?Model
    {
        $options = $this->prepareOptions($options);
        return $this->repo->getOne($id, $options, $this->baseQuery());
    }

    /**
     * Get one record or return 404 if record is not found
     *
     * @param mixed|null $id
     * @param null|array $options
     * @return null|Model
     */
    public function getOneOrFail(mixed $id, ?array $options = null): ?Model
    {
        $options = $this->prepareOptions($options);
        return $this->repo->getOneOrFail($id, $options, $this->baseQuery());
    }

    /**
     * Get one record by specified field (default = "id")
     *
     * @param mixed|null $id
     * @param null|array $options
     * @return null|Model|Builder|QueryBuilder
     */
    public function getOneWithTrashed(mixed $id, ?array $options = null): ?Model
    {
        $options = $this->prepareOptions($options);
        return $this->repo->getOneWithTrashed($id, $options, $this->baseQuery());
    }

    /**
     * Get one record by specified field (default = "id")
     *
     * @param mixed|null $id
     * @param null|array $options
     * @return null|Model|Builder|QueryBuilder
     */
    public function getOneFromTrashed(mixed $id, ?array $options = null): ?Model
    {
        $options = $this->prepareOptions($options);
        return $this->repo->getOneFromTrash($id, $options, $this->baseQuery());
    }

    /**
     * Create one record
     *
     * @param array $payload
     * @return null|Model
     */
    public function createOne(array $payload): ?Model
    {
        return $this->repo->createOne($payload);
    }

    /**
     * Update one record
     *
     * @param string|int $id
     * @param array $payload
     * @param string $field (default = "id")
     * @return null|Model
     */
    public function updateOne(string|int $id, array $payload): ?Model
    {
        $record = $this->repo->getOneOrFail($id);
        return $this->repo->updateOne($record, $payload);
    }

    /**
     * Update one record
     *
     * @param Model $model
     * @param array $payload
     * @return null|Model
     */
    public function updateModel(Model $model, array $payload): ?Model
    {
        return $this->repo->updateOne($model, $payload);
    }

    /**
     * Delete one record
     *
     * @param string|int $id
     * @return null|Model
     */
    public function deleteOne(string|int $id): ?Model
    {
        $record = $this->getOneOrFail($id);
        return $this->repo->deleteOne($record);
    }

    /**
     * Delete one record by model
     *
     * @param Model $model
     * @return null|Model
     */
    public function deleteModel(Model $model): ?Model
    {
        return $this->repo->deleteOne($model);
    }

    /**
     * Restore one record from trash
     *
     * @param string|int $id
     * @return null|Model
     */
    public function restoreOne(string|int $id): ?Model
    {
        $record = $this->repo->getOneFromTrash($id);
        !$record && abort(Response::HTTP_NOT_FOUND);

        return $this->repo->restoreOne($record);
    }

    /**
     * Delete one record from trash by model
     *
     * @param Model $model
     * @return null|Model
     */
    public function restoreModel(Model $model): ?Model
    {
        return $this->repo->restoreOne($model);
    }

    /**
     * Force delete one record
     *
     * @param string|int $id
     * @return null|Model
     */
    public function forceDeleteOne(string|int $id): ?Model
    {
        $record = $this->getOneOrFail($id);
        return $this->repo->forceDeleteOne($record);
    }

    /**
     * Force delete one record
     *
     * @param Model $model
     * @return null|Model
     */
    public function forceDeleteModel(Model $model): ?Model
    {
        return $this->repo->forceDeleteOne($model);
    }

    /**
     * @param callable $where
     * @return null|int
     */
    public function countWhere(callable $where): ?int
    {
        [$filters] = $this->prepareOptions([]);
        return $this->repo->countWhere($where, compact('filters'));
    }
}
