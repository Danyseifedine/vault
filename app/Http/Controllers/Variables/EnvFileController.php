<?php

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\ExportEnvFile;
use App\Actions\Variables\ImportEnvFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\ImportEnvRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk in and out.
 *
 * An export is a bulk reveal and is treated as one: same permission, same
 * audit entry, and the file is streamed rather than stored anywhere. Both
 * actions authorize internally, so there is exactly one check per operation.
 */
class EnvFileController extends Controller
{
    public function export(
        Request $request,
        Organization $organization,
        Project $project,
        Environment $environment,
        ExportEnvFile $export,
    ): StreamedResponse {
        $contents = $export($environment, $request->user());
        $filename = "{$project->slug}.{$environment->slug}.env";

        return response()->streamDownload(
            fn () => print $contents,
            $filename,
            ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store'],
        );
    }

    public function import(
        ImportEnvRequest $request,
        Organization $organization,
        Project $project,
        Environment $environment,
        ImportEnvFile $import,
    ): RedirectResponse {
        $result = $import($environment, $request->user(), $request->contents());

        return back()->with(
            'success',
            "Imported into {$environment->name}: {$result['created']} created, {$result['updated']} updated.",
        );
    }
}
