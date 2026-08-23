<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StationUser extends Model
{
    protected $fillable = ['station_id', 'user_id', 'role'];
}
