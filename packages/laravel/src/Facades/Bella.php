<?php

declare(strict_types=1);

namespace BellaBaxter\Laravel\Facades;

use BellaBaxter\BaxterClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string,string> getAllSecrets()
 * @method static string getSecret(string $key)
 * @method static array<string,string> getSecretsVersion(int $version)
 *
 * @see \BellaBaxter\BaxterClient
 */
class Bella extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BaxterClient::class;
    }
}
