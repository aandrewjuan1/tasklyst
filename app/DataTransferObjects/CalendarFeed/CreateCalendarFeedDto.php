<?php

namespace App\DataTransferObjects\CalendarFeed;

final readonly class CreateCalendarFeedDto
{
    public function __construct(
        public string $feedUrl,
        public ?string $name,
        public string $source = 'brightspace'
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
        ];
    }
}
