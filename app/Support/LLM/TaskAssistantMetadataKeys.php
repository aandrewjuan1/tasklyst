<?php

namespace App\Support\LLM;

final class TaskAssistantMetadataKeys
{
    public const STRUCTURED = 'structured';

    public const STREAM_PHASE = 'stream.phase';

    public const STREAM_PHASE_AT = 'stream.phase_at';

    public const STREAM_STATUS = 'stream.status';

    public const PROCESSED = 'processed';

    public const VALIDATION_ERRORS = 'validation_errors';

    public const SCHEDULE_PROPOSALS = 'schedule.proposals';
}
