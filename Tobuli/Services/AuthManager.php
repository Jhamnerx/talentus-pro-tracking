<?php

namespace Tobuli\Services;

use Illuminate\Support\Arr;
use CustomFacades\Validators\AdminUserLoginMethodsValidator;
use Tobuli\Entities\User;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\Auth\AuthInterface;
use Tobuli\Services\Auth\ConfigurableInterface;

class AuthManager
{
    /**
     * @var AuthInterface[]
     */
    private $auths;

    public function __construct($auths)
    {
        $this->auths = $auths;
    }

    /**
     * @throws ValidationException
     */
    public function storeGeneralSettings(array $input)
    {
        AdminUserLoginMethodsValidator::validate('update', $input);

        $input['login_methods'] = Arr::only($input['login_methods'], $this->getAuthKeys());

        settings('user_login_methods.general', $input);
    }

    /**
     * @throws ValidationException
     */
    public function storeConfig(string $authKey, array $input)
    {
        return $this->getConfigurableAuthByKey($authKey)->storeConfig($input);
    }

    public function checkConfigErrors(string $authKey, array $config): array
    {
        return $this->getConfigurableAuthByKey($authKey)->checkConfigErrors($config);
    }

    private function getConfigurableAuthByKey(string $key): ConfigurableInterface
    {
        $auth = $this->getAuthByKey($key);

        if ($auth instanceof ConfigurableInterface) {
            return $auth;
        }

        throw new \InvalidArgumentException('Auth is not configurable');
    }

    public function getAuthByKey(string $key): AuthInterface
    {
        foreach ($this->auths as $auth) {
            if ($auth->getKey() === $key) {
                return $auth;
            }
        }

        throw new \InvalidArgumentException('Unknown auth: ' . $key);
    }

    public function getAuths()
    {
        return $this->auths;
    }

    public function getAuthKeys()
    {
        $keys = [];

        foreach ($this->auths as $auth) {
            $keys[] = $auth->getKey();
        }

        return $keys;
    }

    public function isAuthEnabledToUser(User $user, string $authKey): bool
    {
        $loginMethods = $user->loginMethods;

        $usesDefault = $loginMethods->isEmpty();

        if ($usesDefault) {
            return self::isAuthEnabledByDefault($authKey);
        }

        return $loginMethods->where('type', $authKey)->where('enabled', 1)->count();
    }

    public static function isAuthEnabledByDefault(string $authKey): bool
    {
        $defaultMethods = self::getDefaultAuths();

        return !empty($defaultMethods[$authKey]);
    }

    public static function getEnabledDefaultAuths()
    {
        return array_filter(self::getDefaultAuths());
    }

    public static function getDefaultAuths()
    {
        return settings('user_login_methods.general.login_methods');
    }
}