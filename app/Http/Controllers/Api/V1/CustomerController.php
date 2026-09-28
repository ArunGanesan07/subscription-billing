<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()
                ->ownedBy($this->merchant($request))
                ->with('activeSubscription')
                ->orderBy('id')
                ->cursorPaginate(50),
        );
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        return new CustomerResource($this->merchant($request)->customers()->create($request->validated()));
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('activeSubscription.segments.plan'));
    }
}
