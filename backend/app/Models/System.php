<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class System extends Model
{
    use SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'icon', 'display_order', 'is_active'];
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(SystemUserRole::class);
    }
}
