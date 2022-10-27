<?php

namespace App\Libraries\Crud;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CrudRepository
{
    protected Model $model;

    protected int $limit = 50;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Return primary key column fo the model
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->model->getKeyName();
    }

    /**
     * Check if field is relation field
     *
     * @param string $field
     * @return bool
     */
    private function isRelationField(string $field): bool
    {
        return strpos($field, '.') !== false;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param string $operator
     * @return callable
     */
    private function buildFiltersQuery(string $field, mixed $value, string $operator = '='): callable
    {
        return function (Builder $query) use ($field, $value, $operator) {
            if ($value && (is_array($value) || $operator === 'between')) {
                if ($operator !== 'between') {
                    return $query->whereIn($field, $value);
                }

                $filteredValue = array_filter($value);
                if (count($filteredValue) === 0) {
                    return $query;
                }

                if (count($filteredValue) === 1) {
                    if (!empty($value[0])) {
                        return $query->where($field, '>=', $value[0]);
                    }

                    return $query->where($field, '<=', $value[1]);
                }

                return $query->whereBetween($field, $filteredValue);
            }

            if ($value !== null) {
                if ($operator === 'contain') {
                    return $query->where($field, 'ilike', '%' . $value . '%');
                }

                if ($operator === 'date') {
                    return $query->whereDate($field, $value);
                }

                if ($operator === 'exists') {
                    if ($value === 'true' || $value === true) {
                        return $query->whereNotNull($field);
                    }

                    return $query->whereNull($field);
                }

                return $query->where($field, $operator, $value);
            }

            return $query;
        };
    }

    public function whereRelation(string $field, mixed $value, string $operator): callable
    {
        return function (Builder $query) use ($field, $value, $operator) {
            if ($this->isRelationField($field)) {
                $data = explode('.', $field);

                if (count($data) === 2) {
                    $relation = $data[0];
                    $relationField = $data[1];

                    if ($operator === 'not_has') {
                        return $query->whereDoesntHave($relation);
                    }

                    return $query->whereHas(
                        $relation,
                        $this->buildFiltersQuery($relationField, $value, $operator)
                    );
                }

                $relation1 = $data[0];
                $relation2 = $data[1];
                $relationField = $data[2];

                if ($operator === 'not_has') {
                    return $query->whereDoesntHave($relation1);
                }

                return $query->whereHas(
                    $relation1,
                    function (Builder $query) use ($relation2, $relationField, $value, $operator) {
                        if ($operator === 'not_has') {
                            return $query->whereDoesntHave($relation2);
                        }

                        return $query->whereHas(
                            $relation2,
                            $this->buildFiltersQuery($relationField, $value, $operator)
                        );
                    }
                );
            }

            return $query;
        };
    }

    /**
     * @param array $filters
     * @return callable
     */
    public function filters(array $filters): callable
    {
        return function (Builder $query) use ($filters) {
            $query->where(function (Builder $query) use ($filters) {
                foreach ($filters as $filter) {
                    ['field' => $field, 'value' => $value, 'operator' => $operator] = $filter;
                    $query->where(
                        $this->isRelationField($field)
                            ? $this->whereRelation($field, $value, $operator)
                            : $this->buildFiltersQuery($field, $value, $operator)
                    );
                }
            });
        };
    }

    /**
     * @param array $fields
     * @return array
     */
    public function getSelectFields(array $fields = []): array
    {
        if (empty($fields)) {
            return ['*'];
        }

        return array_map(
            fn ($field) => preg_replace('/[^a-zA-Z0-9_*]/', '', $field),
            $fields
        );
    }

    /**
     * Build search query
     *
     * @param string $search
     * @param array $fields
     * @return callable
     */
    public function search(string $search, array $fields = []): callable
    {
        return function (Builder $query) use ($search, $fields) {
            $query->where(function (Builder $query) use ($search, $fields) {
                foreach ($fields as $field) {
                    if ($this->isRelationField($field)) {
                        $query->orWhere($this->whereRelation($field, $search, 'contain'));
                    } else {
                        $query->orWhere($field, 'ilike', '%' . $search . '%');
                    }
                }
            });
        };
    }

    /**
     * @param array $sorts
     * @return callable
     */
    public function sorts(array $sorts = []): callable
    {
        return function (Builder $query) use ($sorts) {
            foreach ($sorts as $field => $sort) {
                $query->orderBy($field, $sort);
            }
        };
    }

    /**
     * Build main query
     *
     * @param array $options
     * @param callable|null $baseQuery
     * @return Model|Builder|QueryBuilder
     */
    public function buildQuery(?array $options = null): Model|Builder|QueryBuilder
    {
        if (empty($options)) {
            return $this->model;
        }

        $sorts = $options['sorts'] ?? [];
        $filters = $options['filters'] ?? [];
        $search = $options['search'] ?? '';
        $searchFields = $options['search_fields'] ?? [];
        $fields = $options['fields'] ?? [];
        $relations = $options['relations'] ?? [];
        $counts = $options['counts'] ?? [];
        $limit = $options['limit'] ?? $this->limit;

        return $this->model
            ->select($this->getSelectFields($fields))
            ->when(!empty($filters), $this->filters($filters))
            ->when($search && !empty($searchFields), $this->search($search, $searchFields))
            ->when(count($sorts) > 0, $this->sorts($sorts))
            ->when(!empty($counts), fn (Builder $query) => $query->withCount($counts))
            ->when(!empty($relations), fn (Builder $query) => $query->with($relations))
            ->limit($limit);
    }

    /**
     * @param array $options
     * @param array|null $baseQuery
     * @return Collection|LengthAwarePaginator
     */
    public function paginate(
        array $options = [],
        ?callable $baseQuery = null
    ): Collection|LengthAwarePaginator {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }


        return $query->paginate($options['limit'] ?? $this->limit);
    }

    /**
     * @param array $options
     * @param callable|null $baseQuery
     * @return Collection|LengthAwarePaginator
     */
    public function paginateWithTrashed(
        array $options = [],
        ?callable $baseQuery = null
    ): Collection|LengthAwarePaginator {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->withTrashed()->paginate($options['limit'] ?? $this->limit);
    }

    /**
     * @param array $options
     * @param callable|null $baseQuery
     * @return Collection|LengthAwarePaginator
     */
    public function paginateFromTrash(
        array $options = [],
        ?callable $baseQuery = null
    ): Collection|LengthAwarePaginator {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->onlyTrashed()->paginate($options['limit'] ?? $this->limit);
    }

    /**
     * @param array $options
     * @param callable|null $baseQuery
     * @return Collection
     */
    public function getMany(array $options = [], ?callable $baseQuery = null): Collection
    {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->limit($options['limit'] ?? $this->limit)->get();
    }

    /**
     * @param array $options
     * @param callable|null $baseQuery
     * @return Collection
     */
    public function getManyWithTrashed(array $options = [], ?callable $baseQuery = null): Collection
    {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->limit($options['limit'] ?? $this->limit)->withTrashed()->get();
    }

    /**
     * @param array $options
     * @param callable|null $baseQuery
     * @return Collection
     */
    public function getManyFromTrash(array $options = [],  ?callable $baseQuery = null): Collection
    {
        $query = $this->buildQuery($options);

        if ($baseQuery) {
            $query = $baseQuery($query);
        }


        return $query->limit($options['limit'] ?? $this->limit)->onlyTrashed()->get();
    }

    /**
     * @param mixed|null $value
     * @param string $field
     * @param array|null $options
     * @return null|Model
     */
    public function getOne(mixed $id, ?array $options = null, ?callable $baseQuery = null): ?Model
    {
        $query = $this->buildQuery($options);
        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->find($id);
    }

    /**
     * @param mixed|null $value
     * @return null|object
     */
    public function getLatest(): ?object
    {
        return DB::table($this->model->getTable())->latest()->first();
    }

    /**
     * @return null|object
     */
    public function getLatestAndLock($column = 'created_at', $fields = ['*']): ?object
    {
        return DB::table($this->model->getTable())
            ->sharedLock()
            ->select($fields)
            ->latest($column)
            ->first();
    }

    /**
     * @param mixed $id
     * @param string $field
     * @param array $options
     * @return null|Model
     */
    public function getOneOrFail(mixed $id, ?array $options = null, ?callable $baseQuery = null): ?Model
    {
        $query = $this->buildQuery($options);
        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->findOrFail($id);
    }

    /**
     * @param mixed $id
     * @param string $field
     * @param array $options
     * @return null|Model
     */
    public function getOneWithTrashed(mixed $id, ?array $options = null, ?callable $baseQuery = null): ?Model
    {
        $query = $this->buildQuery($options);
        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->withTrashed()->find($id);
    }

    /**
     * @param mixed $id
     * @param string $field
     * @param array $options
     * @return null|Model
     */
    public function getOneFromTrash(mixed $id, ?array $options = null, ?callable $baseQuery = null): ?Model
    {
        $query = $this->buildQuery($options);
        if ($baseQuery) {
            $query = $baseQuery($query);
        }

        return $query->onlyTrashed()->find($id);
    }

    /**
     * @param array $payload
     * @return null|Model
     */
    public function createOne(array $payload = []): ?Model
    {
        $model = $this->model;
        $model->fill($payload);
        $model->save();

        return $model;
    }

    /**
     * @param Model $model
     * @param array $payload
     * @return null|Model
     */
    public function updateOne(Model $model, array $payload): ?Model
    {
        if (!$model) {
            return null;
        }

        $model->fill($payload);
        $model->save();

        return $model;
    }

    /**
     * @param Model $model
     * @param array|Collection $payload
     * @return null|Model
     */
    public function updateOrCreate(Model $model, array $payload): ?Model
    {
        if (!$model->{$this->getKeyName()}) {
            $model = $this->model;
        }

        $model->fill($payload);
        $model->save();

        return $model;
    }

    /**
     * @param mixed $id
     * @param array $payload
     * @param string $keyColumn
     *
     * @return null|Model|boolean
     */
    public function updateOneById(mixed $id,  array $payload): ?Model
    {
        $model = $this->model->select($this->getKeyName())->find($id);

        if (!$model) {
            return null;
        }

        $model->fill($payload);
        $model->save();

        return $model;
    }

    /**
     * @param Model $model
     * @return null|Model
     */
    public function deleteOne(Model $model): ?Model
    {
        if (!$model) {
            return null;
        }

        $model->delete();

        return $model;
    }

    /**
     * @param string|int $keyValue
     * @return null|Model
     */
    public function deleteOneById(string|int $id): ?Model
    {
        $model = $this->model->select($this->getKeyName())->find($id);

        if (!$model) {
            return null;
        }

        $model->delete();

        return $model;
    }

    /**
     * @param Model $model
     * @return null|Model
     */
    public function restoreOne(Model $model): ?Model
    {
        if (!$model) {
            return null;
        }

        $model->restore();

        return $model;
    }

    /**
     * @param mixed $keyValue
     * @return null|Model
     */
    public function restoreOneById(mixed $id): ?Model
    {
        $model = $this->model->select($this->getKeyName())->withTrashed()->find($id);

        if (!$model) {
            return null;
        }

        $model->restore();

        return $model;
    }

    /**
     * @param Model $model
     * @return null|Model
     */
    public function forceDeleteOne(Model $model): ?Model
    {
        if (!$model) {
            return null;
        }

        return $this->model->forceDelete($model);
    }

    /**
     * @param mixed $id
     * @return null|Model
     */
    public function forceDeleteOneById(mixed $id): ?Model
    {
        $model = $this->model->select($this->getKeyName())->find($id);

        if (!$model) {
            return null;
        }

        return $this->model->forceDelete($model);
    }

    /**
     * @param array|callable $where
     * @param array $fields default ['*']
     * @return null|Model
     */
    public function getOneWhere(array|callable $where, array $fields = ['*']): ?Model
    {
        if (is_array($where)) {
            $query = $this->model;
            foreach ($where as $field => $value) {
                $query = $query->where($field, $value);
            }

            return $query->select($fields)->first();
        }

        return $this->model->where($where)->select($fields)->first();
    }

    /**
     * @param array|callable $where
     * @return int|null
     */
    public function countWhere(array|callable $where, array $filters = []): ?int
    {
        $query = $this->buildQuery($filters, [], [], []);

        if (is_array($where)) {
            foreach ($where as $field => $value) {
                $query->where($field, $value);
            }
        }

        return $query->where($where)->count();
    }
}
