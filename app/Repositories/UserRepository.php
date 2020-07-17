<?php
declare(strict_types=1);

namespace App\Repositories;

use App\User as ActiveRecord;
use Carbon\Carbon;

class UserRepository
{
    protected ActiveRecord $activeRecord;

    public function __construct(ActiveRecord $model)
    {
        $this->activeRecord = $model;
    }

    public function find(int $id, bool $withTrashed = false): ?ActiveRecord
    {
        $query = $this->activeRecord;

        if ($withTrashed) {
            $query = $query->withTrashed();
        }

        return $query->find($id);
    }

    public function update(array $data): bool
    {
        $data['updated_at'] = Carbon::now();
        $data['deleted_at'] = null;

        $this
            ->activeRecord
            ->withTrashed()
            ->where(['external_id' => $data['external_id']])
            ->update($data);

        return true;
    }

    public function insert(array $data): bool
    {
        $data['created_at'] = Carbon::now();
        $data['updated_at'] = Carbon::now();
        $data['deleted_at'] = null;
        $this
            ->activeRecord
            ->insert($data);

        return true;
    }

    public function exists(int $externalId): bool
    {
        return $this
            ->activeRecord
            ->where(['external_id' => $externalId])
            ->withTrashed()
            ->exists();
    }

    public function deleteOld(Carbon $before): int
    {
        // because we cant use "whereNotIn" for delete from db (may be too many ids),
        // i will delete users by update_at field
        return $this->activeRecord->where('updated_at', '<', $before)->delete();
    }
}
