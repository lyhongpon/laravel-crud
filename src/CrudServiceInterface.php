<?php

namespace APPA\Crud;

interface CrudServiceInterface
{
    public function __construct(CrudRepository $repo);
}
