<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Rokn API",
 *     description="Rokn mobile learning API"
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="Rokn API"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="APIToken",
 *     type="apiKey",
 *     name="Authorization",
 *     in="header"
 * )
 */
class APIController extends Controller
{
    //
}
