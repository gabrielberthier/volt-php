<?php

namespace Asyncopath\Data;

class TabularConverter
{
    public function walkTuple(array $rows, string $class)
    {
        $parser = new DtoParser;
        $data = $parser->parse($class);
        $res = $this->walk($rows, $data);
        echo 'After parsing in foreach: '.PHP_EOL;
        echo json_encode($res).PHP_EOL;
    }

    private function walk(array $rows, array $data)
    {
        ['props' => $props, 'join' => $join] = $data;

        $result = [];
        $sqlJoin = new SqlJoin;
        if ($join) {
            foreach ($join as $key => $value) {
                $meta = $join[$key]['meta'] ?? [];
                $rightProps = $join[$key]['props'] ?? [];

                $result = $sqlJoin->parseLeftJoin($rows, 'id', $props, $rightProps, $key);

                $walked = $this->walk($rows, $join[$key]);

                foreach ($walked as $id => $w) {
                    if ($meta['is_array']) {
                        $existent = $result[$id][$key] ?? [];
                        $result[$id][$key] = [...$existent, $w];
                    } else {
                        $result[$id][$key] = $w;
                    }
                }
            }
        }

        return $result;
    }
}
