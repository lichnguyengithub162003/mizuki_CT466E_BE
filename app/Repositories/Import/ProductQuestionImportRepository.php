<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;

class ProductQuestionImportRepository
{
    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array{created: int, updated: int, unchanged: int, deleted: int}  $questionCounters
     * @param  array{created: int, updated: int, unchanged: int, deleted: int}  $answerCounters
     */
    public function synchronize(
        Product $product,
        array $questions,
        array &$questionCounters,
        array &$answerCounters,
    ): void {
        $existing = ProductQuestion::query()
            ->where('product_id', $product->id)
            ->where('source', 'hasaki')
            ->lockForUpdate()
            ->get()
            ->keyBy('external_key');
        $incomingKeys = [];

        foreach ($questions as $attributes) {
            $answers = $attributes['answers'];
            unset($attributes['answers']);
            $externalKey = (string) $attributes['external_key'];
            $incomingKeys[] = $externalKey;
            $question = $existing->get($externalKey);

            if ($question === null) {
                $question = ProductQuestion::query()->create($attributes + [
                    'product_id' => $product->id,
                ]);
                $questionCounters['created']++;
            } else {
                $question->fill($attributes);

                if ($question->isDirty()) {
                    $question->save();
                    $questionCounters['updated']++;
                } else {
                    $questionCounters['unchanged']++;
                }
            }

            $this->synchronizeAnswers($question, $answers, $answerCounters);
        }

        $staleQuestions = $existing->reject(
            static fn (ProductQuestion $question, string $key): bool => in_array(
                $key,
                $incomingKeys,
                true,
            ),
        );

        foreach ($staleQuestions as $staleQuestion) {
            $hasNativeAnswers = ProductQuestionAnswer::query()
                ->where('product_question_id', $staleQuestion->id)
                ->where(function ($query): void {
                    $query->whereNull('source')->orWhere('source', '!=', 'hasaki');
                })
                ->exists();

            if ($hasNativeAnswers) {
                continue;
            }

            $answerCounters['deleted'] += ProductQuestionAnswer::query()
                ->where('product_question_id', $staleQuestion->id)
                ->where('source', 'hasaki')
                ->count();
            $staleQuestion->delete();
            $questionCounters['deleted']++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $answers
     * @param  array{created: int, updated: int, unchanged: int, deleted: int}  $counters
     */
    private function synchronizeAnswers(
        ProductQuestion $question,
        array $answers,
        array &$counters,
    ): void {
        $existing = ProductQuestionAnswer::query()
            ->where('product_question_id', $question->id)
            ->where('source', 'hasaki')
            ->lockForUpdate()
            ->get()
            ->keyBy('external_key');
        $incomingKeys = [];

        foreach ($answers as $attributes) {
            $externalKey = (string) $attributes['external_key'];
            $incomingKeys[] = $externalKey;
            $answer = $existing->get($externalKey);

            if ($answer === null) {
                ProductQuestionAnswer::query()->create($attributes + [
                    'product_question_id' => $question->id,
                ]);
                $counters['created']++;

                continue;
            }

            $answer->fill($attributes);

            if ($answer->isDirty()) {
                $answer->save();
                $counters['updated']++;
            } else {
                $counters['unchanged']++;
            }
        }

        $stale = $existing->reject(
            static fn (ProductQuestionAnswer $answer, string $key): bool => in_array(
                $key,
                $incomingKeys,
                true,
            ),
        );

        if ($stale->isNotEmpty()) {
            ProductQuestionAnswer::query()->whereIn('id', $stale->pluck('id'))->delete();
            $counters['deleted'] += $stale->count();
        }
    }
}
