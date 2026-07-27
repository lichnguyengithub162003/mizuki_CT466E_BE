<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService extends BaseService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
        private readonly VnPayService $vnpay,
    ) {}

    public function createForOrder(
        Order $order,
        PaymentStatus $status = PaymentStatus::Pending,
        ?int $processedByUserId = null,
    ): Payment {
        return $this->payments->transaction(function () use (
            $order,
            $status,
            $processedByUserId,
        ): Payment {
            $lockedOrder = $this->payments->lockOrder($order->id);

            if ($lockedOrder === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
            }

            $existing = $this->payments->findForOrder($lockedOrder->id);

            if ($existing !== null) {
                return $existing;
            }

            return $this->payments->createPayment([
                'payment_number' => $this->generatePaymentNumber(),
                'order_id' => $lockedOrder->id,
                'user_id' => $lockedOrder->user_id,
                'processed_by_user_id' => $processedByUserId,
                'method' => $lockedOrder->payment_method,
                'status' => $status,
                'amount' => $lockedOrder->total_amount,
                'paid_at' => $status === PaymentStatus::Paid ? now() : null,
                'failed_at' => $status === PaymentStatus::Failed ? now() : null,
                'cancelled_at' => $status === PaymentStatus::Cancelled ? now() : null,
                'refunded_at' => $status === PaymentStatus::Refunded ? now() : null,
            ]);
        });
    }

    public function forCustomer(User $user, int $orderId): ?Payment
    {
        $order = $this->orders->findForUser($orderId, $user->id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        return $this->payments->findForOrder($order->id);
    }

    /**
     * @return array{payment_url: string, expires_at: string, payment_number: string}|null
     */
    public function createVnPayUrl(User $user, int $orderId, string $ipAddress): ?array
    {
        $order = $this->orders->findForUser($orderId, $user->id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        $payment = $this->payments->findForOrder($order->id);

        if ($order->payment_method !== PaymentMethod::VNPay
            || $payment?->method !== PaymentMethod::VNPay) {
            $this->validationError('payment_method', 'Đơn hàng không sử dụng phương thức VNPay');
        }

        if ($order->status === OrderStatus::Cancelled) {
            $this->validationError('order', 'Đơn hàng đã hủy, không thể thanh toán');
        }

        if ($payment->status !== PaymentStatus::Pending) {
            $this->validationError('payment', 'Giao dịch không còn ở trạng thái chờ thanh toán');
        }

        if ($payment->amount !== $order->total_amount) {
            $this->validationError('amount', 'Số tiền thanh toán không khớp với đơn hàng');
        }

        return $this->vnpay->createPaymentUrl($payment, $order, $ipAddress);
    }

    /**
     * Return URL is read-only; the signed IPN is authoritative for persistence.
     *
     * @param  array<string, mixed>  $params
     * @return array{valid: bool, reason?: string, data?: array<string, mixed>}
     */
    public function handleVnPayReturn(array $params): array
    {
        if (! $this->vnpay->verifySignature($params)) {
            return ['valid' => false, 'reason' => 'Chữ ký VNPay không hợp lệ'];
        }

        if (! $this->vnpay->hasValidCallbackShape($params)) {
            return ['valid' => false, 'reason' => 'Dữ liệu VNPay không hợp lệ'];
        }

        $paymentNumber = (string) ($params['vnp_TxnRef'] ?? '');
        $payment = $this->payments->findByPaymentNumber($paymentNumber);

        if ($payment === null || ! $this->isVnPayOrderPayment($payment)) {
            return ['valid' => false, 'reason' => 'Không tìm thấy giao dịch thanh toán'];
        }

        if (! $this->amountMatches($payment, $params['vnp_Amount'] ?? null)) {
            return ['valid' => false, 'reason' => 'Số tiền VNPay không hợp lệ'];
        }

        $reportedStatus = match (true) {
            $payment->status === PaymentStatus::Paid => PaymentStatus::Paid->value,
            $payment->status === PaymentStatus::Refunded => PaymentStatus::Refunded->value,
            $payment->order->status === OrderStatus::Cancelled => $payment->status->value,
            $this->vnpay->isSuccessful($params) => PaymentStatus::Paid->value,
            default => PaymentStatus::Failed->value,
        };

        return [
            'valid' => true,
            'data' => [
                'payment_number' => $payment->payment_number,
                'status' => $reportedStatus,
                'order_number' => $payment->order->order_number,
                'amount' => $payment->amount,
                'response_code' => (string) ($params['vnp_ResponseCode'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{RspCode: string, Message: string}
     */
    public function handleVnPayIpn(array $params): array
    {
        if (! $this->vnpay->verifySignature($params)) {
            return $this->ipnResponse('97', 'Invalid signature');
        }

        if (! $this->vnpay->hasValidCallbackShape($params)) {
            return $this->ipnResponse('99', 'Unknown error');
        }

        try {
            return $this->payments->transaction(function () use ($params): array {
                $payment = $this->payments->lockByPaymentNumber(
                    (string) ($params['vnp_TxnRef'] ?? ''),
                );

                if ($payment === null || ! $this->isVnPayOrderPayment($payment)) {
                    return $this->ipnResponse('01', 'Order not found');
                }

                if (! $this->amountMatches($payment, $params['vnp_Amount'] ?? null)) {
                    return $this->ipnResponse('04', 'Invalid amount');
                }

                if ($payment->order->status === OrderStatus::Cancelled) {
                    return $this->ipnResponse('02', 'Order already confirmed');
                }

                $isSuccessful = $this->vnpay->isSuccessful($params);
                $targetStatus = $isSuccessful
                    ? PaymentStatus::Paid
                    : PaymentStatus::Failed;

                if (! $payment->status->canTransitionTo($targetStatus)) {
                    return $this->ipnResponse('02', 'Order already confirmed');
                }

                $providerResponse = $this->vnpay->sanitizeCallback($params);

                if ($isSuccessful) {
                    $transactionReference = trim((string) ($params['vnp_TransactionNo'] ?? ''));

                    if ($transactionReference === ''
                        || $this->payments->transactionReferenceInUse(
                            $transactionReference,
                            $payment->id,
                        )) {
                        return $this->ipnResponse('99', 'Unknown error');
                    }

                    // A valid final success may recover an earlier failed callback.
                    $this->payments->markVnPayPaid(
                        $payment,
                        $transactionReference,
                        $providerResponse,
                    );
                } else {
                    $this->payments->markVnPayFailed($payment, $providerResponse);
                }

                return $this->ipnResponse('00', 'Confirm Success');
            });
        } catch (Throwable) {
            return $this->ipnResponse('99', 'Unknown error');
        }
    }

    private function isVnPayOrderPayment(Payment $payment): bool
    {
        return $payment->method === PaymentMethod::VNPay
            && $payment->order !== null
            && $payment->order->payment_method === PaymentMethod::VNPay;
    }

    private function amountMatches(Payment $payment, mixed $vnpAmount): bool
    {
        return is_scalar($vnpAmount)
            && ctype_digit((string) $vnpAmount)
            && (int) $vnpAmount === $payment->amount * 100;
    }

    /** @return array{RspCode: string, Message: string} */
    private function ipnResponse(string $code, string $message): array
    {
        return ['RspCode' => $code, 'Message' => $message];
    }

    private function validationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function generatePaymentNumber(): string
    {
        return 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
