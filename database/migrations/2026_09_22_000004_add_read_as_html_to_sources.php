<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A probed feed can be unrelated to the page the operator chose (CNRS:
 * /rss.xml is a newsletter, the page is the press list). The operator can
 * tell the crawler to skip feeds and read the page as an HTML list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('read_as_html')->default(false)->after('list_config');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('read_as_html');
        });
    }
};
