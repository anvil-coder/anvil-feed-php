<?php

declare(strict_types=1);

namespace AnvilFeed\Tests\Conformance;

/**
 * A validator for exactly the JSON Schema 2020-12 keywords `schema/v1.0/event.schema.json` uses — and for
 * nothing else. It exists so that a PHP test can hold the serialiser against the one normative schema
 * without a runtime dependency the SDK would then carry into somebody's production tree.
 *
 * The obvious objection to a hand-written validator is that a bug in it makes the gate pass everything, and
 * a gate that passes everything proves nothing. That is why {@see SchemaConformanceTest} runs it over all
 * nine `conformance/` fixtures first and asserts each verdict against `cases.json`: the five that must be
 * refused are refused, for the five separate reasons Task 0 built them for. A vacuous validator cannot get
 * through that, and the same nine fixtures are what ajv is held against in `conformance/tools/validate.mjs`.
 *
 * Values are the output of json_decode WITHOUT associative arrays: a JSON object is a stdClass and a JSON
 * array is a PHP array, so `{}` and `[]` stay distinguishable. An assoc-array decode would silently make an
 * empty object look like an empty array, which is the one shape mistake most worth catching.
 */
final class JsonSchemaValidator
{
    private function __construct(private readonly mixed $schema)
    {
    }

