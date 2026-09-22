<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 素材情報 changed from a list of the policy's items, each quoted from
 * the document, to a dossier: what changed, seen through the editorial
 * lenses that hold, every statement saying whether it comes from the
 * primary source, from general knowledge or from inference. The columns
 * carry over — the dossier is JSON in `data` as the items were, with
 * the same pins and usage beside it — but nothing can read the old
 * shape, so the materials extracted so far go, and the articles written
 * from them with them. Both are made again by the buttons on their
 * screens; the documents, which cost the network, are untouched.
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
