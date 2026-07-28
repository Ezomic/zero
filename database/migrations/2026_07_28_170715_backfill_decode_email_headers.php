<?php

use App\Support\MimeHeader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-decode subjects and sender names stored before the RFC 2047 fix.
     * Only rows that still contain an encoded-word marker ("=?") are touched.
     */
    public function up(): void
    {
        DB::table('emails')
            ->where(function ($q) {
                $q->where('subject', 'like', '%=?%')
                    ->orWhere('from_name', 'like', '%=?%');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $update = [];

                    if ($row->subject !== null && str_contains($row->subject, '=?')) {
                        $decoded = MimeHeader::decode($row->subject);
                        if ($decoded !== $row->subject) {
                            $update['subject'] = $decoded;
                        }
                    }

                    if ($row->from_name !== null && str_contains($row->from_name, '=?')) {
                        $decoded = MimeHeader::decode($row->from_name);
                        if ($decoded !== $row->from_name) {
                            $update['from_name'] = $decoded;
                        }
                    }

                    if ($update !== []) {
                        DB::table('emails')->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        // One-way data cleanup: the original encoded-words are not preserved.
    }
};
