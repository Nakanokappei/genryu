<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns and stored values renamed after the words the screens use.
 */
return new class extends Migration
{
    /** Stored values renamed, [table, column, old, new]. */
    private const VALUES = [
        ['spot_checks', 'verdict', 'unsure', 'cannot_tell'],
        ['spot_checks', 'stratum', 'passed', 'let_through'],
        ['spot_checks', 'stratum', 'near', 'just_below'],
        ['spot_checks', 'stratum', 'far', 'far_below'],
        ['sources', 'status', 'pending', 'configuring'],
        ['sources', 'status', 'ready', 'configured'],
        ['articles', 'status', 'draft', 'written'],
        ['editorial_policies', 'layer', 'exclude_keywords', 'title_filter'],
        ['prompts', 'layer', 'exclude_keywords', 'title_filter'],
    ];

    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->renameColumn('title', 'headline');
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->renameColumn('list_config', 'html_list_settings');
            $table->renameColumn('json_config', 'json_list_settings');
            $table->renameColumn('document_config', 'document_settings');
            $table->renameColumn('fetched_at', 'updates_fetched_at');
            $table->string('status', 16)->default('configuring')->change();
            // 一覧の取得方法 as chosen on the source; html replaces read_as_html.
            $table->string('list_method', 8)->nullable();
        });

        Schema::table('materials', function (Blueprint $table): void {
            $table->renameColumn('data', 'parts');
            $table->renameColumn('validation', 'failed_checks');
        });

        Schema::table('spot_checks', function (Blueprint $table): void {
            $table->renameColumn('passed', 'let_through');
            $table->string('stratum', 16)->change();
            $table->string('verdict', 16)->nullable()->change();
        });

        Schema::table('prompts', function (Blueprint $table): void {
            $table->renameColumn('name', 'layer');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->renameColumn('screening_id', 'latest_screening_id');
        });

        Schema::table('screenings', function (Blueprint $table): void {
            $table->renameColumn('primary_reason', 'reason_class');
        });

        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->renameColumn('days', 'period_days');
            $table->renameColumn('times', 'publication_times');
        });

        Schema::table('language_settings', function (Blueprint $table): void {
            $table->renameColumn('prompt', 'additional_prompt');
        });

        Schema::table('article_images', function (Blueprint $table): void {
            $table->renameColumn('model', 'scene_model');
        });

        // Rename the stored values.
        foreach (self::VALUES as [$table, $column, $old, $new]) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }

        DB::table('sources')->where('read_as_html', true)->update(['list_method' => 'html']);

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn('read_as_html');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->boolean('read_as_html')->default(false);
        });

        DB::table('sources')->where('list_method', 'html')->update(['read_as_html' => true]);

        // Restore the stored values.
        foreach (array_reverse(self::VALUES) as [$table, $column, $old, $new]) {
            DB::table($table)->where($column, $new)->update([$column => $old]);
        }

        Schema::table('article_images', fn (Blueprint $table) => $table->renameColumn('scene_model', 'model'));
        Schema::table('language_settings', fn (Blueprint $table) => $table->renameColumn('additional_prompt', 'prompt'));

        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->renameColumn('period_days', 'days');
            $table->renameColumn('publication_times', 'times');
        });

        Schema::table('screenings', fn (Blueprint $table) => $table->renameColumn('reason_class', 'primary_reason'));
        Schema::table('documents', fn (Blueprint $table) => $table->renameColumn('latest_screening_id', 'screening_id'));
        Schema::table('prompts', fn (Blueprint $table) => $table->renameColumn('layer', 'name'));
        Schema::table('spot_checks', function (Blueprint $table): void {
            $table->renameColumn('let_through', 'passed');
            $table->string('stratum', 8)->change();
            $table->string('verdict', 8)->nullable()->change();
        });

        Schema::table('materials', function (Blueprint $table): void {
            $table->renameColumn('parts', 'data');
            $table->renameColumn('failed_checks', 'validation');
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn('list_method');
            $table->string('status', 16)->default('pending')->change();
            $table->renameColumn('html_list_settings', 'list_config');
            $table->renameColumn('json_list_settings', 'json_config');
            $table->renameColumn('document_settings', 'document_config');
            $table->renameColumn('updates_fetched_at', 'fetched_at');
        });

        Schema::table('articles', fn (Blueprint $table) => $table->renameColumn('headline', 'title'));
    }
};
