<?php

namespace App\Actions\Mail;

use App\Models\PendingMirrorAction;
use App\Support\MirrorBacklog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SummariseMirrorBacklogs
{
    /**
     * One grouped query for the whole page rather than a count per card.
     *
     * @param  Collection<int, int>|array<int, int>  $accountIds
     * @return Collection<int, MirrorBacklog> keyed by account id
     */
    public function handle(Collection|array $accountIds): Collection
    {
        $ids = Collection::wrap($accountIds);

        if ($ids->isEmpty()) {
            return new Collection;
        }

        // A report, not a model read — toBase() keeps the aggregates as plain
        // columns instead of pretending they are attributes of an action.
        $rows = PendingMirrorAction::query()
            ->whereIn('mail_account_id', $ids->all())
            ->groupBy('mail_account_id')
            ->selectRaw('mail_account_id')
            ->selectRaw('sum(case when failed_at is null then 1 else 0 end) as pending')
            ->selectRaw('sum(case when failed_at is null then 0 else 1 end) as abandoned')
            ->selectRaw('min(case when failed_at is null then created_at end) as oldest_queued_at')
            ->toBase()
            ->get();

        $summaries = $rows->mapWithKeys(function (object $row): array {
            $oldest = $row->oldest_queued_at;

            return [
                (is_numeric($row->mail_account_id) ? (int) $row->mail_account_id : 0) => new MirrorBacklog(
                    pending: is_numeric($row->pending) ? (int) $row->pending : 0,
                    oldestQueuedAt: is_string($oldest) ? Carbon::parse($oldest) : null,
                    abandoned: is_numeric($row->abandoned) ? (int) $row->abandoned : 0,
                ),
            ];
        });

        // Every account gets an entry so the view never has to null-check.
        return $ids->mapWithKeys(fn (int $id) => [$id => $summaries->get($id) ?? new MirrorBacklog]);
    }
}
