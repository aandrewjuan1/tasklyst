<?php

namespace App\DataTransferObjects\CalendarFeed;

final readonly class CreateCalendarFeedDto
{
    public function __construct(
        public string $feedUrl,
        public ?string $name,
        public string $source = 'brightspace',
        public ?bool $excludeOverdueItems = null,
        public ?int $importPastMonths = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            feedUrl: (string) ($validated['feedUrl'] ?? ''),
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            source: isset($validated['source']) ? (string) $validated['source'] : 'brightspace',
            excludeOverdueItems: array_key_exists('excludeOverdueItems', $validated)
                ? (bool) $validated['excludeOverdueItems']
                : null,
            importPastMonths: array_key_exists('importPastMonths', $validated)
                ? (int) $validated['importPastMonths']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'feed_url' => $this->feedUrl,
            'name' => $this->name,
            'source' => $this->source,
            'exclude_overdue_items' => $this->excludeOverdueItems,
            'import_past_months' => $this->importPastMonths,
        ];
    }
}
