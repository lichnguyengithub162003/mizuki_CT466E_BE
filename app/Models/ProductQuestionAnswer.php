<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_question_id',
    'source',
    'external_key',
    'author_name',
    'answer',
    'answered_at',
    'source_date',
    'sort_order',
])]
class ProductQuestionAnswer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductQuestion, $this>
     */
    public function productQuestion(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class);
    }
}
