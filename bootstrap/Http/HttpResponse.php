<?php

namespace Nraa\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * HTTP Response helper for controllers
 * Provides convenient methods for returning JSON responses
 */
class HttpResponse
{
    /**
     * Create a JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return JsonResponse
     */
    public static function json(array $data, int $statusCode = 200): JsonResponse
    {
        return new JsonResponse($data, $statusCode);
    }
}
