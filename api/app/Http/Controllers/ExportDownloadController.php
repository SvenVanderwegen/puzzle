<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Content\ExportFileStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Signed export download (route exports.download): requires a valid signature
 * (24-hour window), a live session for the same user (re-auth), and deletes the
 * file after the first successful download (single-download semantics).
 */
final class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $userId, string $file, ExportFileStore $files): Response
    {
        $user = $request->user();

        abort_if($user === null || $user->getAuthIdentifier() !== $userId, 403);

        $contents = $files->exists($userId, $file) ? $files->get($userId, $file) : null;

        abort_if($contents === null, 410);

        $files->delete($userId, $file);

        return new Response($contents, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="burnfront-export.json"',
        ]);
    }
}
