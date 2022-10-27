<?php

namespace App\Libraries\Crud;

interface CrudServiceInterface
{
    public function __construct(CrudRepository $repo);
}
