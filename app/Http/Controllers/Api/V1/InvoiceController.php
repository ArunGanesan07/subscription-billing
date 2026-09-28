<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['customer_id' => ['sometimes', 'integer']]);

        return InvoiceResource::collection(
            Invoice::query()
                ->ownedBy($this->merchant($request))
                ->with('customer:id,name')
                ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->cursorPaginate(50),
        );
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['lines', 'customer:id,name']));
    }
}
