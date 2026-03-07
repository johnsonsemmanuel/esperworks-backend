<?php

namespace App\Repositories\Interfaces;

use App\Models\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function findById(int $id): ?User;
    public function create(array $data): User;
    public function update(int $id, array $data): User;
    public function delete(int $id): bool;
    public function all(): \Illuminate\Database\Eloquent\Collection;
    public function paginate(int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
}
