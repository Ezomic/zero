<?php

namespace App\Http\Controllers;

use App\Actions\Mail\QueueOutboundMessage;
use App\Http\Middleware\AuthenticateOutboundToken;
use App\Http\Requests\SendOutboundRequest;
use App\Models\OutboundToken;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OutboundMessageController extends Controller
{
    public function store(SendOutboundRequest $request, QueueOutboundMessage $queue): JsonResponse
    {
        $token = $request->attributes->get(AuthenticateOutboundToken::ATTRIBUTE);

        if (! $token instanceof OutboundToken) {
            throw new RuntimeException('Reached without the token middleware.');
        }

        $message = $queue->handle(
            $token,
            $request->string('to')->toString(),
            $request->string('subject')->toString(),
            $request->string('text')->toString(),
        );

        return response()->json(['id' => $message->id, 'status' => 'queued'], 202);
    }
}
