<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Waitlist extends Model
{
    protected $table = 'waitlist';
    protected $fillable = ['email', 'phone', 'country'];
}
