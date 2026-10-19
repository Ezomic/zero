<?php

namespace App\View\Components;

use App\Actions\Mail\ListOutgoingStatus;
use App\Models\User;
use Illuminate\View\Component;
use Illuminate\View\View;

class OutgoingStatus extends Component
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function __construct(ListOutgoingStatus $status)
    {
        $user = auth()->user();

        $this->messages = $user instanceof User ? $status->handle($user) : [];
    }

    public function render(): View
    {
        return view('components.outgoing-status');
    }
}
