<?php

namespace App\Data\OpenApi;

use BackedEnum;
use DateTimeInterface;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Builds OpenAPI schemas from spatie/laravel-data classes by reflecting their
 * constructors, mirroring the app's global snake_case name mapping.
 */
class DataSchemaFactory
{
    /**
     * @param  class-string<Data>  $dataClass
     */
    public function make(string $dataClass): ObjectType
    {
        $schema = new ObjectType;
        $required = [];

        foreach ((new ReflectionClass($dataClass))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = Str::snake($parameter->getName());
            $schema->addProperty($name, $this->propertyType($parameter));

            if (! $parameter->isDefaultValueAvailable() && ! $parameter->allowsNull()) {
                $required[] = $name;
            }
        }

        return $schema->setRequired($required);
    }

    private function propertyType(ReflectionParameter $parameter): Type
    {
        $reflectionType = $parameter->getType();
        $types = $reflectionType instanceof ReflectionUnionType ? $reflectionType->getTypes() : [$reflectionType];
        $typeName = 'mixed';
        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && ! in_array($candidate->getName(), [Optional::class, 'null'], true)) {
                $typeName = $candidate->getName();
                break;
            }
        }

        $type = match (true) {
            $typeName === 'int' => new IntegerType,
            $typeName === 'float' => new NumberType,
            $typeName === 'bool' => new BooleanType,
            $typeName === 'string' => $this->attribute($parameter, Date::class) ? (new StringType)->format('date') : new StringType,
            $typeName === 'array' => $this->arrayType($parameter),
            is_subclass_of($typeName, BackedEnum::class) => (new StringType)->enum(array_column($typeName::cases(), 'value')),
            is_a($typeName, DateTimeInterface::class, true) => (new StringType)->format('date-time'),
            is_subclass_of($typeName, Data::class) => $this->make($typeName),
            default => new MixedType,
        };

        if ($type instanceof StringType || $type instanceof NumberType) {
            if ($min = $this->attribute($parameter, Min::class)) {
                $type->setMin($min->getArguments()[0]);
            }

            if ($max = $this->attribute($parameter, Max::class)) {
                $type->setMax($max->getArguments()[0]);
            }
        }

        if ($parameter->isDefaultValueAvailable() && is_scalar($parameter->getDefaultValue())) {
            $type->default($parameter->getDefaultValue());
        }

        return $type->nullable($parameter->allowsNull());
    }

    private function arrayType(ReflectionParameter $parameter): ArrayType
    {
        $itemsOf = $this->attribute($parameter, ItemsOf::class) ?? $this->attribute($parameter, DataCollectionOf::class);
        $itemClass = $itemsOf?->getArguments()[0] ?? null;

        if ($itemClass === null && $parameter->getName() === 'scopes') {
            return (new ArrayType)->setItems(new StringType);
        }

        return (new ArrayType)->setItems($itemClass ? $this->make($itemClass) : new MixedType);
    }

    /**
     * @param  class-string  $attributeClass
     */
    private function attribute(ReflectionParameter $parameter, string $attributeClass): ?\ReflectionAttribute
    {
        return $parameter->getAttributes($attributeClass)[0] ?? null;
    }
}
