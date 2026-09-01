<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use App\Http\Resources\CreditResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TicketResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PaginationClamp;

/**
 * Customer's own resources — every query is scoped to `Auth::id()`
 * (the OAuth-authenticated user, `auth:api` + `scope:profile` guard),
 * never trusting a client-supplied user id. Uses the exact same
 * Eloquent models and JSON:API resources the admin API uses, just
 * filtered to the caller's own records — no separate parallel
 * implementation, no risk of the Bridge's view of a resource drifting
 * from Paymenter core's.
 */
class CustomerController
{
    public function orders(Request $request)
    {
        $orders = Auth::user()->orders()
            ->with('services')
            ->latest()
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return OrderResource::collection($orders);
    }

    public function services(Request $request)
    {
        $services = Auth::user()->services()
            ->with(['product', 'order'])
            ->latest()
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return ServiceResource::collection($services);
    }

    public function invoices(Request $request)
    {
        $invoices = Auth::user()->invoices()
            ->with('items')
            ->latest()
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return InvoiceResource::collection($invoices);
    }

    public function invoice(Request $request, int $invoice)
    {
        $invoice = Auth::user()->invoices()->with('items')->findOrFail($invoice);

        return new InvoiceResource($invoice);
    }

    public function credits(Request $request)
    {
        $credits = Auth::user()->credits()
            ->latest()
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return CreditResource::collection($credits);
    }

    public function tickets(Request $request)
    {
        $tickets = Auth::user()->tickets()
            ->with('messages')
            ->latest()
            ->paginate(PaginationClamp::perPage($request), page: PaginationClamp::page($request));

        return TicketResource::collection($tickets);
    }

    public function ticket(Request $request, int $ticket)
    {
        $ticket = Auth::user()->tickets()->with('messages.user')->findOrFail($ticket);

        return new TicketResource($ticket);
    }

    /**
     * Creates a new support ticket for the authenticated customer only —
     * `user_id` is always `Auth::id()`, never taken from the request
     * body, so a customer can never open a ticket as someone else.
     */
    public function createTicket(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        $ticket = Auth::user()->tickets()->create([
            'subject' => $validated['subject'],
            'status' => 'open',
            'priority' => 'medium',
            'department' => $validated['department'] ?? null,
        ]);

        $ticket->messages()->create([
            'user_id' => Auth::id(),
            'message' => $validated['message'],
        ]);

        return new TicketResource($ticket->fresh(['messages']));
    }

    /**
     * Replies to an existing ticket owned by the authenticated customer —
     * `findOrFail` on the user's own `tickets()` relation means a
     * customer can never post into someone else's ticket, not even by
     * guessing an id (a mismatched id 404s, it never leaks a 403 that
     * would confirm the ticket exists).
     */
    public function replyToTicket(Request $request, int $ticket)
    {
        $ticket = Auth::user()->tickets()->findOrFail($ticket);

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $ticket->messages()->create([
            'user_id' => Auth::id(),
            'message' => $validated['message'],
        ]);

        return new TicketResource($ticket->fresh(['messages']));
    }
}
