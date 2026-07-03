<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Jobs\ExportUserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /me/export: queue the portability job; the signed link arrives by email.
 */
final class ExportController extends Controller
{
    public function request(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        ExportUserData::dispatch($user->id);

        return response()->noContent(202);
    }
}
