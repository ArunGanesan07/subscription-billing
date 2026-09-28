<?php

namespace App\Enums;

enum InvoiceLineType: string
{
    case Base = 'base';
    case Overage = 'overage';
}
