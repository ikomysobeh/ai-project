<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders the end-user platform guide (docs/12-using-the-platform.md) inside
 * the dashboard so a logged-in user doesn't have to go find it in the repo.
 *
 * Deliberately serves ONLY that one file, not the rest of docs/ — the other
 * docs are developer/architecture-facing (deployment internals, DB schema,
 * security rationale) and shouldn't be exposed to every tenant member with
 * a dashboard login. The real docs/ directory is mounted read-only into
 * this container at /docs (see docker-compose.yml) so this renders the
 * actual source file rather than a copy that can drift out of sync.
 */
class DocsController extends Controller
{
    private const GUIDE_PATH = '/docs/12-using-the-platform.md';

    public function index(): Response
    {
        $html = File::exists(self::GUIDE_PATH)
            ? (new GithubFlavoredMarkdownConverter(['html_input' => 'escape']))
                ->convert(File::get(self::GUIDE_PATH))
                ->getContent()
            : null;

        return Inertia::render('console/docs', ['html' => $html]);
    }
}
