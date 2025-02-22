<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Builder;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\Argument;
use CuyZ\Valinor\Mapper\Object\Exception\InvalidSourceForObject;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\Factory\SuitableObjectBuilderNotFound;
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Mapper\Object\ObjectBuilderFilterer;
use CuyZ\Valinor\Mapper\Tree\Node;
use CuyZ\Valinor\Mapper\Tree\Shell;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ClassType;
use CuyZ\Valinor\Type\Types\UnionType;

use function array_filter;
use function array_key_exists;
use function count;
use function is_array;
use function is_iterable;
use function iterator_to_array;

/** @internal */
final class ClassNodeBuilder implements NodeBuilder
{
    private NodeBuilder $delegate;

    private ClassDefinitionRepository $classDefinitionRepository;

    private ObjectBuilderFactory $objectBuilderFactory;

    private ObjectBuilderFilterer $objectBuilderFilterer;

    public function __construct(
        NodeBuilder $delegate,
        ClassDefinitionRepository $classDefinitionRepository,
        ObjectBuilderFactory $objectBuilderFactory,
        ObjectBuilderFilterer $objectBuilderFilterer
    ) {
        $this->delegate = $delegate;
        $this->classDefinitionRepository = $classDefinitionRepository;
        $this->objectBuilderFactory = $objectBuilderFactory;
        $this->objectBuilderFilterer = $objectBuilderFilterer;
    }

    public function build(Shell $shell, RootNodeBuilder $rootBuilder): Node
    {
        $classTypes = $this->classTypes($shell->type());

        if (empty($classTypes)) {
            return $this->delegate->build($shell, $rootBuilder);
        }

        $source = $shell->value();

        $builder = $this->builder($source, ...$classTypes);
        $arguments = [...$builder->describeArguments()];

        $source = $this->transformSource($source, ...$arguments);
        $children = [];

        foreach ($arguments as $argument) {
            $name = $argument->name();
            $type = $argument->type();
            $attributes = $argument->attributes();
            $value = array_key_exists($name, $source) ? $source[$name] : $argument->defaultValue();

            $child = $shell->child($name, $type, $value, $attributes);
            $children[] = $rootBuilder->build($child);
        }

        $object = $this->buildObject($builder, $children);

        return Node::branch($shell, $object, $children);
    }

    /**
     * @return array<ClassType>
     */
    private function classTypes(Type $type): array
    {
        if ($type instanceof ClassType) {
            return [$type];
        }

        if ($type instanceof UnionType) {
            return array_filter($type->types(), static fn (Type $subType) => $subType instanceof ClassType);
        }

        return [];
    }

    /**
     * @param mixed $source
     */
    private function builder($source, ClassType ...$classTypes): ObjectBuilder
    {
        $builders = [];

        foreach ($classTypes as $classType) {
            $class = $this->classDefinitionRepository->for($classType);

            try {
                $builders[] = $this->objectBuilderFactory->for($class, $source);
            } catch (SuitableObjectBuilderNotFound $exception) {
                if (count($classTypes) === 1) {
                    throw $exception;
                }
            }
        }

        return $this->objectBuilderFilterer->filter($source, ...$builders);
    }

    /**
     * @param mixed $source
     * @return mixed[]
     */
    private function transformSource($source, Argument ...$arguments): array
    {
        if ($source === null || count($arguments) === 0) {
            return [];
        }

        if (is_iterable($source) && ! is_array($source)) {
            $source = iterator_to_array($source);
        }

        if (count($arguments) === 1) {
            $name = $arguments[0]->name();

            if (! is_array($source) || ! array_key_exists($name, $source)) {
                $source = [$name => $source];
            }
        }

        if (! is_array($source)) {
            throw new InvalidSourceForObject($source);
        }

        return $source;
    }

    /**
     * @param Node[] $children
     */
    private function buildObject(ObjectBuilder $builder, array $children): ?object
    {
        $arguments = [];

        foreach ($children as $child) {
            if (! $child->isValid()) {
                return null;
            }

            $arguments[$child->name()] = $child->value();
        }

        return $builder->build($arguments);
    }
}
