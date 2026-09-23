<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            // ここに「キー => 値」を並べる
            'id'              => $this->id,
            'name'            => $this->name,
            'email'           => $this->email,
            'department'      => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null,
            'is_system_admin' => $this->is_system_admin,
            'systems'         => $this->systemRoles->map(fn ($r) => [
                'key'  => $r->system->key,
                'name' => $r->system->name,
                'role' => $r->role,
            ])->values(),
        ];
    }
}
