<?php

namespace Znck\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

class BelongsToThrough extends Relation
{
    /**
     * The column alias for the local key on the first "through" parent model.
     *
     * @var string
     */
    const THROUGH_KEY = 'laravel_through_key';

    /**
     * The "through" parent model instances.
     *
     * @var \Illuminate\Database\Eloquent\Model[]
     */
    protected $throughParents;

    /**
     * The foreign key prefix for the first "through" parent model.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The custom foreign keys on the relationship.
     *
     * @var array
     */
    protected $foreignKeyLookup;

    /**
     * Create a new belongs to through relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  \Illuminate\Database\Eloquent\Model[]  $throughParents
     * @param  string|null  $localKey
     * @param  string  $prefix
     * @param  array  $foreignKeyLookup
     * @return void
     */
    public function __construct(Builder $query, Model $parent, array $throughParents, $localKey = null, $prefix = '', array $foreignKeyLookup = [])
    {
        $this->throughParents = $throughParents;
        $this->prefix = $prefix;
        $this->foreignKeyLookup = $foreignKeyLookup;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        $this->query->select([$this->related->getTable().'.*']);

        $this->performJoins();

        if (static::$constraints) {
            $localValue = $this->parent[$this->getFirstForeignKeyName()];

            $this->query->where($this->getQualifiedFirstLocalKeyName(), '=', $localValue);
        }
    }

    /**
     * Set the join clauses on the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|null  $query
     * @return void
     */
    protected function performJoins(Builder $query = null)
    {
        $query = $query ?: $this->query;

        foreach ($this->throughParents as $i => $model) {
            $table = $model->getTable();

            $predecessor = $i > 0 ? $this->throughParents[$i - 1] : $this->related;

            $first = $table.'.'.$this->getForeignKeyName($predecessor);

            $second = $predecessor->getQualifiedKeyName();

            $query->join($table, $first, '=', $second);

            if ($this->hasSoftDeletes($model)) {
                $this->query->whereNull($model->getQualifiedDeletedAtColumn());
            }
        }
    }

    /**
     * Get the foreign key for a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    protected function getForeignKeyName(Model $model)
    {
        $table = $model->getTable();

        if (array_key_exists($table, $this->foreignKeyLookup)) {
            return $this->foreignKeyLookup[$table];
        }

        return Str::singular($table).'_id';
    }

    /**
     * Determine whether a model uses SoftDeletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function hasSoftDeletes(Model $model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive(get_class($model)));
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->query->addSelect([
            $this->getQualifiedFirstLocalKeyName().' as '.static::THROUGH_KEY,
        ]);

        $keys = $this->getKeys($models, $this->getFirstForeignKeyName());

        $this->query->whereIn($this->getQualifiedFirstLocalKeyName(), $keys);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  \Illuminate\Database\Eloquent\Model[]  $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  \Illuminate\Database\Eloquent\Model[]  $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model[$this->getFirstForeignKeyName()];

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result[static::THROUGH_KEY]] = $result;

            unset($result[static::THROUGH_KEY]);
        }

        return $dictionary;
    }

    /**
     * Get the results of the relationship.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getResults()
    {
        return $this->query->first();
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $parent
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parent, $columns = ['*'])
    {
        $this->performJoins($query);

        $foreignKey = $parent->getQuery()->from.'.'.$this->getFirstForeignKeyName();

        $foreignKey = new Expression($query->getQuery()->getGrammar()->wrap($foreignKey));

        return $query->select($columns)->where(
            $this->getQualifiedFirstLocalKeyName(), '=', $foreignKey
        );
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parent
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationCountQuery(Builder $query, Builder $parent)
    {
        return $this->getRelationExistenceQuery($query, $parent, new Expression('count(*)'));
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parent
     * @param  array|mixed $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQuery(Builder $query, Builder $parent, $columns = ['*'])
    {
        return $this->getRelationExistenceQuery($query, $parent, $columns);
    }

    /**
     * Get the foreign key for the first "through" parent model.
     *
     * @return string
     */
    public function getFirstForeignKeyName()
    {
        return $this->prefix.$this->getForeignKeyName(end($this->throughParents));
    }

    /**
     * Get the qualified local key for the first "through" parent model.
     *
     * @return string
     */
    public function getQualifiedFirstLocalKeyName()
    {
        return end($this->throughParents)->getQualifiedKeyName();
    }
}
