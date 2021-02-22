<?php

namespace DigitalCreative\ConditionalContainer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use logipar\Logipar;
use Whitecube\NovaFlexibleContent\Flexible;

class ConditionalContainer extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'conditional-container';

    /**
     * @var Collection
     */
    public $fields;

    /**
     * @var Collection
     */
    public $expressions;

    /**
     * @var Collection
     */
    public const OPERATORS = [
        '>=', '<=', '<', '>',
        '!==', '!=',
        '===', '==', '=',
        'includes', 'contains',
        'ends with', 'starts with', 'startsWith', 'endsWith',
        'boolean', 'truthy'
    ];

    /**
     * ConditionalContainer constructor.
     *
     * @param array $fields
     */
    public function __construct(array $fields)
    {

        $this->fields = collect($fields);
        $this->expressions = collect();

        parent::__construct('conditional_container_' . md5($this->fields->whereInstanceOf(Field::class)->pluck('attribute')->join('.')));

        $this->withMeta([ 'operation' => 'some' ]);

    }

    public function if($expression): self
    {
        $this->expressions->push($expression);

        return $this;
    }

    public function orIf($expression): self
    {
        return $this->if($expression);
    }

    /**
     * Resolve the field's value.
     *
     * @param mixed $resource
     * @param string|null $attribute
     *
     * @return void
     */
    public function resolve($resource, $attribute = null)
    {

        /**
         * Clone everything before resolving to avoid fields being mutated when nested in some sort of repeating wrapper
         */
        $this->fields = $this->fields->map(static function ($field) {

            return clone $field;

        });

        /**
         * Avoid unselected fields coming with pre-filled data on update when using a flexible field
         */
        if (resolve(NovaRequest::class)->route()->controller instanceof UpdateFieldController) {

            if ($this->fields->pluck('meta.__has_flexible_field__')->filter()->isNotEmpty() === false) {

                if (count($this->resolveDependencyFieldUsingResource($resource)) === 0) {

                    return;

                }

            }

        }

        /**
         * @var Field $field
         */
        foreach ($this->fields as $field) {

            $field->resolve($resource, $field->attribute);

        }

    }

    public function fill(NovaRequest $request, $model)
    {

        $callbacks = [];

        /**
         * @var Field $field
         */
        foreach ($this->fields as $field) {

            $callbacks[] = $field->fill($request, $model);

        }

        return static function () use ($callbacks) {

            foreach ($callbacks as $callback) {

                if (is_callable($callback)) {

                    $callback();

                }

            }

        };

    }

    public function useAndOperator(): self
    {
        return $this->withMeta([ 'operation' => 'every' ]);
    }

    private function relationalOperatorLeafResolver(Collection $values, string $literal): bool
    {

        [ $attribute, $operator, $value ] = self::splitLiteral($literal);

        if ($values->keys()->contains($attribute)) {

            return $this->executeCondition($values->get($attribute), $operator, $value);

        }

        return false;

    }

    private function executeCondition($attributeValue, string $operator, $conditionValue): bool
    {

        $conditionValue = trim($conditionValue, '"\'');

        if ((is_numeric($attributeValue) && is_numeric($conditionValue)) ||
            (in_array($operator, [ '<', '>', '<=', '>=' ]) && $conditionValue)) {

            $conditionValue = (int) $conditionValue;
            $attributeValue = (int) $attributeValue;

        }

        if (in_array($conditionValue, [ 'true', 'false' ])) {

            $conditionValue = $conditionValue === 'true';

        }

        switch ($operator) {

            case '=':
            case '==':
                return $attributeValue == $conditionValue;
            case '===':
                return $attributeValue === $conditionValue;
            case '!=':
                return $attributeValue != $conditionValue;
            case '!==':
                return $attributeValue !== $conditionValue;
            case '>':
                return $attributeValue > $conditionValue;
            case '<':
                return $attributeValue < $conditionValue;
            case '>=':
                return $attributeValue >= $conditionValue;
            case '<=':
                return $attributeValue <= $conditionValue;
            case 'boolean':
            case 'truthy':
                return $conditionValue ? !!$attributeValue : !$attributeValue;
            case 'includes':
            case 'contains':

                /**
                 * On the javascript side it uses ('' || []).includes() which works with array and string
                 */
                if ($attributeValue instanceof Collection) {

                    return $attributeValue->contains($conditionValue);

                }

                return Str::contains($attributeValue, $conditionValue);

            case 'starts with':
            case 'startsWith':
                return Str::startsWith($attributeValue, $conditionValue);
            case 'endsWith':
            case 'ends with':
                return Str::endsWith($attributeValue, $conditionValue);
            default :
                return false;

        }

    }

    public static function splitLiteral(string $literal): array
    {

        $operator = collect(self::OPERATORS)
            ->filter(static function ($operator) use ($literal) {
                return strpos($literal, $operator) !== false;
            })
            ->first();

        [ $attribute, $value ] = collect(explode($operator, $literal))->map(static function ($value) {
            return trim($value);
        });

        return [
            $attribute,
            $operator,
            $value
        ];

    }

    public function runConditions(Collection $values): bool
    {
        return $this->expressions->{$this->meta[ 'operation' ]}(function ($expression) use ($values) {

            $parser = new Logipar();
            $parser->parse(is_callable($expression) ? $expression() : $expression);

            $resolver = $parser->filterFunction(function (...$arguments) {
                return $this->relationalOperatorLeafResolver(...$arguments);
            });

            return $resolver($values);

        });
    }

    /**
     * @param Resource|Model $resource
     * @param NovaRequest $request
     *
     * @return array
     */
    public function resolveDependencyFieldUsingRequest($resource, NovaRequest $request): array
    {

        $matched = $this->runConditions(collect($request->toArray()));

        /**
         * Imagine the situation where you have 2 fields with the same name, you conditionally show them based on X
         * when field A is saved the db value is saved as A format, when you switch the value to B, now B is feed
         * with the A data which may or may not be of the same shape (string / boolean for example)
         * The following check resets the resource value with an "default" value before processing update
         * Therefore avoiding format conflicts
         */
        if ($matched && $request->route()->controller instanceof ResourceUpdateController) {

            foreach ($this->fields as $field) {

                if ($field instanceof Field &&
                    !$field instanceof Flexible &&
                    !$field instanceof RelatableField &&
                    !blank($field->attribute) &&
                    !$field->isReadonly($request)) {

                    $resource->setAttribute($field->attribute, $field->value);

                }

            }

        }

        return $matched ? $this->fields->toArray() : [];

    }

    /**
     * @param Resource|Model $resource
     *
     * @return array
     */
    public function resolveDependencyFieldUsingResource($resource): array
    {

        $matched = $this->runConditions(
            $this->flattenRelationships($resource)
        );

        return $matched ? $this->fields->toArray() : [];

    }

    /**
     * @param Model|Resource $resource
     *
     * @return Collection
     */
    private function flattenRelationships($resource): Collection
    {

        $data = collect($resource->toArray());

        if (!method_exists($resource, 'getRelations')) {

            return $data;

        }

        foreach ($resource->getRelations() as $relationName => $relation) {

            if ($relation instanceof Collection) {

                $data->put($relationName, $relation->map->getKey());

            } else if ($relation instanceof Model) {

                $data->put($relationName, $relation->getKey());

            }

        }

        return $data;

    }

    public function jsonSerialize()
    {
        return array_merge([
            'fields' => $this->fields,
            'expressions' => $this->expressions->map(static function ($expression) {

                return is_callable($expression) ? $expression() : $expression;

            }),
        ], parent::jsonSerialize());
    }

}