    public static function forSchemaFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('cannot read the schema at ' . $path);
        }

        return new self(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> every violation, empty when the value is valid */
    public function errors(mixed $value): array
    {
        return $this->check($value, $this->schema, '#');
    }

    public function isValid(mixed $value): bool
    {
        return $this->errors($value) === [];
    }

    /** @return list<string> */
    private function check(mixed $value, mixed $schema, string $path): array
    {
        if ($schema === true) {
            return [];
        }
        if ($schema === false) {
            return [$path . ': no value is allowed here'];
        }
        if (!$schema instanceof \stdClass) {
            throw new \RuntimeException($path . ': a schema must be an object or a boolean');
        }

        $errors = [];
        foreach (get_object_vars($schema) as $keyword => $argument) {
            $errors = [...$errors, ...$this->applyKeyword($keyword, $argument, $value, $schema, $path)];
        }

        return $errors;
    }

    /** @return list<string> */
    private function applyKeyword(string $keyword, mixed $argument, mixed $value, \stdClass $schema, string $path): array
    {
        return match ($keyword) {
            '$schema', '$id', 'title', 'description', 'examples', 'default' => [],
            'type' => $this->matchesType($value, $argument) ? []
                : [$path . ': expected ' . $argument . ', got ' . get_debug_type($value)],
            'const' => $value === $argument ? []
                : [$path . ': must be equal to constant ' . var_export($argument, true)],
            'enum' => in_array($value, $argument, true) ? []
                : [$path . ': must be one of ' . implode('|', array_map(strval(...), $argument))],
            'pattern' => !is_string($value) || preg_match($this->delimited($argument), $value) === 1 ? []
                : [$path . ': must match ' . $argument],
            'minLength' => !is_string($value) || mb_strlen($value) >= $argument ? []
                : [$path . ': shorter than ' . $argument],
            'maxLength' => !is_string($value) || mb_strlen($value) <= $argument ? []
                : [$path . ': longer than ' . $argument . ' (' . mb_strlen($value) . ')'],
            'maxItems' => !is_array($value) || count($value) <= $argument ? []
                : [$path . ': more than ' . $argument . ' items (' . count($value) . ')'],
            'maxProperties' => !$value instanceof \stdClass
                || count(get_object_vars($value)) <= $argument ? []
                : [$path . ': more than ' . $argument . ' properties ('
                    . count(get_object_vars($value)) . ')'],
            'format' => $this->formatErrors($value, $argument, $path),
            'minimum' => !is_int($value) && !is_float($value) || $value >= $argument ? []
                : [$path . ': below ' . $argument],
            'required' => $this->requiredErrors($value, $argument, $path),
            'properties' => $this->propertyErrors($value, $argument, $path),
            'additionalProperties' => $this->additionalPropertyErrors($value, $argument, $schema, $path),
            'propertyNames' => $this->propertyNameErrors($value, $argument, $path),
            'items' => $this->itemErrors($value, $argument, $path),
            'not' => $this->check($value, $argument, $path) === []
                ? [$path . ': must NOT be valid against the "not" schema'] : [],
            default => throw new \RuntimeException(
                'the schema uses the keyword "' . $keyword . '" at ' . $path
                . ', which this validator does not implement — extend it rather than let it pass silently'
            ),
        };
    }

    /**
     * Only the formats schema 1.0 actually uses, and "date-time" is checked the way ajv-formats checks it:
     * the grammar first, then the calendar. A pattern alone accepts 2026-02-30 and hour 25, and the server
     * answers schema_violation to both because Instant.parse refuses them.
     *
     * @return list<string>
     */
    private function formatErrors(mixed $value, string $format, string $path): array
    {
        if (!is_string($value)) {
            return [];
        }

        return match ($format) {
            'date-time' => $this->isDateTime($value) ? []
                : [$path . ': "' . $value . '" is not an RFC 3339 date-time'],
            default => throw new \RuntimeException(
                'the schema uses the format "' . $format . '" at ' . $path
                . ', which this validator does not implement'
            ),
        };
    }

    private function isDateTime(string $value): bool
    {
        $grammar = '~^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$~';
        if (preg_match($grammar, $value, $parts) !== 1) {
            return false;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return false;
        }
        // Second 60 is the leap second RFC 3339 allows; the hour and minute have no such exception.
        if ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 60) {
            return false;
        }
        $zone = $parts[8];
        if ($zone !== 'Z' && $zone !== 'z') {
            [$hours, $minutes] = explode(':', substr($zone, 1));

            return (int) $hours <= 23 && (int) $minutes <= 59;
        }

        return true;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => throw new \RuntimeException('unknown type "' . $type . '"'),
        };
    }

    /** @return list<string> */
    private function requiredErrors(mixed $value, array $required, string $path): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }
        $errors = [];
        foreach ($required as $name) {
            if (!property_exists($value, $name)) {
                $errors[] = $path . ': the required property "' . $name . '" is missing';
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function propertyErrors(mixed $value, \stdClass $properties, string $path): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }
        $errors = [];
        foreach (get_object_vars($properties) as $name => $subSchema) {
            if (property_exists($value, $name)) {
                $errors = [...$errors, ...$this->check($value->$name, $subSchema, $path . '/' . $name)];
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function additionalPropertyErrors(mixed $value, mixed $subSchema, \stdClass $schema, string $path): array
    {
        if (!$value instanceof \stdClass || $subSchema === true) {
            return [];
        }
        $known = isset($schema->properties) ? array_keys(get_object_vars($schema->properties)) : [];
        $errors = [];
        foreach (get_object_vars($value) as $name => $item) {
            if (!in_array($name, $known, true)) {
                $errors = [...$errors, ...$this->check($item, $subSchema, $path . '/' . $name)];
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function propertyNameErrors(mixed $value, mixed $subSchema, string $path): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }
        $errors = [];
        foreach (array_keys(get_object_vars($value)) as $name) {
            $errors = [...$errors, ...$this->check((string) $name, $subSchema, $path . '/' . $name . ' (name)')];
        }

        return $errors;
    }

    /** @return list<string> */
    private function itemErrors(mixed $value, mixed $subSchema, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }
        $errors = [];
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...$this->check($item, $subSchema, $path . '/' . $index)];
        }

        return $errors;
    }

    /** JSON Schema patterns are ECMA regexes with no delimiters; "~" never occurs in schema 1.0's. */
    private function delimited(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~u';
    }
}
