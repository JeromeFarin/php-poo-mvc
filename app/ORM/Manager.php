<?php

namespace App\ORM;

/**
 * Class Manager
 * @package App\ORM
 */
class Manager
{
    /**
     * @var \PDO
     */
    protected $pdo;

    /**
     * @var string
     */
    private $model;

    /**
     * @var array
     */
    private $metadata;

    /**
     * Manager constructor.
     * @param \PDO $pdo
     * @param $model
     * @throws ORMException
     */
    public function __construct(\PDO $pdo, $model)
    {
        $this->pdo = $pdo;
        $reflectionClass = new \ReflectionClass($model);
        if($reflectionClass->getParentClass()->getName() == Model::class) {
            $this->model = $model;
            $this->metadata = $this->model::metadata();
        }else{
            throw new ORMException("Cette classe n'est pas une entité.");
        }
        $this->model = $model;
    }

    /**
     * @param $property
     * @return mixed
     */
    public function getColumnByProperty($property)
    {
        $property = lcfirst($property);
        $columns = array_keys(array_filter($this->metadata["columns"], function($column) use ($property) {
            return $column["property"] == $property;
        }));
        $column = array_shift($columns);
        return $column;
    }

    /**
     * @param array $filters
     * @return string
     */
    private function where($filters = [])
    {
        if(!empty($filters)) {
            $conditions = [];
            foreach($filters as $property => $value) {
                $conditions[] = sprintf("%s = :%s",$this->getColumnByProperty($property), $property);
            }
            return sprintf("WHERE %s", implode($conditions, " AND "));
        }
        return "";
    }

    /**
     * @param array $sorting
     * @return string
     */
    private function orderBy($sorting = [])
    {
        if(!empty($sorting)) {
            $sorts = [];
            foreach($sorting as $property => $value) {
                $sorts[] = sprintf("%s %s",$this->getColumnByProperty($property), $value);
            }
            return sprintf("ORDER BY %s", implode($sorts, ","));
        }
        return "";
    }

    /**
     * @param integer $length
     * @param integer $start
     * @return string
     */
    public function limit($length, $start)
    {
        if($length !== null) {
            if($start !== null) {
                return sprintf("LIMIT %s,%s", $start, $length);
            }
            return sprintf("LIMIT %s", $length);
        }
        return "";
    }

    /**
     * @param array $filters
     * @return Model
     */
    public function fetch($filters = [])
    {
        $sqlQuery = sprintf("SELECT * FROM %s %s LIMIT 0,1", $this->metadata["table"], $this->where($filters));
        $statement = $this->pdo->prepare($sqlQuery);
        $statement->execute($filters);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);
        return (new $this->model())->hydrate($result);
    }

    /**
     * @param array $filters
     * @param array $sorting
     * @param null|integer $length
     * @param null|integer $start
     * @return array
     */
    private function fetchAll($filters = [] ,$sorting = [], $length = null, $start = null)
    {
        $sqlQuery = sprintf("SELECT * FROM %s %s %s %s", $this->metadata["table"], $this->where($filters), $this->orderBy($sorting), $this->limit($length, $start));
        $statement = $this->pdo->prepare($sqlQuery);
        $statement->execute($filters);
        $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $data = [];
        foreach($results as $result) {
            $data[] = (new $this->model())->hydrate($result);
        }
        return $data;
    }

    /**
     * @param array $filters
     * @return Model
     */
    public function findOneBy($filters = [])
    {
        return $this->fetch($filters);
    }

    /**
     * @param array $filters
     * @param array $orderBy
     * @param null|integer $length
     * @param null|integer $start
     * @return array
     */
    public function findBy($filters = [], $orderBy = [], $length = null, $start = null)
    {
        return $this->fetchAll($filters, $orderBy, $length, $start);
    }

    /**
     * @param mixed $id
     * @return Model
     */
    public function find($id)
    {
        return $this->fetch([$this->metadata["primaryKey"] => $id]);
    }

    /**
     * @return array
     */
    public function findAll()
    {
        return $this->fetchAll();
    }

    /**
     * @param $name
     * @param $arguments
     * @return Model
     */
    public function __call($name, $arguments)
    {
        if(preg_match("/^findOneBy([A-Za-z]+)$/", $name, $matches)) {
            return $this->findOneBy([$matches[1] => $arguments[0]]);
        }elseif(preg_match("/^findBy([A-Za-z]+)$/", $name, $matches)) {
            $arguments[1] = $arguments[1] ?? [];
            $arguments[2] = $arguments[2] ?? null;
            $arguments[3] = $arguments[3] ?? null;
            return $this->fetchAll([$matches[1] => $arguments[0]], $arguments[1], $arguments[2], $arguments[3]);
        }
    }

    public function persist(Model $model)
    {
        if($model->getPrimaryKey()) {
            $this->update($model);
        }else{
            $this->insert($model);
        }
    }

    /**
     * @param Model $model
     */
    private function update(Model &$model)
    {
        $set = [];
        $parameters = [];
        foreach(array_keys($this->metadata["columns"]) as $column)
        {
            $sqlValue = $model->getSQLValueByColumn($column);
            if($sqlValue !== $model->originalData[$column]) {
                $parameters[$column] = $sqlValue;
                $model->orignalData[$column] = $sqlValue;
                $set[] = sprintf("%s = :%s", $column, $column);
            }
        }
        if(count($set)) {
            $sqlQuery = sprintf("UPDATE %s SET %s WHERE %s = :id", $this->metadata["table"], implode(",", $set), $this->metadata["primaryKey"]);
            $statement = $this->pdo->prepare($sqlQuery);
            $statement->execute(["id" => $model->getPrimaryKey()]);
        }
    }

    /**
     * @param Model $model
     */
    private function insert(Model &$model)
    {
        $set = [];
        $parameters = [];
        foreach(array_keys($this->metadata["columns"]) as $column)
        {
            $sqlValue = $model->getSQLValueByColumn($column);
            $model->orignalData[$column] = $sqlValue;
            $parameters[$column] = $sqlValue;
            $set[] = sprintf("%s = :%s", $column, $column);
        }
        $sqlQuery = sprintf("INSERT INTO %s SET %s", $this->metadata["table"], implode(",", $set));
        $statement = $this->pdo->prepare($sqlQuery);
        $statement->execute($parameters);
        $model->setPrimaryKey($this->pdo->lastInsertId());
    }

    /**
     * @param Model $model
     */
    public function remove(Model $model)
    {
        $sqlQuery = sprintf("DELETE FROM %s WHERE %s = :id", $this->metadata["table"], $this->metadata["primaryKey"]);
        $statement = $this->pdo->prepare($sqlQuery);
        $statement->execute(["id" => $model->getPrimaryKey()]);
    }
}