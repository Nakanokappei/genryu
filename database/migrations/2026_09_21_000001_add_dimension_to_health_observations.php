<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health metrics need a sub-key (the entrypoint URL for entry counts) so a
 * baseline can be computed per entrypoint rather than per source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_observations', function (Blueprint $table) {
            $table->text('dimension')->nullable()->after('metric');
            $table->index(['source_id', 'metric', 'dimension', 'observed_at'], 'health_observations_baseline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('health_observations', function (Blueprint $table) {
            $table->dropIndex('health_observations_baseline_idx');
            $table->dropColumn('dimension');
        });
    }
};
