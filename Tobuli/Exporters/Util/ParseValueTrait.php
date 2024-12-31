<?php

namespace Tobuli\Exporters\Util;

trait ParseValueTrait
{
    protected array $attributeHeaders = [];

    protected function parseValues($item, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[] = strpos($attribute, '.')
                ? $this->parseRelationValue($item, $attribute)
                : $item->$attribute;
        }

        return $values;
    }

    private function parseRelationValue($item, string $key)
    {
        $relations = explode('.', $key);
        $value = $item;

        foreach ($relations as $relation) {
            $value = $value->$relation ?? null;

            if (!$value) {
                return null;
            }
        }

        return $value;
    }

    public function setAttributeHeaders(array $attributeHeaders): self
    {
        $this->attributeHeaders = $attributeHeaders;

        return $this;
    }

    public function getHeaders(array $attributes): array
    {
        $headers = [];

        foreach ($attributes as $attribute) {
            $headers[] = $this->attributeHeaders[$attribute] ?? $attribute;
        }

        return $headers;
    }
}
