<?php

namespace Nuwave\Lighthouse\Schema\AST;

use GraphQL\Utils\AST;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\ObjectFieldNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\FieldDefinitionNode;

class ASTHelper
{
    /**
     * This function exists as a workaround for an issue within webonyx/graphql-php.
     *
     * The problem is that lists of definitions are usually NodeList objects - except
     * when the list is empty, then it is []. This function corrects that inconsistency
     * and allows the rest of our code to not worry about it until it is fixed.
     *
     * This issue is brought up here https://github.com/webonyx/graphql-php/issues/285
     * Remove this method (and possibly the entire class) once it is resolved.
     *
     * @param NodeList|array $original
     * @param array          $addition
     *
     * @return NodeList
     */
    public static function mergeNodeList($original, $addition): NodeList
    {
        if (! $original instanceof NodeList) {
            $original = new NodeList($original);
        }

        return $original->merge($addition);
    }

    /**
     * This function will merge two lists uniquely by name. Fields will
     * be removed from the original list if the name exists in both lists.
     *
     * @param NodeList|array $original
     * @param array          $addition
     *
     * @return NodeList
     */
    public static function mergeUniqueNodeList($original, $addition): NodeList
    {
        $newFields = collect($addition)->pluck('name.value')->filter()->all();
        $filteredList = collect($original)->filter(function ($field) use ($newFields) {
            return ! in_array(data_get($field, 'name.value'), $newFields);
        })->values()->all();

        return self::mergeNodeList($filteredList, $addition);
    }

    /**
     * Create a clone of the original node.
     *
     * @param Node $node
     *
     * @return Node
     */
    public static function cloneNode(Node $node): Node
    {
        return AST::fromArray($node->toArray(true));
    }

    /**
     * @param FieldDefinitionNode $field
     *
     * @return string
     * @throws \Exception
     */
    public static function getFieldTypeName(FieldDefinitionNode $field): string
    {
        $type = $field->type;
        if ($type instanceof ListTypeNode || $type instanceof NonNullTypeNode){
            $type = self::getUnderlyingNamedTypeNode($type);
        }
        
        /** @var NamedTypeNode $type */
        return $type->name->value;
    }
    
    /**
     * @param Node $node
     *
     * @return NamedTypeNode
     * @throws \Exception
     */
    public static function getUnderlyingNamedTypeNode(Node $node): NamedTypeNode
    {
        if($node instanceof NamedTypeNode){
            return $node;
        }
        
        $type = data_get($node, 'type');
        
        if(!$type){
            throw new \Exception("The node '$node->kind' does not have a type associated with it.");
        }
        
        return self::getUnderlyingNamedTypeNode($type);
    }

    /**
     * @param DirectiveNode $directive
     * @param string $name
     * @param mixed|null $default
     *
     * @return mixed|null
     */
    public static function directiveArgValue(DirectiveNode $directive, string $name, $default = null)
    {
        $arg = collect($directive->arguments)->first(function (ArgumentNode $argumentNode) use ($name) {
            return $argumentNode->name->value === $name;
        });

        return $arg
            ? self::argValue($arg, $default)
            : $default;
    }


    /**
     * Get argument's value.
     *
     * @param Node  $arg
     * @param mixed $default
     *
     * @return mixed
     */
    public static function argValue(Node $arg, $default = null)
    {
        $valueNode = $arg->value;

        if (! $valueNode) {
            return $default;
        }

        if ($valueNode instanceof ListValueNode) {
            return collect($valueNode->values)->map(function (ValueNode $valueNode) {
                return $valueNode->value;
            })->toArray();
        }

        if ($valueNode instanceof ObjectValueNode) {
            return collect($valueNode->fields)
                ->mapWithKeys(function (ObjectFieldNode $field) {
                    return [$field->name->value => self::argValue($field)];
                })
                ->toArray();
        }

        return $valueNode->value;
    }
}
