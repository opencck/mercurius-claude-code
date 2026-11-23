<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use stdClass;

/**
 * The schema of a tool's input as the API takes it: no `oneOf` / `allOf` / `anyOf` at the top, no null branch in a
 * union of an optional field, and an object always has `properties`. The combinators at the top are hints; the
 * application checks the arguments of a call itself. Nested unions of real variants stay.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L286-L301
 *      (normalize_input_schema) — the same three rules
 * ref: references/hermes-agent/tools/schema_sanitizer.py@e96221fa:L192-L223 (_is_null_branch, _carry_union_meta,
 *      strip_nullable_unions) — a union collapses only when a null branch was dropped and one branch is left; the
 *      `nullable` hint is not kept
 */
final class Schema {
    private const UNIONS = ['anyOf', 'oneOf'];
    private const BANNED_TOP = ['oneOf', 'allOf', 'anyOf'];
    private const META = ['description', 'title', 'default', 'examples'];

    public static function normalize(stdClass $schema): stdClass {
        $out = self::rewrite($schema);
        assert($out instanceof stdClass);
        $banned = false;
        foreach (self::BANNED_TOP as $key) {
            if (property_exists($out, $key)) {
                unset($out->{$key});
                $banned = true;
            }
        }
        if ($banned && !property_exists($out, 'type')) {
            $out->type = 'object';
        }
        if (($out->type ?? null) === 'object' && !(($out->properties ?? null) instanceof stdClass)) {
            $out->properties = new stdClass();
        }
        return $out;
    }

    private static function rewrite(mixed $node): mixed {
        if (is_array($node)) {
            return array_map(self::rewrite(...), $node);
        }
        if (!$node instanceof stdClass) {
            return $node;
        }
        $out = new stdClass();
        /** @var mixed $value */
        foreach (get_object_vars($node) as $key => $value) {
            $out->{$key} = self::rewrite($value);
        }
        foreach (self::UNIONS as $key) {
            /** @var mixed $variants */
            $variants = $out->{$key} ?? null;
            if (!is_array($variants)) {
                continue;
            }
            $kept = array_values(array_filter($variants, static fn(mixed $item): bool => !($item instanceof stdClass && ($item->type ?? null) === 'null')));
            if (count($kept) !== 1 || count($kept) === count($variants)) {
                continue;
            }
            $survivor = $kept[0] instanceof stdClass ? clone $kept[0] : new stdClass();
            foreach (self::META as $meta) {
                if (property_exists($out, $meta) && !property_exists($survivor, $meta) && !($meta === 'default' && property_exists($survivor, '$ref'))) {
                    $survivor->{$meta} = $out->{$meta};
                }
            }
            // the branch that is left may be a union itself
            return self::rewrite($survivor);
        }
        return $out;
    }
}
