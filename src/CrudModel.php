<?php

namespace App\Libraries\Crud;

use Illuminate\Database\Eloquent\Model;

class CrudModel extends Model
{
    public $preventsLazyLoading = true;
}
