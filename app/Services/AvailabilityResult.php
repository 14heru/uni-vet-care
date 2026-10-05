<?php

namespace App\Services;

/**
 * The date-level result returned by the doctor availability engine.
 */
final readonly class AvailabilityResult
{
    /**
     * @param  list<array{
     *     start_at: string,
     *     end_at: string,
     *     status: string,
     *     source: string,
     *     reason: string|null
     * }>  $slots
     */
    public function __construct(
        public string $date,
        public string $timezone,
        public string $status,
        public ?string $reason,
        public array $slots,
    ) {}

    /**
     * Convert the result into a safe, serializable payload.
     *
     * @return array{
     *     date: string,
     *     timezone: string,
     *     status: string,
     *     reason: string|null,
     *     slots: list<array{
     *         start_at: string,
     *         end_at: string,
     *         status: string,
     *         source: string,
     *         reason: string|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'reason' => $this->reason,
            'slots' => $this->slots,
        ];
    }
}
