<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\FormRequestRulesExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RulesToParameters;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ValidateCallExtractor;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use Throwable;
use function in_array;

class RequestBodyExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $description = Str::of($routeInfo->phpDoc()->getAttribute('description'));

        try {
            $bodyParams = $this->extractParamsFromRequestValidationRules($routeInfo->route, $routeInfo->methodNode());

            $mediaType = $this->getMediaType($operation, $routeInfo, $bodyParams);

            if (count($bodyParams)) {
                if (! in_array($operation->method, config('scramble.disallow_request_body'))) {
                    $this->applyNestedTitles($bodyParams);

                    $operation->addRequestBodyObject(
                        RequestBodyObject::make()
                            ->setContent(
                                $mediaType,
                                Schema::createFromParameters($bodyParams)
                                    ->setTitle($this->getTitle($routeInfo))
                            )
                    );
                } else {
                    $operation->addParameters($bodyParams);
                }
            } elseif (! in_array($operation->method, config('scramble.disallow_request_body'))) {
                $operation
                    ->addRequestBodyObject(
                        RequestBodyObject::make()
                            ->setContent(
                                $mediaType,
                                Schema::fromType(new ObjectType)
                            )
                    );
            }
        } catch (Throwable $exception) {
            if (app()->environment('testing')) {
                throw $exception;
            }
            $description = $description->append('⚠️Cannot generate request documentation: '.$exception->getMessage());
        }

        $operation
            ->summary(Str::of($routeInfo->phpDoc()->getAttribute('summary'))->rtrim('.'))
            ->description($description);
    }

    protected function getMediaType(Operation $operation, RouteInfo $routeInfo, array $bodyParams): string
    {
        if (
            ($mediaTags = $routeInfo->phpDoc()->getTagsByName('@requestMediaType'))
            && ($mediaType = trim(Arr::first($mediaTags)?->value?->value))
        ) {
            return $mediaType;
        }

        $jsonMediaType = 'application/json';

        if ($operation->method === 'get') {
            return $jsonMediaType;
        }

        return $this->hasBinary($bodyParams) ? 'multipart/form-data' : $jsonMediaType;
    }

    protected function hasBinary($bodyParams): bool
    {
        return collect($bodyParams)->contains(function (Parameter $parameter) {
            if (property_exists($parameter?->schema?->type, 'format')) {
                return $parameter->schema->type->format === 'binary';
            }

            return false;
        });
    }

    protected function extractParamsFromRequestValidationRules(Route $route, ?ClassMethod $methodNode)
    {
        [$rules, $nodesResults] = $this->extractRouteRequestValidationRules($route, $methodNode);

        return (new RulesToParameters($rules, $nodesResults, $this->openApiTransformer))->handle();
    }

    protected function extractRouteRequestValidationRules(Route $route, $methodNode)
    {
        $rules = [];
        $nodesResults = [];

        // Custom form request's class `validate` method
        if (($formRequestRulesExtractor = new FormRequestRulesExtractor($methodNode))->shouldHandle()) {
            if (count($formRequestRules = $formRequestRulesExtractor->extract($route))) {
                $rules = array_merge($rules, $formRequestRules);
                $nodesResults[] = $formRequestRulesExtractor->node();
            }
        }

        if (($validateCallExtractor = new ValidateCallExtractor($methodNode))->shouldHandle()) {
            if ($validateCallRules = $validateCallExtractor->extract()) {
                $rules = array_merge($rules, $validateCallRules);
                $nodesResults[] = $validateCallExtractor->node();
            }
        }

        return [$rules, array_filter($nodesResults)];
    }

    protected function getTitle(RouteInfo $routeInfo): string
    {
        return $this->getTitleFromPhpDoc($routeInfo->phpDoc())
            ?? $this->getTitleFromMethodParameter($routeInfo->reflectionMethod())
            ?? $this->getTitleFromClassName($routeInfo->className());
    }

    protected function getTitleFromPhpDoc(PhpDocNode $docNode): ?string
    {
        $tags = $docNode->getTagsByName('@request');

        if (!$tags || !($tag = reset($tags))) {
            return null;
        }

        return Str::of($tag->value->value)->explode(' ')->first();
    }

    protected function getTitleFromMethodParameter(\ReflectionMethod $method): ?string
    {
        return collect($method->getParameters())
            ->map(fn (\ReflectionParameter $parameter) => $parameter->getType()->getName())
            ->filter(fn (string $typeName) => is_subclass_of($typeName, FormRequest::class))
            ->map(fn (string $typeName) => class_basename($typeName))
            ->first();
    }

    protected function getTitleFromClassName(string $className): string
    {
        return Str::of(class_basename($className))
            ->replaceMatches('/(Api)?Controller/', '')
            ->append('Request')
            ->toString();
    }

    /**
     * @param Parameter[] $bodyParams
     */
    protected function applyNestedTitles(array &$bodyParams): void
    {
        $typePattern = '/type:[a-zA-Z_][a-zA-Z0-9_]+/';

        foreach ($bodyParams as $param) {
            if (
                !Str::of($param->description)->isMatch($typePattern)
                || !($param->schema->type instanceof ObjectType)
            ) {
                continue;
            }

            $type = Str::of($param->description)
                ->match($typePattern)
                ->replace('type:', '')
                ->toString();

            $param->schema->setTitle($type);
            $param->schema->type->setTitle($type);

            $param->description(
                Str::of($param->description)
                    ->replaceMatches($typePattern, '')
                    ->trim()
                    ->replaceMatches('/  +/', ' ')
                    ->toString()
            );
        }
    }
}
