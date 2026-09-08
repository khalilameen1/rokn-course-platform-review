<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioDeletedUpload extends Model
{
    public $timestamps = false;

    protected $fillable = ['portfolio_item_id', 'client_request_id'];
}
