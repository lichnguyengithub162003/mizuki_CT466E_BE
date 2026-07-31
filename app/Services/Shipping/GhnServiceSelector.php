<?php

namespace App\Services\Shipping;

use Illuminate\Validation\ValidationException;

class GhnServiceSelector
{
    /**
     * Prefer GHN light-parcel service type 2, then the lowest stable service ID.
     *
     * @param  list<array{service_id: int, short_name: string, service_type_id: int}>  $services
     * @return array{service_id: int, short_name: string, service_type_id: int}
     */
    public function select(array $services): array
    {
        if ($services === []) {
            throw ValidationException::withMessages([
                'shipping' => ['GHN hiện không có dịch vụ phù hợp cho tuyến giao hàng này!'],
            ]);
        }

        usort($services, static function (array $left, array $right): int {
            $leftPriority = $left['service_type_id'] === 2 ? 0 : 1;
            $rightPriority = $right['service_type_id'] === 2 ? 0 : 1;

            return [$leftPriority, $left['service_id']]
                <=> [$rightPriority, $right['service_id']];
        });

        return $services[0];
    }
}
