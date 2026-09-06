<?php

namespace App\Services\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReturnStatus;
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

    /** @return array{pending_action: int, requested: int, approved_pending_payout: int} */
    public function counts(User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', Refund::class);

        return $this->refunds->countsForAdmin($user->role, $user->branch_id);
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
                returnRequired: (bool) ($data['return_required'] ?? false),
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

    public function completeManualSettlement(User $user, int $refundId, string $reference): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId, $reference): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('payout', $refund);

            if ($refund->status === 'refunded'
                && in_array($refund->settlement_method, ['vnpay', 'manual_external'], true)) {
                return $refund;
            }

            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages(['status' => ['Chỉ yêu cầu đã duyệt mới có thể xác nhận hoàn ngoài hệ thống']]);
            }

            $this->ensureInspectionAllowsSettlement($refund);

            if ($refund->order->payment?->status !== PaymentStatus::Paid
                || $refund->order->payment?->method !== PaymentMethod::VNPay) {
                throw ValidationException::withMessages(['payment' => ['Chỉ giao dịch VNPAY sử dụng luồng xác nhận hoàn về giao dịch gốc']]);
            }

            return $this->refunds->closeWithoutPayout(
                $refund,
                'vnpay',
                trim($reference),
            );
        });
    }

    public function receiveReturn(User $user, int $refundId): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manageReturn', $refund);
            $this->ensureReturnRequired($refund);

            if (in_array($refund->return_status, [
                RefundReturnStatus::Received,
                RefundReturnStatus::Restocked,
            ], true)) {
                return $refund;
            }

            if (! in_array($refund->status, ['approved', 'refunded'], true)
                || ! in_array($refund->return_status, [
                    RefundReturnStatus::AwaitingReturn,
                    RefundReturnStatus::InTransit,
                ], true)) {
                throw ValidationException::withMessages([
                    'return_status' => ['Trạng thái hiện tại không thể ghi nhận đã nhận hàng hoàn'],
                ]);
            }

            return $this->refunds->markReturnReceived($refund);
        });
    }

    public function restock(User $user, int $refundId): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manageReturn', $refund);
            $this->ensureReturnRequired($refund);

            if ($refund->return_status === RefundReturnStatus::Restocked) {
                return $refund;
            }

            if ($refund->return_status !== RefundReturnStatus::Received) {
                throw ValidationException::withMessages([
                    'return_status' => ['Chỉ có thể nhập kho sau khi đã nhận hàng hoàn'],
                ]);
            }

            if ($refund->order->items->isEmpty()
                || $refund->order->items->contains(fn ($item): bool => $item->product_variant_id === null)) {
                throw ValidationException::withMessages([
                    'items' => ['Không thể xác định đầy đủ biến thể của đơn hàng để nhập kho'],
                ]);
            }

            try {
                return $this->refunds->restockFullOrder($refund, $user->id);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    'inventory' => [$exception->getMessage()],
                ]);
            }
        });
    }

    public function markNotRestockable(User $user, int $refundId): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manageReturn', $refund);
            $this->ensureReturnRequired($refund);

            if ($refund->return_status === RefundReturnStatus::NotRestockable) {
                return $refund;
            }

            if ($refund->return_status !== RefundReturnStatus::Received) {
                throw ValidationException::withMessages([
                    'return_status' => ['Chỉ có thể đánh dấu không nhập kho sau khi đã nhận hàng hoàn'],
                ]);
            }

            return $this->refunds->markReturnNotRestockable($refund);
        });
    }

    public function rejectReturnInspection(User $user, int $refundId): ?Refund
    {
        return $this->refunds->transaction(function () use ($user, $refundId): ?Refund {
            $refund = $this->refunds->lockForAdmin($refundId, $user->role, $user->branch_id);

            if ($refund === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manageReturn', $refund);
            $this->ensureReturnRequired($refund);

            if ($refund->return_inspection_status === 'rejected') {
                return $refund;
            }

            if ($refund->status !== 'approved'
                || $refund->return_status !== RefundReturnStatus::Received
                || $refund->return_inspection_status !== 'pending') {
                throw ValidationException::withMessages([
                    'return_inspection_status' => ['Chỉ có thể từ chối sau khi đã nhận hàng và đang chờ kiểm tra'],
                ]);
            }

            return $this->refunds->rejectReturnInspection($refund, $user->id);
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

    private function ensureReturnRequired(Refund $refund): void
    {
        if (! $refund->return_required) {
            throw ValidationException::withMessages([
                'return_required' => ['Yêu cầu hoàn tiền này không yêu cầu hoàn hàng'],
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

        $this->ensureInspectionAllowsSettlement($refund);

        if ($refund->order->payment?->status !== PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'payment' => ['Đơn hàng chưa thanh toán nên không thể chi trả hoàn tiền vào ví'],
            ]);
        }

        if (! in_array($refund->order->payment?->method, [
            PaymentMethod::Wallet,
            PaymentMethod::Cash,
            PaymentMethod::BankTransfer,
        ], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Phương thức thanh toán này không hoàn tiền vào Ví Mizuki'],
            ]);
        }
    }

    private function ensureInspectionAllowsSettlement(Refund $refund): void
    {
        if (! $refund->return_required) {
            return;
        }

        $accepted = in_array($refund->return_inspection_status, [
            'accepted_restockable',
            'accepted_not_restockable',
        ], true);
        $legacyAccepted = $refund->return_inspection_status === null
            && in_array($refund->return_status, [
                RefundReturnStatus::Restocked,
                RefundReturnStatus::NotRestockable,
            ], true);

        if (! $accepted && ! $legacyAccepted) {
            throw ValidationException::withMessages([
                'return_inspection_status' => ['Phải hoàn tất kiểm tra hàng hoàn trước khi hoàn tiền'],
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
