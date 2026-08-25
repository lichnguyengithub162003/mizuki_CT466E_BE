<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\RefundRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RefundService extends BaseService
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly WalletRepository $wallets,
        private readonly WalletTransactionRepository $walletTransactions,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Refund::class);

        return $this->refunds->paginateForAdmin(
            role: $user->role,
            branchId: $user->branch_id,
            filters: $filters,
            perPage: (int) ($filters['per_page'] ?? 20),
        );
    }

    public function detail(User $user, int $refundId): ?Refund
    {
        $refund = $this->refunds->findForAdmin($refundId, $user->role, $user->branch_id);

        if ($refund === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $refund);

        return $refund;
    }

    /** @param array{approved_amount?: int, review_note?: string|null} $data */
    public function approve(User $user, int $refundId, array $data): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId, $data): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('review', $refund);
            $this->ensureRequested($refund);

            $approvedAmount = (int) ($data['approved_amount'] ?? $refund->requested_amount);

            if ($approvedAmount > $refund->requested_amount) {
                throw ValidationException::withMessages([
                    'approved_amount' => ['Số tiền duyệt không được vượt quá số tiền yêu cầu'],
                ]);
            }

            $approved = $this->refunds->approve(
                refund: $refund,
                approvedAmount: $approvedAmount,
                reviewerId: $user->id,
                reviewNote: $data['review_note'] ?? null,
            );

            if ($approved->order->payment?->status !== PaymentStatus::Paid) {
                return $this->refunds->closeWithoutPayout($approved);
            }

            return $approved;
        });
    }

    /** @param array{review_note: string} $data */
    public function reject(User $user, int $refundId, array $data): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId, $data): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('review', $refund);
            $this->ensureRequested($refund);

            return $this->refunds->reject(
                refund: $refund,
                reviewerId: $user->id,
                reviewNote: $data['review_note'],
            );
        });
    }

    public function payoutToWallet(User $user, int $refundId): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId): ?Refund {
            // Financial rows are always locked in the order Refund -> Wallet.
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('payout', $refund);
            $this->ensurePayable($refund);

            $wallet = $this->wallets->findOrCreateLockedForUser($refund->user_id);

            if ($refund->wallet_transaction_id !== null) {
                $this->ensureExistingPayoutMatches($refund, $wallet);

                return $refund->status === 'refunded' && $refund->refunded_at !== null
                    ? $refund
                    : $this->refunds->markRefunded($refund, $refund->wallet_transaction_id);
            }

            if ($refund->status === 'refunded') {
                throw ValidationException::withMessages([
                    'refund' => ['Yêu cầu hoàn tiền có dữ liệu chi trả không nhất quán'],
                ]);
            }

            $wallet = $this->wallets->credit($wallet, (int) $refund->approved_amount);
            $transaction = $this->walletTransactions->createTransaction([
                'transaction_number' => $this->payoutTransactionNumber($refund),
                'wallet_id' => $wallet->id,
                'order_id' => $refund->order_id,
                'created_by_user_id' => $user->id,
                'type' => WalletTransactionType::Refund,
                'direction' => WalletTransactionDirection::Credit,
                'amount' => (int) $refund->approved_amount,
                'balance_after' => $wallet->balance,
                'reference' => $refund->refund_number,
                'description' => "Hoàn tiền cho đơn hàng {$refund->order->order_number}",
            ]);

            return $this->refunds->markRefunded($refund, $transaction->id);
        });
    }

    private function ensureRequested(Refund $refund): void
    {
        if ($refund->status !== 'requested') {
            throw ValidationException::withMessages([
                'status' => ['Yêu cầu hoàn tiền đã được xử lý'],
            ]);
        }
    }

    private function ensurePayable(Refund $refund): void
    {
        if (! in_array($refund->status, ['approved', 'refunded'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Chỉ yêu cầu hoàn tiền đã duyệt mới có thể chi trả vào ví'],
            ]);
        }

        if ($refund->approved_amount === null || $refund->approved_amount <= 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Số tiền được duyệt phải lớn hơn 0'],
            ]);
        }

        if ($refund->order->payment?->status !== PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'payment' => ['Đơn hàng chưa thanh toán nên không thể chi trả hoàn tiền vào ví'],
            ]);
        }
    }

    private function ensureExistingPayoutMatches(Refund $refund, Wallet $wallet): void
    {
        $transaction = $refund->walletTransaction;

        if ($transaction === null
            || $transaction->wallet_id !== $wallet->id
            || $transaction->order_id !== $refund->order_id
            || $transaction->type !== WalletTransactionType::Refund
            || $transaction->direction !== WalletTransactionDirection::Credit
            || $transaction->amount !== $refund->approved_amount
            || $transaction->reference !== $refund->refund_number) {
            throw ValidationException::withMessages([
                'refund' => ['Dữ liệu chi trả hoàn tiền không hợp lệ'],
            ]);
        }
    }

    private function payoutTransactionNumber(Refund $refund): string
    {
        return 'WR-'.strtoupper(substr(hash('sha256', $refund->refund_number), 0, 20));
    }
}
