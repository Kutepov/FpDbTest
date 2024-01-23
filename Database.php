<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private const PARAMETER_SYMBOL = '?';
    private const SPECIFIER_INT = 'd';
    private const SPECIFIER_FLOAT = 'f';
    private const SPECIFIER_ARRAY = 'a';
    private const SPECIFIER_IDENTIFIER = '#';

    private const SPECIFIERS = [
        self::SPECIFIER_INT,
        self::SPECIFIER_FLOAT,
        self::SPECIFIER_ARRAY,
        self::SPECIFIER_IDENTIFIER,
    ];

    private const AVAILABLE_TYPES_ARGS = [
        'string',
        'integer',
        'double',
        'boolean',
        'NULL',
    ];
    private const OPENING_CHARACTER_CONDITIONAL_BLOCK = '{';
    private const CLOSING_CHARACTER_CONDITIONAL_BLOCK = '}';

    private const TYPE_PART_QUERY = 'query';
    private const TYPE_PART_CONDITION = 'condition';
    private const TYPE_PART_PARAMETER = 'parameter';
    private const SKIP_VALUE = 'skip';

    private mysqli $mysqli;
    private string $partQueryString = '';
    private int $countParameters = 0;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $this->countParameters = 0;
        $parseQueryArray = $this->parseQuery($query);
        if ($this->validateArgs($args)) {
            $parseQueryArray = $this->insertParameters($parseQueryArray, $args);
            return $this->createQueryString($parseQueryArray);
        } else {
            throw new Exception('Args is not valid');
        }
    }

    public function skip(): string
    {
        return self::SKIP_VALUE;
    }

    private function parseQuery(string $query): array
    {
        $parseQueryArray = [];
        $queryStringLength = strlen($query);
        for ($i = 0; $i < $queryStringLength; $i++) {
            if ($query[$i] === self::OPENING_CHARACTER_CONDITIONAL_BLOCK) {
                $parseQueryArray[] = $this->addPartQuery(self::TYPE_PART_QUERY);
                $i++;
                while ($query[$i] !== self::CLOSING_CHARACTER_CONDITIONAL_BLOCK) {
                    if ($query[$i] === self::OPENING_CHARACTER_CONDITIONAL_BLOCK) {
                        throw new Exception('Nested conditional blocks.');
                    }

                    $this->partQueryString .= $query[$i];
                    $i++;

                    if ($i >= $queryStringLength) {
                        throw new Exception('Bad conditional blocks.');
                    }
                }

                $parseQueryArray[] = $this->addPartQuery(self::TYPE_PART_CONDITION);
                $currentKey = array_key_last($parseQueryArray);
                $parseQueryArray[$currentKey]['value'] = $this->parseQuery(
                    $parseQueryArray[$currentKey]['value']
                );
            } elseif ($query[$i] === self::PARAMETER_SYMBOL) {
                $parseQueryArray[] = $this->addPartQuery(self::TYPE_PART_QUERY);
                $this->partQueryString = $query[$i];

                if (in_array($query[$i + 1], self::SPECIFIERS)) {
                    $this->partQueryString .= $query[$i + 1];
                    $i++;
                }

                $parseQueryArray[] = $this->addPartQuery(self::TYPE_PART_PARAMETER);
                $this->countParameters++;
            } else {
                $this->partQueryString .= $query[$i];
            }
        }

        if ($this->partQueryString !== '') {
            $parseQueryArray[] = $this->addPartQuery(self::TYPE_PART_QUERY);
        }

        return $parseQueryArray;
    }

    private function addPartQuery(string $type): array
    {
        $result = [
            'type' => $type,
            'value' => $this->partQueryString
        ];
        $this->partQueryString = '';
        return $result;
    }

    private function validateArgs(array $args): bool
    {
        return $this->countParameters === count($args);
    }

    private function insertParameters(array $parseQueryArray, array $args): array
    {
        $numberOfParameter = 0;
        foreach ($parseQueryArray as $index => $item) {
            switch ($item['type']) {
                case self::TYPE_PART_QUERY:
                    break;
                case self::TYPE_PART_PARAMETER:
                    $parseQueryArray[$index]['value'] = $this->insertParam($item['value'], $args[$numberOfParameter]);
                    $numberOfParameter++;
                    break;
                case self::TYPE_PART_CONDITION:
                    foreach ($item['value'] as $conditionBlockIndex => $conditionBlockItem) {
                        if ($conditionBlockItem['type'] === self::TYPE_PART_PARAMETER) {
                            if ($this->isNeedSkip($args[$numberOfParameter])) {
                                unset($parseQueryArray[$index]);
                                $numberOfParameter++;
                                break;
                            }

                            $parseQueryArray[$index]['value'][$conditionBlockIndex]['value'] = $this->insertParam(
                                $conditionBlockItem['value'],
                                $args[$numberOfParameter]
                            );
                            $numberOfParameter++;
                        }
                    }
                    break;
            }
        }

        return $parseQueryArray;
    }

    private function insertParam(string $specifier, $arg)
    {
        if ($specifier === self::PARAMETER_SYMBOL) {
            return $this->prepareArg(is_string($arg) ? "'" . $arg . "'" : $arg);
        }

        $specifier = $specifier[1];
        if (!in_array($specifier, self::SPECIFIERS)) {
            throw new Exception('Unavailable specifier.');
        }

        $returnArg = null;
        switch ($specifier) {
            case self::SPECIFIER_INT:
                $returnArg = is_null($arg) ? $arg : $this->prepareArg((integer)$arg);
                break;
            case self::SPECIFIER_FLOAT:
                $returnArg = $this->prepareArg((double)$arg);
                break;
            case self::SPECIFIER_ARRAY:
                if (!is_array($arg)) {
                    throw new Exception('Bad type arg.');
                }

                $returnArg = $this->processingArrayParam($arg);
                break;
            case self::SPECIFIER_IDENTIFIER:
                $returnArg = is_array($arg) ? $this->processingArrayParam(
                    $arg,
                    self::SPECIFIER_IDENTIFIER
                ) : '`' . $this->prepareArg($arg) . '`';
                break;
        }

        return $returnArg;
    }

    private function processingArrayParam(array $arg, string $type = self::SPECIFIER_ARRAY): string
    {
        if (array_is_list($arg)) {
            $arg = implode(', ', array_map(function ($string) use ($type) {
                $quote = $type === self::SPECIFIER_IDENTIFIER ? "`" : "'";
                return is_string($string) ? $quote . $string . $quote : $string;
            }, $arg));
            $returnArg = $this->prepareArg($arg);
        } else {
            $string = [];
            foreach ($arg as $index => $item) {
                $string[] = '`' . $this->prepareArg($index) . '` = ' . $this->prepareArg(
                        (is_string($item) ? "'" . $item . "'" : $item)
                    );
            }
            $returnArg = implode(', ', $string);
        }

        return $returnArg;
    }

    private function prepareArg($arg): string
    {
        if (in_array(gettype($arg), self::AVAILABLE_TYPES_ARGS)) {
            if (is_bool($arg)) {
                $arg = (int)$arg;
            }
            if (is_null($arg)) {
                $arg = 'NULL';
            }

            return $arg;
        } else {
            throw new Exception('Unavailable type of arg.');
        }
    }

    private function isNeedSkip($item): bool
    {
        if (is_array($item)) {
            foreach ($item as $value) {
                if ($value === $this->skip()) {
                    return true;
                }
            }
        } else {
            if ($item === $this->skip()) {
                return true;
            }
        }

        return false;
    }

    private function createQueryString(array $parseQueryArray): string
    {
        $result = '';
        foreach ($parseQueryArray as $itemQuery) {
            if ($itemQuery['type'] === self::TYPE_PART_CONDITION) {
                foreach ($itemQuery['value'] as $itemConditionQuery) {
                    $result .= $itemConditionQuery['value'];
                }
            } else {
                $result .= $itemQuery['value'];
            }
        }

        return $result;
    }

}
