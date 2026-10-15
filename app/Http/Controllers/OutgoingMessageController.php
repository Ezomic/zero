<?php

namespace App\Http\Controllers;

use App\Actions\Mail\ListOutgoingStatus;
use App\Actions\Mail\PurgeOutgoingMessages;
use App\Actions\Mail\UndoOutgoingMessage;
use App\Concerns\InteractsWithCurrentUser;
use App\Models\OutgoingMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Status, undo and dismiss for messages held after Send (ZERO-117). Every
 * lookup goes through the signed-in user's own messages, so another user's id
 * is a 404 exactly like an id that does not exist.
 */
class OutgoingMessageController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(ListOutgoingStatus $status): JsonResponse
    {
        return response()->json(['messages' => $status->handle($this->currentUser())]);
    }

    public function undo(int $outgoing, UndoOutgoingMessage $undo): JsonResponse
    {
        $message = $this->own($outgoing);

        if (! $undo->handle($message)) {
            return response()->json([
                'message' => 'Too late to undo: this message is already on its way or gone.',
            ], 409);
        }

        return response()->json(['restore_url' => route('compose.create', ['restore' => $message->id])]);
    }

    public function dismiss(int $outgoing, PurgeOutgoingMessages $purge): Response|JsonResponse
    {
        $message = $this->own($outgoing);

        if (! $message->canBeReopened() && $message->status !== OutgoingMessage::SENT) {
            return response()->json(['message' => 'This message is still being sent.'], 409);
        }

        $purge->discard($message);

        return response()->noContent();
    }

    protected function own(int $id): OutgoingMessage
    {
        return $this->currentUser()->outgoingMessages()->findOrFail($id);
    }
}
