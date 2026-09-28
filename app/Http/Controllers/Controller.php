<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The authenticated tenant (set by AuthenticateMerchant). */
    protected function merchant(Request $request): Merchant
    {
        return $request->user();
    }
}
