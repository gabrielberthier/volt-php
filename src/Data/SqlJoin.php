<?php

namespace Asyncopath\Data;

class SqlJoin
{
    public function parseLeft(
        array $rows,
        string $leftPk,
        array $leftCols,
    ): array {
        /** @var array<string,array> */
        $map = [];
        foreach ($rows as $row) {
            $pkValue = $row[$leftPk];

            if (! \array_key_exists($pkValue, $map)) {
                $leftObject = array_reduce(
                    $leftCols,
                    fn (array $carry, string $col) => [...$carry, $col => $row[$col] ?? null],
                    []
                );
                $map[$pkValue] = $leftObject;
            }
        }

        return $map;

    }

    /**
     * Parses flat SQL LEFT JOIN results into a nested structure.
     *
     * @param array rows - The flat array of objects from the database.
     * @param string leftPk - The primary key column name of the left table.
     * @param string[] leftCols - The column names belonging to the left table.
     * @param string[] rightCols - The column names belonging to the right table.
     * @param string arrayName - The name of the resulting array property.
     * @return array The nested array of objects.
     */
    public function parseLeftJoin(
        array $rows,
        string $leftPk,
        array $leftCols,
        array $rightCols,
        string $arrayName
    ): array {
        /** @var array<string,array> */
        $map = [];

        foreach ($rows as $row) {
            $pkValue = $row[$leftPk];

            if (! \array_key_exists($pkValue, $map)) {
                $leftObject = array_reduce(
                    $leftCols,
                    fn (array $carry, string $col) => [...$carry, $col => $row[$col] ?? null],
                    []
                );
                $leftObject[$arrayName] = [];
                $map[$pkValue] = $leftObject;
            }
            if (\count($rightCols) && $row[$rightCols[0]]) {
                $rightObject = array_reduce(
                    $rightCols,
                    fn (array $carry, string $col) => [...$carry, $col => $row[$col] ?? null],
                    []
                );

                $map[$pkValue][$arrayName][] = $rightObject;
            }
        }

        return $map;
    }

    /**
     * Parses flat SQL LEFT JOIN results into a nested structure.
     *
     * @param array rows - The flat array of objects from the database.
     * @param string leftPk - The primary key column name of the left table.
     * @param string[] leftCols - The column names belonging to the left table.
     * @param string[] rightCols - The column names belonging to the right table.
     * @param string arrayName - The name of the resulting array property.
     * @return array The nested array of objects.
     */
    public function parseInnerJoin(
        array $rows,
        string $leftPk,
        array $leftCols,
        array $rightCols,
        string $arrayName
    ): array {
        /** @var array<string,array> */
        $map = [];

        foreach ($rows as $row) {
            $pkValue = $row[$leftPk];

            if (! \array_key_exists($pkValue, $map)) {
                $leftObject = array_reduce(
                    $leftCols,
                    fn (array $carry, string $col) => [...$carry, $col => $row[$col] ?? null],
                    []
                );
                $leftObject[$arrayName] = [];
                $map[$pkValue] = $leftObject;
            }
            if (\count($rightCols) && $row[$rightCols[0]]) {
                $rightObject = array_reduce(
                    $rightCols,
                    fn (array $carry, string $col) => [...$carry, $col => $row[$col] ?? null],
                    []
                );

                $map[$pkValue][$arrayName] = $rightObject;
            }
        }

        return $map;
    }
}
