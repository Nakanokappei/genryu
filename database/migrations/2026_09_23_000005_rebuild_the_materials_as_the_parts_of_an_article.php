<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 素材情報 is the parts an article is made of — the angle, before /
 * change / after, the facts of the primary source and the background
 * from the model's own knowledge — and nothing else: the lenses, the
 * claims, the confidence and the strength of the dossier tried earlier
 * the same day were notes about the answer, not material to write with.
 * The columns do not move and none is added: the shape will change
 * again, and a shape that lives in JSON can. The materials of the old
 * shape go, and the articles written from them with them; both are made
 * again from their screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('articles')->delete();
        DB::table('materials')->delete();
    }

    public function down(): void
    {
        // Nothing to put back: the materials of the old shape are gone for good.
    }
};
