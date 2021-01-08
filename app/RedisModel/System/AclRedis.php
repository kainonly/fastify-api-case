<?php
declare (strict_types=1);

namespace App\RedisModel\System;

use Hyperf\DbConnection\Db;
use Hyperf\Extra\Common\RedisModel;
use Hyperf\Support\RedisModel\AclInterface;

class AclRedis extends RedisModel implements AclInterface
{
    protected string $key = 'system:acl';

    /**
     * Clear Cache
     */
    public function clear(): void
    {
        $this->redis->del($this->getKey());
    }

    /**
     * Get Cache
     * @param string $key
     * @param int $policy
     * @return array
     */
    public function get(string $key, int $policy): array
    {
        if (!$this->redis->exists($this->getKey())) {
            $this->update();
        }

        $raws = $this->redis->hGet($this->getKey(), $key);
        $data = !empty($raws) ? json_decode($raws, true) : [];

        switch ($policy) {
            case 0:
                return explode(',', $data['read']);
            case 1:
                return [
                    ...explode(',', $data['read']),
                    ...explode(',', $data['write'])
                ];
            default:
                return [];
        }
    }

    /**
     * Refresh Cache
     */
    private function update(): void
    {
        $query = Db::table('acl')
            ->where('status', '=', 1)
            ->get(['key', 'write', 'read']);

        if ($query->isEmpty()) {
            return;
        }

        $lists = [];
        foreach ($query->toArray() as $value) {
            $lists[$value->key] = json_encode([
                'write' => $value->write,
                'read' => $value->read
            ]);
        }
        $this->redis->hMSet($this->getKey(), $lists);
    }
}