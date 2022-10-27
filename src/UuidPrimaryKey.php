<?php

namespace APPA\Crud;

trait UuidPrimaryKey
{
    protected $primaryKey = 'id';

    /**
     * Return primary key column fo the model
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->model->getKeyName();
    }
}
