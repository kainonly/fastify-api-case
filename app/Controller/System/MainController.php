<?php
declare(strict_types=1);

namespace App\Controller\System;

use App\Client\CosClient;
use Hyperf\Di\Annotation\Inject;
use Exception;
use App\RedisModel\System\AdminRedis;
use App\RedisModel\System\ResourceRedis;
use App\RedisModel\System\RoleRedis;
use Hyperf\DbConnection\Db;
use Hyperf\Extra\Auth\Auth;
use Hyperf\Extra\Rbac\Rbac;
use Hyperf\Extra\Redis\RefreshToken;
use Hyperf\Extra\Redis\UserLock;
use Hyperf\Utils\Context;
use Psr\Http\Message\ResponseInterface;

class MainController extends BaseController
{
    use Auth, Rbac;

    /**
     * @Inject()
     * @var RefreshToken
     */
    private RefreshToken $refreshToken;
    /**
     * @Inject()
     * @var UserLock
     */
    private UserLock $userLock;
    /**
     * @Inject()
     * @var AdminRedis
     */
    private AdminRedis $adminRedis;
    /**
     * @Inject()
     * @var ResourceRedis
     */
    private ResourceRedis $resourceRedis;
    /**
     * @Inject()
     * @var RoleRedis
     */
    private RoleRedis $roleRedis;
    /**
     * @Inject()
     * @var CosClient
     */
    private CosClient $cosClient;

    /**
     * 用户登录
     */
    public function login(): ResponseInterface
    {
        $body = $this->curd->should([
            'username' => [
                'required',
                'between:4,20'
            ],
            'password' => [
                'required',
                'between:12,20'
            ],
        ]);
        $data = $this->adminRedis->get($body['username']);
        if (empty($data)) {
            return $this->response->json([
                'error' => 1,
                'msg' => 'User does not exist or has been frozen'
            ]);
        }
        if (!$this->userLock->check('admin:' . $body['username'])) {
            $this->userLock->lock('admin:' . $body['username']);
            return $this->response->json([
                'error' => 2,
                'msg' => 'You have failed to log in too many times, please try again later'
            ]);
        }
        if (!$this->hash->check($body['password'], $data['password'])) {
            $this->userLock->inc('admin:' . $body['username']);
            return $this->response->json([
                'error' => 1,
                'msg' => 'User password verification is inconsistent'
            ]);
        }
        $this->userLock->remove('admin:' . $body['username']);
        return $this->create('system', [
            'user' => $data['username'],
        ]);
    }

    /**
     * 用户验证
     */
    public function verify(): ResponseInterface
    {
        return $this->authVerify('system');
    }

    /**
     * 用户登出
     */
    public function logout(): ResponseInterface
    {
        return $this->destory('system');
    }

    /**
     * 获取资源
     * @return array
     */
    public function resource(): array
    {
        return [
            'error' => 0,
            'data' => $this->fetchResource(
                $this->resourceRedis,
                $this->adminRedis,
                $this->roleRedis
            )
        ];
    }

    /**
     * 个人信息
     * @return array
     */
    public function information(): array
    {
        $data = Db::table('admin')
            ->where('username', '=', Context::get('auth')['user'])
            ->first(['email', 'phone', 'call', 'avatar']);

        return [
            'error' => 0,
            'data' => $data
        ];
    }

    /**
     * 更新个人信息
     * @return array
     */
    public function update(): array
    {
        $body = $this->curd->should([
            'old_password' => [
                'sometimes',
                'between:12,20',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[@$!%*?&-+])(?=.*[0-9])[\w|@$!%*?&-+]+$/'
            ],
            'new_password' => [
                'required_with:old_password',
                'between:12,20',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[@$!%*?&-+])(?=.*[0-9])[\w|@$!%*?&-+]+$/'
            ],
        ]);
        $username = Context::get('auth')['user'];
        $data = Db::table('admin')
            ->where('username', '=', $username)
            ->first();

        if (empty($data)) {
            return [
                'error' => 1,
                'msg' => 'not exists'
            ];
        }

        if (!empty($body['old_password'])) {
            if (!$this->hash->check($body['old_password'], $data->password)) {
                return [
                    'error' => 2,
                    'msg' => 'password verification failed'
                ];
            }
            $body['password'] = $this->hash->create($body['new_password']);
        }

        unset($body['old_password'], $body['new_password']);
        Db::table('admin')
            ->where('username', '=', $username)
            ->update($body);

        $this->adminRedis->clear();
        return [
            'error' => 0,
            'msg' => 'ok'
        ];
    }

    /**
     * 上传
     * @return array
     * @throws Exception
     */
    public function uploads(): array
    {
        if (!$this->request->hasFile('image')) {
            return [
                'error' => 1,
                'msg' => 'upload file does not exist'
            ];
        }
        $file = $this->request->file('image');
        $fileName = $this->cosClient->put($file);
        return [
            'error' => 0,
            'data' => [
                'savename' => $fileName
            ]
        ];
    }

    /**
     * 对象存储签名
     * @return array
     * @throws Exception
     */
    public function cosPresigned(): array
    {
        return $this->cosClient->generatePostPresigned([
            ['content-length-range', 0, 104857600]
        ]);
    }
}