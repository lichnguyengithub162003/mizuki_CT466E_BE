<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'order_count' => (int) ($this->orders_count ?? 0),
            'total_spent' => (int) ($this->total_spent ?? 0),
            'appointment_count' => (int) ($this->appointments_count ?? 0),
            'wallet_balance' => $this->whenLoaded('wallet', fn (): int => (int) ($this->wallet?->balance ?? 0)),
            'latest_orders' => $this->whenLoaded('orders', fn () => $this->orders->map(fn ($order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'total_amount' => $order->total_amount,
                'payment_status' => $order->payment?->status->value,
                'payment_status_label' => $order->payment?->status->label(),
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all()),
            'latest_appointments' => $this->whenLoaded('appointments', fn () => $this->appointments->map(fn ($appointment): array => [
                'id' => $appointment->id,
                'appointment_number' => $appointment->appointment_number,
                'branch' => ['id' => $appointment->branch->id, 'name' => $appointment->branch->name],
                'service_name' => $appointment->service_name,
                'status' => $appointment->status->value,
                'status_label' => $appointment->status->label(),
                'starts_at' => $appointment->starts_at?->toISOString(),
            ])->values()->all()),
            'skin_profile' => $this->whenLoaded('skinProfile', fn () => $this->skinProfile === null ? null : [
                'id' => $this->skinProfile->id,
                'skin_type' => $this->skinProfile->skin_type,
                'concerns' => $this->skinProfile->concerns ?? [],
                'sensitivity_level' => $this->skinProfile->sensitivity_level,
                'url' => "/api/v1/admin/customers/{$this->id}/skin-profile",
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
