<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use App\Http\Resources\CreditResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TicketResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PaginationClamp;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\ServerDetailsResolver;

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

    /**
     * A single service's detail view: the same customer-visible
     * attributes as the list endpoint, plus its generic per-service
     * config option values (RAM/CPU/storage/etc — genuinely different
     * per product, never hardcoded field names), plus an optional
     * provider-specific `server_details` block (Proxmox IP/hostname/OS
     * when applicable, null for every other/unknown provider — see
     * `ServerDetailsResolver`). `findOrFail` on the user's own
     * `services()` relation, exactly like `invoice()`/`ticket()` above,
     * so a mismatched id 404s rather than leaking a 403 that would
     * confirm another customer's service exists.
     */
    public function service(Request $request, int $service)
    {
        $service = Auth::user()->services()
            ->with(['product', 'order', 'configs.configOption', 'configs.configValue'])
            ->findOrFail($service);

        return response()->json([
            'data' => [
                'id' => (string) $service->id,
                'type' => 'services',
                'attributes' => [
                    'quantity' => $service->quantity,
                    'price' => $service->price !== null ? (string) $service->price : null,
                    'status' => $service->status,
                    'currency_code' => $service->currency_code,
                    'expires_at' => $service->expires_at?->toISOString(),
                    'product_name' => $service->product?->name,
                ],
                'configs' => $service->configs->map(function ($config) {
                    return [
                        'option_name' => $config->configOption?->name,
                        'value' => $config->configValue?->name,
                    ];
                })->values(),
                'server_details' => ServerDetailsResolver::resolve($service),
            ],
        ]);
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
