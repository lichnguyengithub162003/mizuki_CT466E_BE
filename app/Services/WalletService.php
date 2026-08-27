<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class WalletService extends BaseService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly WalletTransactionRepository $transactions,
        private readonly PaymentRepository $payments,
    ) {}

    public function forCustomer(User $user): Wallet
    {
        return $this->wallets->transaction(
            fn (): Wallet => $this->wallets->findOrCreateLockedForUser($user->id),
        );
    }

    /** @return array{balance: int, payable: bool, shortfall: int} */
    public function affordabilityForCustomer(User $user, int $totalAmount): array
    {
        $wallet = $this->forCustomer($user);

        return [
            'balance' => $wallet->balance,
            'payable' => $wallet->balance >= $totalAmount,
            'shortfall' => max($totalAmount - $wallet->balance, 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{wallet: Wallet, transactions: LengthAwarePaginator<int, WalletTransaction>}
     */
    public function transactionsForCustomer(User $user, array $filters): array
    {
        $wallet = $this->forCustomer($user);

        return [
            'wallet' => $wallet,
            'transactions' => $this->transactions->paginateForWallet(
                walletId: $wallet->id,
                filters: $filters,
                perPage: (int) ($filters['per_page'] ?? 20),
            ),
        ];
    }

    /**
     * Lock and validate the wallet before checkout mutates stock or creates records.
     */
    public function lockForCheckout(User $user, int $totalAmount): Wallet
    {
        $wallet = $this->wallets->findOrCreateLockedForUser($user->id);

        if ($wallet->balance < $totalAmount) {
            $this->validationError(
                'balance',
                'Số tiền trong ví không đủ để thanh toán đơn hàng này!',
            );
        }

        return $wallet;
    }

    /**
     * Complete a wallet payment inside the checkout transaction.
     */
    public function completeCheckoutPayment(
        User $user,
        Order $order,
        Payment $payment,
        Wallet $wallet,
    ): WalletTransaction {
        $this->validateCheckoutPayment($user, $order, $payment, $wallet);
        $wallet = $this->wallets->debit($wallet, $payment->amount);
        $transaction = $this->transactions->createTransaction([
            'transaction_number' => 'WT-'.$payment->payment_number,
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'created_by_user_id' => $user->id,
            'type' => WalletTransactionType::OrderPayment,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $payment->amount,
            'balance_after' => $wallet->balance,
            'reference' => $payment->payment_number,
            'description' => "Thanh toán đơn hàng {$order->order_number}",
        ]);
        $this->payments->markWalletPaid($payment, $transaction->id);

        return $transaction;
    }

    private function validateCheckoutPayment(
        User $user,
        Order $order,
        Payment $payment,
        Wallet $wallet,
    ): void {
        if ($order->payment_method !== PaymentMethod::Wallet
            || $payment->method !== PaymentMethod::Wallet) {
            $this->validationError('payment_method', 'Đơn hàng không sử dụng phương thức ví');
        }

        if ($order->user_id !== $user->id || $wallet->user_id !== $user->id) {
            $this->validationError('wallet', 'Ví không thuộc khách hàng đặt đơn');
        }

        if ($payment->status !== PaymentStatus::Pending) {
            $this->validationError('payment', 'Giao dịch không còn ở trạng thái chờ thanh toán');
        }

        if ($payment->amount !== $order->total_amount) {
            $this->validationError('amount', 'Số tiền thanh toán không khớp với đơn hàng');
        }

        if ($wallet->balance < $payment->amount) {
            $this->validationError(
                'balance',
                'Số tiền trong ví không đủ để thanh toán đơn hàng này!',
            );
        }
    }

    private function validationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
