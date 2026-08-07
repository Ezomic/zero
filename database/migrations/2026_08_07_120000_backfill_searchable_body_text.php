<?php

use App\Support\SearchableBody;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills body_text for messages that only ever stored an HTML body.
 *
 * emails_fts indexes body_text, and its triggers read that column directly,
 * so a message whose body arrived as HTML contributed nothing to search even
 * after it had been opened (ZERO-102).
 *
 * Local only, no network: these rows already hold their HTML. The much larger
 * population of messages with no body at all is a separate problem, handled by
 * mail:backfill-bodies, which has to talk to the mail server.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('emails')
            ->whereNotNull('body_html')
            ->where(function ($query): void {
                $query->whereNull('body_text')->orWhere('body_text', '');
            })
            ->orderBy('id')
            // Chunked by id rather than chunkById on a query builder so the
            // UPDATE below cannot shift rows out from under the cursor.
            ->select('id', 'body_html')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $text = SearchableBody::fromHtml(is_string($row->body_html) ? $row->body_html : null);

                    if ($text === null) {
                        continue;
                    }

                    // Touches body_text only, which is what fires the FTS
                    // update trigger and reindexes the row.
                    DB::table('emails')->where('id', $row->id)->update(['body_text' => $text]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: the derived text is indistinguishable from a
        // real text part once written, and clearing every body_text would
        // destroy genuine ones.
    }
};
