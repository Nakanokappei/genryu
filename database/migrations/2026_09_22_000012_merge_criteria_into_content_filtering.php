<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The content filtering of the editorial policy (UI: 編集方針 — コンテンツ
 * フィルタリング, on the 文書 screen) is one body, the system prompt of
 * the LLM that reads a fetched document, instead of the two criteria
 * for / against fetching: what was written in either is kept, one after
 * the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bodies = DB::table('editorial_policies')->whereIn('layer', ['fetch_criteria', 'skip_criteria'])->orderBy('layer')->pluck('body', 'layer');
        $merged = trim(implode("\n\n", array_filter([$bodies['fetch_criteria'] ?? '', $bodies['skip_criteria'] ?? ''])));

        if ($merged !== '') {
            DB::table('editorial_policies')->updateOrInsert(['layer' => 'content_filtering'], ['body' => $merged, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('editorial_policies')->whereIn('layer', ['fetch_criteria', 'skip_criteria'])->delete();
    }

    public function down(): void
    {
        $body = (string) DB::table('editorial_policies')->where('layer', 'content_filtering')->value('body');

        if ($body !== '') {
            DB::table('editorial_policies')->updateOrInsert(['layer' => 'fetch_criteria'], ['body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('editorial_policies')->where('layer', 'content_filtering')->delete();
    }
};
