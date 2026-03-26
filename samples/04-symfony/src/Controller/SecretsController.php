<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bella\BellaAppSecrets;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecretsController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Untyped: all 8 secrets via getenv().
     */
    #[Route('/', name: 'secrets', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'PORT'                        => getenv('PORT') ?: null,
            'DATABASE_URL'                => getenv('DATABASE_URL') ?: null,
            'EXTERNAL_API_KEY'            => getenv('EXTERNAL_API_KEY') ?: null,
            'GLEAP_API_KEY'               => getenv('GLEAP_API_KEY') ?: null,
            'ENABLE_FEATURES'             => getenv('ENABLE_FEATURES') ?: null,
            'APP_ID'                      => getenv('APP_ID') ?: null,
            'ConnectionStrings__Postgres' => getenv('ConnectionStrings__Postgres') ?: null,
            'APP_CONFIG'                  => getenv('APP_CONFIG') ?: null,
        ]);
    }

    /**
     * Typed: all 8 secrets via BellaAppSecrets.
     */
    #[Route('/typed', name: 'secrets_typed', methods: ['GET'])]
    public function typed(): JsonResponse
    {
        $s = new BellaAppSecrets();
        return new JsonResponse([
            'PORT'                        => (string) $s->port(),
            'DATABASE_URL'                => $s->databaseUrl(),
            'EXTERNAL_API_KEY'            => $s->externalApiKey(),
            'GLEAP_API_KEY'               => $s->gleapApiKey(),
            'ENABLE_FEATURES'             => $s->enableFeatures() ? 'true' : 'false',
            'APP_ID'                      => $s->appId(),
            'ConnectionStrings__Postgres' => $s->connectionStringsPostgres(),
            'APP_CONFIG'                  => $s->appConfig(),
        ]);
    }
}
