<?php

namespace Oro\Component\DoctrineUtils\ORM;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\SQLParserUtils;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;
use Oro\Component\PhpUtils\ArrayUtil;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class QueryUtils
{
    const IN         = 'in';
    const IN_BETWEEN = 'in_between';

    /**
     * @param Query $query
     * @param array $paramMappings
     *
     * @return array
     *
     * @throws QueryException
     */
    public static function processParameterMappings(Query $query, $paramMappings)
    {
        $sqlParams = [];
        $types     = [];

        /** @var Query\Parameter $parameter */
        foreach ($query->getParameters() as $parameter) {
            $key = $parameter->getName();

            if (!isset($paramMappings[$key])) {
                throw QueryException::unknownParameter($key);
            }

            $value = $query->processParameterValue($parameter->getValue());
            $type  = ($parameter->getValue() === $value)
                ? $parameter->getType()
                : Query\ParameterTypeInferer::inferType($value);

            foreach ($paramMappings[$key] as $position) {
                $types[$position] = $type;
            }

            $sqlPositions = $paramMappings[$key];
            $value        = [$value];
            $countValue   = count($value);

            for ($i = 0, $l = count($sqlPositions); $i < $l; $i++) {
                $sqlParams[$sqlPositions[$i]] = $value[($i % $countValue)];
            }
        }

        if (count($sqlParams) !== count($types)) {
            throw QueryException::parameterTypeMismatch();
        }

        if ($sqlParams) {
            ksort($sqlParams);
            $sqlParams = array_values($sqlParams);

            ksort($types);
            $types = array_values($types);
        }

        return [$sqlParams, $types];
    }

    /**
     * @param AbstractPlatform $platform
     *
     * @return ResultSetMapping
     */
    public static function createResultSetMapping(AbstractPlatform $platform)
    {
        return new PlatformResultSetMapping($platform);
    }

    /**
     * @param ResultSetMapping $mapping
     * @param string           $alias
     *
     * @return string
     *
     * @throws QueryException
     */
    public static function getColumnNameByAlias(ResultSetMapping $mapping, $alias)
    {
        foreach ($mapping->scalarMappings as $key => $val) {
            if ($alias === $val) {
                return $key;
            }
        }

        throw new QueryException(sprintf('Unknown column alias: %s', $alias));
    }

    /**
     * Returns an expression in SELECT clause by its alias
     *
     * @param QueryBuilder $qb
     * @param string       $alias An alias of an expression in SELECT clause
     *
     * @return string|null
     */
    public static function getSelectExprByAlias(QueryBuilder $qb, $alias)
    {
        /** @var \Doctrine\ORM\Query\Expr\Select $selectPart */
        foreach ($qb->getDQLPart('select') as $selectPart) {
            foreach ($selectPart->getParts() as $part) {
                if (preg_match_all('#(\,\s*)*(?P<expr>.+?)\\s+AS\\s+(?P<alias>\\w+)#i', $part, $matches)) {
                    foreach ($matches['alias'] as $key => $val) {
                        if ($val === $alias) {
                            return $matches['expr'][$key];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param Query                   $query
     * @param Query\ParserResult|null $parsedQuery
     *
     * @return string
     *
     * @throws QueryException
     */
    public static function getExecutableSql(Query $query, Query\ParserResult $parsedQuery = null)
    {
        if (null === $parsedQuery) {
            $parsedQuery = static::parseQuery($query);
        }

        $sql = $parsedQuery->getSqlExecutor()->getSqlStatements();

        list($params, $types) = self::processParameterMappings($query, $parsedQuery->getParameterMappings());
        list($sql, $params, $types) = SQLParserUtils::expandListParameters($sql, $params, $types);

        $paramPos = SQLParserUtils::getPlaceholderPositions($sql);
        for ($i = count($paramPos) - 1; $i >= 0; $i--) {
            $sql = substr_replace(
                $sql,
                $query->getEntityManager()->getConnection()->quote($params[$i], $types[$i]),
                $paramPos[$i],
                1
            );
        }

        return $sql;
    }

    /**
     * @param Query $query
     *
     * @return Query\ParserResult
     */
    public static function parseQuery(Query $query)
    {
        $parser = new Query\Parser($query);

        return $parser->parse();
    }

    /**
     * Builds CONCAT(...) DQL expression
     *
     * @param string[] $parts
     *
     * @return string
     */
    public static function buildConcatExpr(array $parts)
    {
        $stack = [];
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $stack[] = count($stack) === 0
                ? $parts[$i]
                : sprintf('CONCAT(%s, %s)', $parts[$i], array_pop($stack));
        }

        if (empty($stack)) {
            return '';
        }

        return array_pop($stack);
    }

    /**
     * Gets the root entity alias of the query.
     *
     * @param QueryBuilder $qb             The query builder
     * @param bool         $throwException Whether to throw exception in case the query does not have a root alias
     *
     * @return string|null
     *
     * @throws QueryException
     */
    public static function getSingleRootAlias(QueryBuilder $qb, $throwException = true)
    {
        $rootAliases = $qb->getRootAliases();

        $result = null;
        if (count($rootAliases) !== 1) {
            if ($throwException) {
                $errorReason = count($rootAliases) === 0
                    ? 'the query has no any root aliases'
                    : sprintf('the query has several root aliases. "%s"', implode(', ', $rootAliases));

                throw new QueryException(
                    sprintf(
                        'Can\'t get single root alias for the given query. Reason: %s.',
                        $errorReason
                    )
                );
            }
        } else {
            $result = $rootAliases[0];
        }

        return $result;
    }

    /**
     * Calculates the page offset
     *
     * @param int $page  The page number
     * @param int $limit The maximum number of items per page
     *
     * @return int
     */
    public static function getPageOffset($page, $limit)
    {
        return $page > 0
            ? ($page - 1) * $limit
            : 0;
    }

    /**
     * Applies the given joins for the query builder
     *
     * @param QueryBuilder $qb
     * @param array|null   $joins
     */
    public static function applyJoins(QueryBuilder $qb, $joins)
    {
        if (empty($joins)) {
            return;
        }

        $qb->distinct(true);
        $rootAlias = self::getSingleRootAlias($qb);
        foreach ($joins as $key => $val) {
            if (empty($val)) {
                $qb->leftJoin($rootAlias . '.' . $key, $key);
            } elseif (is_array($val)) {
                if (isset($val['join'])) {
                    $join = $val['join'];
                    if (false === strpos($join, '.')) {
                        $join = $rootAlias . '.' . $join;
                    }
                } else {
                    $join = $rootAlias . '.' . $key;
                }
                $condition     = null;
                $conditionType = null;
                if (isset($val['condition'])) {
                    $condition     = $val['condition'];
                    $conditionType = Expr\Join::WITH;
                }
                if (isset($val['conditionType'])) {
                    $conditionType = $val['conditionType'];
                }
                $qb->leftJoin($join, $key, $conditionType, $condition);
            } else {
                $qb->leftJoin($rootAlias . '.' . $val, $val);
            }
        }
    }

    /**
     * Checks the given criteria and converts them to Criteria object if needed
     *
     * @param Criteria|array|null $criteria
     *
     * @return Criteria
     */
    public static function normalizeCriteria($criteria)
    {
        if (null === $criteria) {
            $criteria = new Criteria();
        } elseif (is_array($criteria)) {
            $newCriteria = new Criteria();
            foreach ($criteria as $fieldName => $value) {
                $newCriteria->andWhere(Criteria::expr()->eq($fieldName, $value));
            }

            $criteria = $newCriteria;
        }

        return $criteria;
    }

    /**
     * @param QueryBuilder $qb
     * @param string       $field
     * @param int[]        $values
     */
    public static function applyOptimizedIn(QueryBuilder $qb, $field, array $values)
    {
        $expressions     = [];
        $optimizedValues = static::optimizeIntegerValues($values);

        if ($optimizedValues[static::IN]) {
            $param = static::generateParameterName($field);

            $qb->setParameter($param, $optimizedValues[static::IN]);
            $expressions[] = $qb->expr()->in($field, sprintf(':%s', $param));
        }

        foreach ($optimizedValues[static::IN_BETWEEN] as $range) {
            list($min, $max) = $range;
            $minParam = static::generateParameterName($field);
            $maxParam = static::generateParameterName($field);

            $qb->setParameter($minParam, $min);
            $qb->setParameter($maxParam, $max);

            $expressions[] = $qb->expr()->between(
                $field,
                sprintf(':%s', $minParam),
                sprintf(':%s', $maxParam)
            );
        }

        if ($expressions) {
            $qb->andWhere(call_user_func_array([$qb->expr(), 'orX'], $expressions));
        }
    }

    /**
     * @param int[] $values
     *
     * @return array
     */
    public static function optimizeIntegerValues(array $values)
    {
        $result = [
            static::IN         => [],
            static::IN_BETWEEN => [],
        ];

        $ranges = ArrayUtil::intRanges($values);
        foreach ($ranges as $range) {
            list($min, $max) = $range;
            if ($min === $max) {
                $result[static::IN][] = $min;
            } else {
                $result[static::IN_BETWEEN][] = $range;
            }
        }

        // when there is lots of ranges, it takes way longer than IN
        if (count($result[static::IN_BETWEEN]) > 1000) {
            $result[static::IN]         = $values;
            $result[static::IN_BETWEEN] = [];
        }

        return $result;
    }

    /**
     * @param string $prefix
     *
     * @return string
     */
    public static function generateParameterName($prefix)
    {
        static $n = 0;
        $n++;

        return sprintf('%s_%d', uniqid(str_replace('.', '', $prefix)), $n);
    }

    /**
     * Removes unused parameters from query builder
     *
     * @param QueryBuilder $qb
     */
    public static function removeUnusedParameters(QueryBuilder $qb)
    {
        $dql = $qb->getDQL();
        $usedParameters = [];
        foreach ($qb->getParameters() as $parameter) {
            /** @var $parameter \Doctrine\ORM\Query\Parameter */
            $parameterName = $parameter->getName();
            if (self::dqlContainsParameter($dql, $parameterName)) {
                $usedParameters[$parameterName] = $parameter->getValue();
            }
        }

        $qb->setParameters($usedParameters);
    }

    /**
     * Returns TRUE if $dql contains usage of parameter with $parameterName
     *
     * @param string $dql
     * @param string $parameterName
     *
     * @return bool
     */
    public static function dqlContainsParameter($dql, $parameterName)
    {
        $pattern = is_numeric($parameterName)
            ? sprintf('/\?%s[^\w]/', preg_quote($parameterName))
            : sprintf('/\:%s[^\w]/', preg_quote($parameterName));

        return (bool) preg_match($pattern, $dql . ' ');
    }

    /**
     * @param string $dql
     *
     * @return array
     */
    public static function getDqlAliases($dql)
    {
        $matches = [];
        preg_match_all('/(FROM|JOIN)\s+\S+\s+(AS\s+)?(\S+)/i', $dql, $matches);

        return $matches[3];
    }

    /**
     * @param string $dql
     * @param array $replacements
     *
     * @return string
     */
    public static function replaceDqlAliases($dql, array $replacements)
    {
        return array_reduce(
            $replacements,
            function ($carry, array $replacement) {
                return preg_replace(sprintf('/(?<=[^\w\.\:])%s(?=\b)/', $replacement[0]), $replacement[1], $carry);
            },
            $dql
        );
    }

    /**
     * @param QueryBuilder $qb
     * @param Expr\Join $join
     *
     * @return string
     */
    public static function getJoinClass(QueryBuilder $qb, Expr\Join $join)
    {
        if (class_exists($join->getJoin())) {
            return $join->getJoin();
        }

        $fromParts = $qb->getDqlPart('from');
        $aliasToClassMap = [];
        foreach ($fromParts as $from) {
            $aliasToClassMap[$from->getAlias()] = $from->getFrom();
        }

        list($parentAlias, $field) = explode('.', $join->getJoin());
        $parentClass = isset($aliasToClassMap[$parentAlias])
            ? $aliasToClassMap[$parentAlias]
            : static::getJoinClass($qb, static::findJoinByAlias($qb, $parentAlias));

        return $qb->getEntityManager()
            ->getClassMetadata($parentClass)
            ->getAssociationTargetClass($field);
    }

    /**
     * @param QueryBuilder $qb
     * @param string $alias
     *
     * @return Expr\Join
     */
    public static function findJoinByAlias(QueryBuilder $qb, $alias)
    {
        $joinParts = $qb->getDQLPart('join');
        foreach ($joinParts as $joins) {
            foreach ($joins as $join) {
                if ($join->getAlias() === $alias) {
                    return $join;
                }
            }
        }

        return null;
    }

    /**
     * @param QueryBuilder $qb
     * @param string $alias
     *
     * @return bool
     */
    public static function isToOne(QueryBuilder $qb, $alias)
    {
        $rootAliases = $qb->getRootAliases();
        if (count($rootAliases) !== 1) {
            return false;
        }

        $joins = [];
        $currentAlias = $alias;
        while ($parentAliasWithJoin = static::findParentAliasWithJoin($qb, $currentAlias)) {
            $currentAlias = key($parentAliasWithJoin);
            $joins[] = reset($parentAliasWithJoin);
        }

        if ($currentAlias !== $rootAliases[0]) {
            return false;
        }

        $rootEntities = $qb->getRootEntities();
        $em = $qb->getEntityManager();

        $metadata = $em->getClassMetadata($rootEntities[0]);
        $startIndex = count($joins) - 1;
        for ($i = $startIndex; $i >= 0; $i--) {
            list(, $field) = explode('.', $joins[$i]);
            if (!isset($metadata->associationMappings[$field])) {
                return false;
            }

            $assoc = $metadata->associationMappings[$field];

            if (!($assoc['type'] & ClassMetadataInfo::TO_ONE)) {
                return false;
            }

            $metadata = $em->getClassMetadata($assoc['targetEntity']);
        }

        return true;
    }

    /**
     * @param QueryBuilder $qb
     * @param string $alias
     *
     * @return array
     * [parent alias, join]
     */
    protected static function findParentAliasWithJoin(QueryBuilder $qb, $alias)
    {
        $joinPart = $qb->getDQLPart('join');
        foreach ($joinPart as $joins) {
            foreach ($joins as $join) {
                if ($join->getAlias() === $alias) {
                    list($parentAlias) = explode('.', $join->getJoin());

                    return [$parentAlias => $join->getJoin()];
                }
            }
        }

        return null;
    }
}
