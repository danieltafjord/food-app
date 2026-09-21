<?php

namespace App\Data\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Documents controller actions that accept and return spatie/laravel-data
 * objects: a Data parameter becomes the JSON request body, and a Data (or
 * `DataCollection<int, XData>` docblock) return type becomes the "data"
 * wrapped 200 response.
 */
class DataOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $method = $routeInfo->reflectionMethod();

        if (! $method) {
            return;
        }

        $schemas = new DataSchemaFactory;

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Data::class)) {
                $operation->addRequestBodyObject(
                    RequestBodyObject::make()->setContent('application/json', Schema::fromType($schemas->make($type->getName())))->required()
                );
            }
        }

        $returnType = $method->getReturnType();
        $returnClass = $returnType instanceof ReflectionNamedType ? $returnType->getName() : null;

        $payload = match (true) {
            $returnClass === null => null,
            is_subclass_of($returnClass, Data::class) => $schemas->make($returnClass),
            $returnClass === DataCollection::class => $this->collectionSchema($schemas, (string) $method->getDocComment()),
            default => null,
        };

        if ($payload) {
            $operation->responses = array_values(array_filter(
                $operation->responses ?? [],
                fn ($response) => ! ($response instanceof Response && in_array((int) $response->code, [200, 201], true)),
            ));

            $operation->addResponse(
                Response::make(200)->setDescription('Successful response')->setContent(
                    'application/json',
                    Schema::fromType((new ObjectType)->addProperty('data', $payload)->setRequired(['data'])),
                )
            );
        }
    }

    /**
     * Resolve the item class from a `@return DataCollection<int, XData>` docblock.
     */
    private function collectionSchema(DataSchemaFactory $schemas, string $docComment): ?Type
    {
        if (! preg_match('/@return\s+DataCollection<\s*int\s*,\s*(\w+)\s*>/', $docComment, $matches)) {
            return null;
        }

        $itemClass = 'App\\Data\\'.$matches[1];

        return is_subclass_of($itemClass, Data::class)
            ? (new ArrayType)->setItems($schemas->make($itemClass))
            : null;
    }
}
