<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A screening is a first pass (the model of the content filtering, which
 * may answer 要確認) or the second pass that a 要確認 gets once, by the next
 * model up, which must decide 採用 or 不採用: no one reviews by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->unsignedTinyInteger('pass')->default(1)->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->dropColumn('pass');
        });
    }
};
