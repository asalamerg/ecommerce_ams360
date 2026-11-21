<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives browser reports of blocked content. Reports come from anyone, so only
 * a few short fields are logged and oversized bodies are ignored.
 */
class CspReportController extends Controller
{
    private const int MAX_BYTES = 8192;

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        $report = strlen($body) <= self::MAX_BYTES ? json_decode($body, true) : null;
        $report = is_array($report) ? ($report['csp-report'] ?? $report) : [];

        $fields = collect(['document-uri', 'violated-directive', 'effective-directive', 'blocked-uri', 'source-file', 'line-number'])
            ->mapWithKeys(fn (string $field): array => [
                $field => is_array($report) && is_scalar($report[$field] ?? null) ? Str::limit((string) $report[$field], 300) : '',
            ])
            ->filter();

        if ($fields->isNotEmpty()) {
            Log::warning('CSP violation', $fields->all());
        }

        return response()->noContent();
    }
}
