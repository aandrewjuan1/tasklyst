# LLM Task Assistant — Cursor Agent Implementation Plan

> **Agent Context**
> Stack: **Laravel 12 · Livewire 4 · PrismPHP · Ollama (`hermes3:3b`)**
> Goal: Build a queued, policy-authorized, schema-validated LLM assistant for task prioritization and scheduling.
> This file is structured as ordered implementation steps. **Execute phases in sequence. Do not skip ahead.**

---

## ⚠️ Hard Rules — Read Before Every Step

> [!IMPORTANT]
> - **Never** call Ollama or PrismPHP synchronously inside an HTTP request. Always dispatch `ProcessLlmRequestJob`.
> - **Never** write to the database from inside `LlmChatService` or any Action/Service without going through an existing domain Action (`CreateTaskAction`, `UpdateTaskAction`, `CreateScheduleAction`).
> - **Never** use a raw string where an Enum exists (`LlmIntent`, `ChatMessageRole`, `ToolCallStatus`).
> - **Never** hardcode model name, temperature, token limits, or queue names. Always read from `config('llm.*')`.
> - **Always** call `Gate::authorize('executeLlmTool', $task)` inside `ToolExecutorService` before any task write.
> - **Always** check `LlmToolCall::findByRequestId($clientRequestId)` before executing a tool call (idempotency).
> - **Never** expose `llm_raw` to the UI or return it in any HTTP response.
> - **Never** call real Ollama in tests. Always mock `CallLlmAction`.

> [!WARNING]
> The model can propose any task ID it sees in context. The Policy gate is the only thing preventing cross-user writes. Do not remove or skip it.

---

## Architecture Flow

```
HTTP POST /chat/threads/{thread}/messages
  │
  ├─ StoreChatMessageRequest     [validates + authorizes via ChatThreadPolicy::sendMessage]
  │
  ├─ ChatThreadController        [thin — persists user ChatMessage, dispatches job, returns 202]
  │
  └─ ProcessLlmRequestJob        [QUEUED on 'llm' queue]
       │
       └─ LlmChatService::handle()
            ├─ BuildContextAction        → ContextDto
            ├─ PromptManagerService      → LlmRequestDto
            ├─ CallLlmAction             → LlmRawResponseDto
            ├─ PostProcessorService      → LlmResponseDto  (throws on schema/auth violations)
            ├─ ToolExecutorService       → ToolResultDto   (Gate::authorize before every write)
            └─ persist ChatMessage(role=assistant) + broadcast to UI
```

---

## Complete File Map

```
app/
├── Actions/
│   ├── Llm/
│   │   ├── BuildContextAction.php
│   │   ├── CallLlmAction.php
│   │   └── RetryRepairAction.php
│   └── Tool/
│       ├── CreateTaskAction.php
│       ├── UpdateTaskAction.php
│       └── CreateScheduleAction.php
├── DataTransferObjects/
│   ├── Llm/
│   │   ├── ContextDto.php
│   │   ├── ConversationTurn.php
│   │   ├── LlmRequestDto.php
│   │   ├── LlmRawResponseDto.php
│   │   ├── LlmResponseDto.php
│   │   ├── ToolCallDto.php
│   │   ├── ToolResultDto.php
│   │   ├── TaskContextItem.php
│   │   └── EventContextItem.php
│   └── Ui/
│       └── RecommendationDisplayDto.php
├── Enums/
│   ├── LlmIntent.php
│   ├── ChatMessageRole.php
│   └── ToolCallStatus.php
├── Exceptions/
│   └── Llm/
│       ├── LlmValidationException.php
│       ├── LlmSchemaVersionException.php
│       ├── ToolExecutionException.php
│       └── UnknownEntityException.php
├── Http/
│   └── Requests/
│       └── Chat/
│           ├── StoreChatMessageRequest.php
│           └── CreateChatThreadRequest.php
├── Jobs/
│   └── ProcessLlmRequestJob.php
├── Models/
│   ├── ChatThread.php
│   ├── ChatMessage.php
│   └── LlmToolCall.php
│   # Task.php / Schedule.php — ADD scopes to existing models
├── Policies/
│   ├── ChatThreadPolicy.php
│   ├── LlmToolCallPolicy.php
│   └── TaskPolicy.php              # ADD executeLlmTool + schedule abilities
├── Providers/
│   └── LlmServiceProvider.php
└── Services/
    └── Llm/
        ├── LlmChatService.php
        ├── ContextBuilderService.php
        ├── PromptManagerService.php
        ├── PostProcessorService.php
        └── ToolExecutorService.php

config/
└── llm.php

database/migrations/
├── xxxx_create_chat_threads_table.php
├── xxxx_create_chat_messages_table.php
└── xxxx_create_llm_tool_calls_table.php

tests/
├── Unit/
│   ├── Enums/LlmIntentTest.php
│   ├── Services/PostProcessorServiceTest.php
│   ├── Services/ToolExecutorServiceTest.php
│   └── Actions/BuildContextActionTest.php
└── Feature/
    ├── Chat/ScheduleFlowTest.php
    ├── Chat/PolicyEnforcementTest.php
    ├── Chat/IdempotencyTest.php
    └── Jobs/ProcessLlmRequestJobTest.php
```

---

## Phase 1 — Config, Enums, Exceptions

> [!NOTE]
> Phase 1 has zero dependencies. Create all files in this phase before moving to Phase 2.

---

### STEP 1.1 — Create `config/llm.php`

```php
<?php
// config/llm.php

return [

    // ── Model ─────────────────────────────────────────────────────────
    'model'          => env('LLM_MODEL', 'hermes3:3b'),
    'temperature'    => (float) env('LLM_TEMPERATURE', 0.2),
    'top_p'          => (float) env('LLM_TOP_P', 0.9),
    'max_tokens'     => (int)   env('LLM_MAX_TOKENS', 1024),
    'max_tokens_cap' => (int)   env('LLM_MAX_TOKENS_CAP', 2048),

    // ── Schema versioning ─────────────────────────────────────────────
    // Bump this when prompt/DTO contract changes. PostProcessorService rejects mismatches.
    'schema_version' => env('LLM_SCHEMA_VERSION', '2026-03-01.v1'),

    // ── Context window ────────────────────────────────────────────────
    'context' => [
        'max_tasks'              => (int) env('LLM_CTX_MAX_TASKS', 8),
        'max_events_hours'       => (int) env('LLM_CTX_MAX_EVENTS_HOURS', 24),
        'recent_messages'        => (int) env('LLM_CTX_RECENT_MESSAGES', 6),
        'summary_task_threshold' => (int) env('LLM_CTX_SUMMARY_THRESHOLD', 50),
        'token_budget'           => (int) env('LLM_CTX_TOKEN_BUDGET', 2000),
    ],

    // ── Repair ────────────────────────────────────────────────────────
    // Single repair attempt only — never loop retries.
    'repair' => [
        'max_attempts' => 1,
    ],

    // ── Confidence thresholds ─────────────────────────────────────────
    'confidence' => [
        'low_threshold'  => (float) env('LLM_CONFIDENCE_LOW', 0.4),
        'high_threshold' => (float) env('LLM_CONFIDENCE_HIGH', 0.75),
    ],

    // ── Queue ─────────────────────────────────────────────────────────
    'queue' => [
        'connection' => env('LLM_QUEUE_CONNECTION', 'redis'),
        'name'       => env('LLM_QUEUE_NAME', 'llm'),
        'timeout'    => (int) env('LLM_QUEUE_TIMEOUT', 90),
        'tries'      => (int) env('LLM_QUEUE_TRIES', 2),
    ],

    // ── Rate limiting (per user) ──────────────────────────────────────
    'rate_limit' => [
        'max_requests' => (int) env('LLM_RATE_MAX', 30),
        'per_minutes'  => (int) env('LLM_RATE_MINUTES', 10),
    ],

    // ── Logging ───────────────────────────────────────────────────────
    'log' => [
        'channel'            => env('LLM_LOG_CHANNEL', 'stack'),
        'raw_retention_days' => (int) env('LLM_RAW_RETENTION_DAYS', 90),
    ],

    // ── Whitelisted tool names ─────────────────────────────────────────
    // Only these strings are accepted in tool_call.tool from the model.
    'allowed_tools' => [
        'create_task',
        'update_task',
        'create_schedule',
    ],

    // ── Timezone ──────────────────────────────────────────────────────
    'timezone' => env('LLM_TIMEZONE', 'Asia/Manila'),
];
```

Then add these lines to `.env` and `.env.example`:

```dotenv
# LLM Assistant
LLM_MODEL=hermes3:3b
LLM_TEMPERATURE=0.2
LLM_TOP_P=0.9
LLM_MAX_TOKENS=1024
LLM_MAX_TOKENS_CAP=2048
LLM_SCHEMA_VERSION=2026-03-01.v1
LLM_CTX_MAX_TASKS=8
LLM_CTX_MAX_EVENTS_HOURS=24
LLM_CTX_RECENT_MESSAGES=6
LLM_CTX_SUMMARY_THRESHOLD=50
LLM_CTX_TOKEN_BUDGET=2000
LLM_CONFIDENCE_LOW=0.4
LLM_CONFIDENCE_HIGH=0.75
LLM_QUEUE_CONNECTION=redis
LLM_QUEUE_NAME=llm
LLM_QUEUE_TIMEOUT=90
LLM_QUEUE_TRIES=2
LLM_RATE_MAX=30
LLM_RATE_MINUTES=10
LLM_LOG_CHANNEL=stack
LLM_RAW_RETENTION_DAYS=90
LLM_TIMEZONE=Asia/Manila
```

**✅ Verify:** `php artisan config:clear && php artisan tinker --execute="dump(config('llm.model'))"` prints `hermes3:3b`.

---

### STEP 1.2 — Create `app/Enums/LlmIntent.php`

```php
<?php
// app/Enums/LlmIntent.php

namespace App\Enums;

enum LlmIntent: string
{
    case Schedule   = 'schedule';
    case Create     = 'create';
    case Update     = 'update';
    case Prioritize = 'prioritize';
    case List       = 'list';
    case General    = 'general';
    case Clarify    = 'clarify';
    case Error      = 'error';

    /** Intents that are allowed to emit a tool_call. Used in PostProcessorService. */
    public function canTriggerToolCall(): bool
    {
        return in_array($this, [self::Schedule, self::Create, self::Update], strict: true);
    }

    /** Intents that never write to the database. */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::Prioritize, self::List, self::General], strict: true);
    }

    /** All valid string values — used to validate model output. */
    public static function allowedValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

**✅ Verify:** `LlmIntent::from('schedule')` resolves without error. `LlmIntent::Error->canTriggerToolCall()` returns `false`.

---

### STEP 1.3 — Create `app/Enums/ChatMessageRole.php`

```php
<?php
// app/Enums/ChatMessageRole.php

namespace App\Enums;

enum ChatMessageRole: string
{
    case User      = 'user';
    case Assistant = 'assistant';
    case System    = 'system';
    case Tool      = 'tool';
    case Meta      = 'meta';

    public function isAssistantAuthored(): bool
    {
        return in_array($this, [self::Assistant, self::Tool, self::Meta], strict: true);
    }
}
```

---

### STEP 1.4 — Create `app/Enums/ToolCallStatus.php`

```php
<?php
// app/Enums/ToolCallStatus.php

namespace App\Enums;

enum ToolCallStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed  = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
```

**✅ Verify (all enums):** `php artisan tinker --execute="dump(App\Enums\LlmIntent::allowedValues())"` prints all 8 intent values.

---

### STEP 1.5 — Create Exception Classes

Create all four files:

```php
<?php
// app/Exceptions/Llm/LlmValidationException.php

namespace App\Exceptions\Llm;

use RuntimeException;

class LlmValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,       // 'PARSE_ERROR' | 'VALIDATION_ERROR' | 'SCHEMA_MISMATCH'
        public readonly ?string $rawResponse = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

```php
<?php
// app/Exceptions/Llm/LlmSchemaVersionException.php

namespace App\Exceptions\Llm;

use RuntimeException;

class LlmSchemaVersionException extends RuntimeException
{
    public function __construct(
        public readonly string $received,
        public readonly string $expected,
    ) {
        parent::__construct(
            "Schema version mismatch: received [{$received}], expected [{$expected}]."
        );
    }
}
```

```php
<?php
// app/Exceptions/Llm/ToolExecutionException.php

namespace App\Exceptions\Llm;

use RuntimeException;

class ToolExecutionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $tool,
        public readonly array $args = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

```php
<?php
// app/Exceptions/Llm/UnknownEntityException.php

namespace App\Exceptions\Llm;

use RuntimeException;

class UnknownEntityException extends RuntimeException
{
    public function __construct(
        public readonly string $entityType,      // 'task' | 'event' | 'schedule'
        public readonly string|int $entityId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Unknown {$entityType} with id [{$entityId}] referenced by model — possible injection.",
            0,
            $previous,
        );
    }
}
```

**✅ Verify:** All four classes resolve via `new App\Exceptions\Llm\LlmValidationException('test', 'PARSE_ERROR')` without error.

---

## Phase 2 — Database Migrations & Eloquent Models

> [!NOTE]
> **Depends on:** Phase 1 complete (enums must exist before models use them as casts).

---

### STEP 2.1 — Create Migration: `chat_threads`

```php
<?php
// database/migrations/xxxx_create_chat_threads_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('model')->default('hermes3:3b');
            $table->text('system_prompt')->nullable();
            $table->string('schema_version')->default('2026-03-01.v1');
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};
```

---

### STEP 2.2 — Create Migration: `chat_messages`

```php
<?php
// database/migrations/xxxx_create_chat_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system', 'tool', 'meta']);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content_text');
            $table->json('content_json')->nullable();
            $table->text('llm_raw')->nullable();        // restricted — never expose to UI
            $table->json('meta')->nullable();            // confidence, tokens, trace_id, latency
            $table->string('client_request_id')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
```

---

### STEP 2.3 — Create Migration: `llm_tool_calls`

> [!IMPORTANT]
> `client_request_id` MUST have a unique constraint. This is the idempotency key. Do not remove it.

```php
<?php
// database/migrations/xxxx_create_llm_tool_calls_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id')->unique();  // ← CRITICAL: idempotency key
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('tool');
            $table->string('args_hash');
            $table->json('tool_result_payload')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_tool_calls');
    }
};
```

**✅ Verify (all migrations):** `php artisan migrate` runs without errors. `php artisan migrate:status` shows all three tables.

---

### STEP 2.4 — Add LLM scopes to existing `app/Models/Task.php`

> [!NOTE]
> Do NOT replace the existing Task model. ADD the following scopes and casts to it.

```php
// ADD to app/Models/Task.php — merge with existing casts and methods

// In $casts array, ensure these exist:
protected $casts = [
    // ... your existing casts ...
    'due_date'         => 'date',
    'is_completed'     => 'boolean',
    'estimate_minutes' => 'integer',
    'priority'         => 'integer',
];

// ADD these scopes:

/** Used by BuildContextAction — active incomplete tasks ordered by priority then due date. */
public function scopeActiveForUser(Builder $query, int $userId): Builder
{
    return $query
        ->where('user_id', $userId)
        ->where('is_completed', false)
        ->orderByDesc('priority')
        ->orderBy('due_date');
}

/** Minimal columns for summary mode (> 50 tasks). */
public function scopeSummaryColumns(Builder $query): Builder
{
    return $query->select(['id', 'title', 'due_date', 'priority', 'estimate_minutes']);
}

/** Eager-load specific IDs mentioned in user message. */
public function scopeForIds(Builder $query, array $ids): Builder
{
    return $query->whereIn('id', $ids);
}
```

---

### STEP 2.5 — Add LLM scopes to existing `app/Models/Schedule.php`

> [!NOTE]
> Do NOT replace the existing Schedule model. ADD the following.

```php
// ADD to app/Models/Schedule.php

// In $casts array, ensure these exist:
protected $casts = [
    // ... your existing casts ...
    'start_datetime'   => 'immutable_datetime',
    'end_datetime'     => 'immutable_datetime',
    'duration_minutes' => 'integer',
];

// ADD these scopes:

/** Used by BuildContextAction — upcoming schedules within N hours. */
public function scopeUpcomingForUser(Builder $query, int $userId, int $hours = 24): Builder
{
    return $query
        ->where('user_id', $userId)
        ->where('start_datetime', '>=', now())
        ->where('start_datetime', '<=', now()->addHours($hours))
        ->orderBy('start_datetime');
}

/**
 * Used by PostProcessorService to detect scheduling conflicts before tool execution.
 * Checks if any existing schedule overlaps the proposed [start, start+duration] window.
 */
public function scopeConflictingWith(
    Builder $query,
    int $userId,
    \DateTimeImmutable $start,
    int $durationMinutes,
): Builder {
    $end = $start->modify("+{$durationMinutes} minutes");

    return $query
        ->where('user_id', $userId)
        ->where('start_datetime', '<', $end)
        ->where('end_datetime', '>', $start);
}
```

---

### STEP 2.6 — Create `app/Models/ChatThread.php`

```php
<?php
// app/Models/ChatThread.php

namespace App\Models;

use App\Enums\ChatMessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatThread extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'title', 'model', 'system_prompt',
        'schema_version', 'metadata',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'archived_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'thread_id')->latestOfMany();
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(LlmToolCall::class, 'thread_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Returns the last N user/assistant turns for ContextDto::recentMessages.
     * Ordered chronologically (oldest first) for prompt injection.
     */
    public function recentTurns(int $limit = 6): \Illuminate\Database\Eloquent\Collection
    {
        return $this->messages()
            ->whereIn('role', [ChatMessageRole::User->value, ChatMessageRole::Assistant->value])
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
```

---

### STEP 2.7 — Create `app/Models/ChatMessage.php`

```php
<?php
// app/Models/ChatMessage.php

namespace App\Models;

use App\DataTransferObjects\Llm\ConversationTurn;
use App\Enums\ChatMessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'thread_id', 'role', 'author_id', 'content_text',
        'content_json', 'llm_raw', 'meta', 'client_request_id',
    ];

    protected $casts = [
        'role'         => ChatMessageRole::class,
        'content_json' => 'array',
        'meta'         => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isAssistant(): bool
    {
        return $this->role === ChatMessageRole::Assistant;
    }

    /** Maps to ConversationTurn DTO for ContextDto injection. */
    public function toConversationTurn(): ConversationTurn
    {
        return new ConversationTurn(
            role:       $this->role->value,
            text:       $this->content_text,
            structured: $this->content_json,
            createdAt:  \DateTimeImmutable::createFromMutable($this->created_at->toDateTime()),
        );
    }
}
```

---

### STEP 2.8 — Create `app/Models/LlmToolCall.php`

```php
<?php
// app/Models/LlmToolCall.php

namespace App\Models;

use App\Enums\ToolCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmToolCall extends Model
{
    protected $fillable = [
        'client_request_id', 'user_id', 'thread_id',
        'tool', 'args_hash', 'tool_result_payload', 'status',
    ];

    protected $casts = [
        'tool_result_payload' => 'array',
        'status'              => ToolCallStatus::class,
    ];

    // ── Relationships ──────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    // ── Idempotency ────────────────────────────────────────────────────

    /**
     * Returns an existing tool call if this client_request_id was already processed.
     * ToolExecutorService calls this BEFORE executing any tool.
     * Returns null → safe to proceed. Returns model → return cached result, skip execution.
     */
    public static function findByRequestId(string $clientRequestId): ?self
    {
        return self::where('client_request_id', $clientRequestId)->first();
    }
}
```

**✅ Verify (all models):** `php artisan tinker --execute="dump(App\Models\ChatThread::first())"` returns null without error (table exists, just empty).

---

## Phase 3 — DTOs

> [!NOTE]
> **Depends on:** Phase 1 (enums). DTOs are pure PHP — no DB dependency.

---

### STEP 3.1 — Create all DTO files

```php
<?php
// app/DataTransferObjects/Llm/ConversationTurn.php

namespace App\DataTransferObjects\Llm;

final class ConversationTurn
{
    public function __construct(
        public readonly string             $role,       // ChatMessageRole->value
        public readonly string             $text,
        public readonly ?array             $structured, // parsed content_json or null
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/TaskContextItem.php

namespace App\DataTransferObjects\Llm;

final class TaskContextItem
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $title,
        public readonly ?string $dueDate,        // YYYY-MM-DD or null
        public readonly int     $priority,
        public readonly ?int    $estimateMinutes,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/EventContextItem.php

namespace App\DataTransferObjects\Llm;

final class EventContextItem
{
    public function __construct(
        public readonly int    $id,
        public readonly string $title,
        public readonly string $startDatetime,   // ISO 8601 with offset
        public readonly int    $durationMinutes,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/ContextDto.php

namespace App\DataTransferObjects\Llm;

final class ContextDto
{
    public function __construct(
        public readonly \DateTimeImmutable $now,
        /** @var list<TaskContextItem> */
        public readonly array              $tasks,
        /** @var list<EventContextItem> */
        public readonly array              $events,
        /** @var list<ConversationTurn> */
        public readonly array              $recentMessages,
        public readonly array              $userPreferences = [],
        public readonly ?string            $fingerprint     = null,  // md5 of task IDs
        public readonly bool               $isSummaryMode   = false,
    ) {}

    /**
     * All valid task IDs present in this context.
     * PostProcessorService uses this to reject model-invented IDs.
     */
    public function taskIds(): array
    {
        return array_map(fn (TaskContextItem $t) => $t->id, $this->tasks);
    }
}
```

```php
<?php
// app/DataTransferObjects/Llm/LlmRequestDto.php

namespace App\DataTransferObjects\Llm;

final class LlmRequestDto
{
    public function __construct(
        public readonly string  $systemPrompt,
        public readonly string  $userPayloadJson,
        public readonly float   $temperature,
        public readonly int     $maxTokens,
        public readonly array   $options  = [],
        public readonly ?string $traceId  = null,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/LlmRawResponseDto.php

namespace App\DataTransferObjects\Llm;

final class LlmRawResponseDto
{
    public function __construct(
        public readonly string  $rawText,
        public readonly float   $latencyMs,
        public readonly ?int    $tokensUsed = null,
        public readonly ?string $modelName  = null,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/ToolCallDto.php

namespace App\DataTransferObjects\Llm;

final class ToolCallDto
{
    public function __construct(
        public readonly string $tool,             // must be in config('llm.allowed_tools')
        public readonly array  $args,
        public readonly string $clientRequestId,  // UUID
        public readonly bool   $confirmationRequired = false,
    ) {}
}
```

```php
<?php
// app/DataTransferObjects/Llm/ToolResultDto.php

namespace App\DataTransferObjects\Llm;

final class ToolResultDto
{
    public function __construct(
        public readonly string  $tool,
        public readonly bool    $success,
        public readonly array   $payload,         // domain object data — authoritative IDs/timestamps
        public readonly ?string $errorMessage = null,
    ) {}

    public function toArray(): array
    {
        return [
            'tool'         => $this->tool,
            'success'      => $this->success,
            'payload'      => $this->payload,
            'errorMessage' => $this->errorMessage,
        ];
    }

    public static function fromStoredPayload(array $payload): self
    {
        return new self(
            tool:    $payload['tool'],
            success: $payload['success'],
            payload: $payload['payload'],
        );
    }
}
```

```php
<?php
// app/DataTransferObjects/Llm/LlmResponseDto.php

namespace App\DataTransferObjects\Llm;

use App\Enums\LlmIntent;

final class LlmResponseDto
{
    public function __construct(
        public readonly LlmIntent   $intent,
        public readonly array       $data,
        public readonly ?ToolCallDto $toolCall,
        public readonly bool        $isError,
        public readonly string      $message,
        public readonly float       $confidence,
        public readonly string      $schemaVersion,
        public readonly ?string     $raw = null,
    ) {}

    public function hasToolCall(): bool
    {
        return $this->toolCall !== null && ! $this->isError;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < config('llm.confidence.low_threshold');
    }

    /** Factory for error states — use in PostProcessorService catch blocks. */
    public static function error(string $userMessage, ?string $raw = null): self
    {
        return new self(
            intent:        LlmIntent::Error,
            data:          [],
            toolCall:      null,
            isError:       true,
            message:       $userMessage,
            confidence:    0.0,
            schemaVersion: config('llm.schema_version'),
            raw:           $raw,
        );
    }
}
```

```php
<?php
// app/DataTransferObjects/Ui/RecommendationDisplayDto.php

namespace App\DataTransferObjects\Ui;

final class RecommendationDisplayDto
{
    public function __construct(
        public readonly string  $primaryMessage,
        public readonly array   $cards    = [],   // task/event cards for UI rendering
        public readonly array   $actions  = [],   // CTA button definitions
        public readonly bool    $isError  = false,
        public readonly ?string $traceId  = null,
    ) {}
}
```

**✅ Verify:** `php artisan tinker --execute="new App\DataTransferObjects\Llm\ContextDto(new DateTimeImmutable, [], [], [])"` constructs without error.

---

## Phase 4 — Policies & Authorization

> [!IMPORTANT]
> Policies are the security boundary between LLM proposals and DB writes. Create and register all policies before any service or tool action.

---

### STEP 4.1 — Create `app/Policies/ChatThreadPolicy.php`

```php
<?php
// app/Policies/ChatThreadPolicy.php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;

class ChatThreadPolicy
{
    public function view(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    public function delete(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id;
    }

    /**
     * Called by StoreChatMessageRequest::authorize().
     * Fails if thread is soft-deleted or owned by another user.
     */
    public function sendMessage(User $user, ChatThread $thread): bool
    {
        return $thread->user_id === $user->id && $thread->deleted_at === null;
    }
}
```

---

### STEP 4.2 — Add LLM abilities to `app/Policies/TaskPolicy.php`

> [!NOTE]
> Add these two methods to your EXISTING TaskPolicy. Do not replace existing methods.

```php
// ADD to existing app/Policies/TaskPolicy.php

/**
 * Called by ToolExecutorService via Gate::authorize('executeLlmTool', $task)
 * BEFORE any update_task or create_schedule tool execution.
 *
 * This is the critical cross-user protection:
 * even if the model proposes a task ID belonging to another user, this gate blocks it.
 */
public function executeLlmTool(User $user, Task $task): bool
{
    return $task->user_id === $user->id;
}

/**
 * Authorizes scheduling. Also checks task is not already completed.
 * Called by ToolExecutorService before create_schedule tool execution.
 */
public function schedule(User $user, Task $task): bool
{
    return $task->user_id === $user->id && ! $task->is_completed;
}
```

---

### STEP 4.3 — Create `app/Policies/LlmToolCallPolicy.php`

```php
<?php
// app/Policies/LlmToolCallPolicy.php

namespace App\Policies;

use App\Models\LlmToolCall;
use App\Models\User;

class LlmToolCallPolicy
{
    public function view(User $user, LlmToolCall $toolCall): bool
    {
        return $user->id === $toolCall->user_id || $user->isAdmin();
    }
}
```

---

### STEP 4.4 — Register policies in `app/Providers/AuthServiceProvider.php`

```php
// ADD to the $policies array in app/Providers/AuthServiceProvider.php

use App\Models\ChatThread;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Policies\ChatThreadPolicy;
use App\Policies\LlmToolCallPolicy;
use App\Policies\TaskPolicy;

protected $policies = [
    // ... your existing policies ...
    ChatThread::class   => ChatThreadPolicy::class,
    Task::class         => TaskPolicy::class,
    LlmToolCall::class  => LlmToolCallPolicy::class,
];
```

**✅ Verify:** `php artisan policy:list` shows `ChatThread → ChatThreadPolicy` and `Task → TaskPolicy`.

---

## Phase 5 — Service Provider, Form Requests, Routes

> [!NOTE]
> **Depends on:** Phases 1–4 complete.

---

### STEP 5.1 — Create `app/Providers/LlmServiceProvider.php`

```php
<?php
// app/Providers/LlmServiceProvider.php

namespace App\Providers;

use App\Actions\Llm\BuildContextAction;
use App\Actions\Llm\CallLlmAction;
use App\Actions\Llm\RetryRepairAction;
use App\Services\Llm\LlmChatService;
use App\Services\Llm\PostProcessorService;
use App\Services\Llm\PromptManagerService;
use App\Services\Llm\ToolExecutorService;
use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton — stateless, expensive to construct, safe to share.
        $this->app->singleton(PromptManagerService::class, fn ($app) =>
            new PromptManagerService(
                schemaVersion: config('llm.schema_version'),
                timezone:      config('llm.timezone'),
                allowedTools:  config('llm.allowed_tools'),
            )
        );

        // Singleton — holds compiled validation schemas.
        $this->app->singleton(PostProcessorService::class, fn ($app) =>
            new PostProcessorService(
                schemaVersion: config('llm.schema_version'),
                confidenceLow: config('llm.confidence.low_threshold'),
                repairAction:  $app->make(RetryRepairAction::class),
            )
        );

        // Bound per-resolution — fresh instance per job.
        $this->app->bind(LlmChatService::class, fn ($app) =>
            new LlmChatService(
                contextBuilder: $app->make(BuildContextAction::class),
                promptManager:  $app->make(PromptManagerService::class),
                callLlm:        $app->make(CallLlmAction::class),
                postProcessor:  $app->make(PostProcessorService::class),
                toolExecutor:   $app->make(ToolExecutorService::class),
            )
        );
    }
}
```

Then register in `bootstrap/providers.php`:

```php
// bootstrap/providers.php — ADD this entry:
App\Providers\LlmServiceProvider::class,
```

---

### STEP 5.2 — Create `app/Http/Requests/Chat/CreateChatThreadRequest.php`

```php
<?php
// app/Http/Requests/Chat/CreateChatThreadRequest.php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CreateChatThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ChatThread::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

---

### STEP 5.3 — Create `app/Http/Requests/Chat/StoreChatMessageRequest.php`

```php
<?php
// app/Http/Requests/Chat/StoreChatMessageRequest.php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        // Rejects if thread is null, soft-deleted, or belongs to another user.
        return $thread && $this->user()->can('sendMessage', $thread);
    }

    public function rules(): array
    {
        return [
            'message'           => ['required', 'string', 'min:1', 'max:2000'],
            'client_request_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required'           => 'A message is required.',
            'message.max'                => 'Message must not exceed 2000 characters.',
            'client_request_id.required' => 'A unique request ID is required for idempotency.',
            'client_request_id.uuid'     => 'client_request_id must be a valid UUID v4.',
        ];
    }
}
```

---

### STEP 5.4 — Add routes to `routes/web.php` (or `api.php`)

```php
// ADD to routes/web.php or routes/api.php

use App\Http\Requests\Chat\CreateChatThreadRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Controllers\ChatThreadController;

Route::middleware([
    'auth',
    'throttle:' . config('llm.rate_limit.max_requests') . ',' . config('llm.rate_limit.per_minutes'),
])->prefix('chat')->group(function () {
    Route::post('/threads', [ChatThreadController::class, 'store']);
    Route::post('/threads/{thread}/messages', [ChatThreadController::class, 'sendMessage']);
    Route::get('/threads/{thread}/messages', [ChatThreadController::class, 'messages']);
    Route::patch('/threads/{thread}', [ChatThreadController::class, 'update']);
    Route::delete('/threads/{thread}', [ChatThreadController::class, 'destroy']);
});
```

**✅ Verify:** `php artisan route:list | grep chat` shows all 5 routes with `auth` and `throttle` middleware.

---

## Phase 6 — Core LLM Services & Actions

> [!NOTE]
> **Depends on:** All previous phases. Create files in the order listed — earlier files are dependencies of later ones.

---

### STEP 6.1 — Create `app/Actions/Llm/BuildContextAction.php`

```php
<?php
// app/Actions/Llm/BuildContextAction.php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\EventContextItem;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\Models\ChatThread;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;

class BuildContextAction
{
    public function __invoke(User $user, string $threadId, string $message): ContextDto
    {
        $now      = new \DateTimeImmutable('now', new \DateTimeZone(config('llm.timezone')));
        $maxTasks = config('llm.context.max_tasks');
        $maxHours = config('llm.context.max_events_hours');
        $limit    = config('llm.context.recent_messages');
        $summaryThreshold = config('llm.context.summary_task_threshold');

        // Decide summary mode based on total active task count
        $totalTasks  = Task::where('user_id', $user->id)->where('is_completed', false)->count();
        $summaryMode = $totalTasks > $summaryThreshold;

        // Fetch tasks
        $taskQuery = Task::activeForUser($user->id)->limit($maxTasks);
        if ($summaryMode) {
            $taskQuery->summaryColumns();
        }
        $tasks = $taskQuery->get()->map(fn ($t) => new TaskContextItem(
            id:              $t->id,
            title:           $t->title,
            dueDate:         $t->due_date?->format('Y-m-d'),
            priority:        $t->priority ?? 0,
            estimateMinutes: $t->estimate_minutes,
        ))->all();

        // Fetch upcoming schedules / events
        $events = Schedule::upcomingForUser($user->id, $maxHours)
            ->get()
            ->map(fn ($s) => new EventContextItem(
                id:              $s->id,
                title:           $s->task?->title ?? 'Scheduled block',
                startDatetime:   $s->start_datetime->format(\DateTimeInterface::ATOM),
                durationMinutes: $s->duration_minutes,
            ))->all();

        // Fetch recent conversation turns
        $thread  = ChatThread::findOrFail($threadId);
        $recentMessages = $thread->recentTurns($limit)
            ->map(fn ($m) => $m->toConversationTurn())
            ->all();

        // Deterministic fingerprint of task IDs for PostProcessorService validation
        $fingerprint = md5(implode(',', array_column($tasks, 'id')));

        return new ContextDto(
            now:            $now,
            tasks:          $tasks,
            events:         $events,
            recentMessages: $recentMessages,
            fingerprint:    $fingerprint,
            isSummaryMode:  $summaryMode,
        );
    }
}
```

---

### STEP 6.2 — Create `app/Actions/Llm/CallLlmAction.php`

```php
<?php
// app/Actions/Llm/CallLlmAction.php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\LlmRequestDto;
use Illuminate\Support\Facades\Log;
// use EchoLabs\Prism\Prism;   ← uncomment when PrismPHP is installed

class CallLlmAction
{
    public function __invoke(LlmRequestDto $request): LlmRawResponseDto
    {
        $start = microtime(true);

        // ── PrismPHP call (uncomment and adapt to your PrismPHP version) ──────
        // $response = Prism::text()
        //     ->using('ollama', config('llm.model'))
        //     ->withSystemPrompt($request->systemPrompt)
        //     ->withPrompt($request->userPayloadJson)
        //     ->usingTemperature($request->temperature)
        //     ->withMaxTokens($request->maxTokens)
        //     ->generate();
        // $rawText = $response->text;
        // $tokens  = $response->usage?->totalTokens;
        // ─────────────────────────────────────────────────────────────────────

        // ── Stub for development before PrismPHP wiring ──────────────────────
        $rawText = '{"schema_version":"' . config('llm.schema_version') . '","intent":"general","data":{},"tool_call":null,"message":"PrismPHP not wired yet.","meta":{"confidence":1.0}}';
        $tokens  = null;
        // ─────────────────────────────────────────────────────────────────────

        $latency = (microtime(true) - $start) * 1000;

        Log::channel(config('llm.log.channel'))->info('llm.call', [
            'trace_id'   => $request->traceId,
            'model'      => config('llm.model'),
            'latency_ms' => $latency,
            'tokens'     => $tokens,
            // Do NOT log full rawText here — use restricted log channel for that
        ]);

        return new LlmRawResponseDto(
            rawText:    $rawText,
            latencyMs:  $latency,
            tokensUsed: $tokens,
            modelName:  config('llm.model'),
        );
    }
}
```

---

### STEP 6.3 — Create `app/Actions/Llm/RetryRepairAction.php`

```php
<?php
// app/Actions/Llm/RetryRepairAction.php

namespace App\Actions\Llm;

use Illuminate\Support\Facades\Log;
// use EchoLabs\Prism\Prism;   ← uncomment when PrismPHP is installed

class RetryRepairAction
{
    /**
     * Asks the model ONCE to fix broken JSON into a valid canonical envelope.
     * Returns repaired string, or null if repair also fails.
     * NEVER call this more than once per original request — see config('llm.repair.max_attempts').
     */
    public function __invoke(string $brokenJson, string $schemaDescription): ?string
    {
        $repairPrompt = <<<PROMPT
        The following JSON is malformed or incomplete. Fix it so it exactly matches this schema:
        {$schemaDescription}

        Broken JSON:
        {$brokenJson}

        Return ONLY the corrected JSON. No markdown. No explanation.
        PROMPT;

        try {
            // $response = Prism::text()
            //     ->using('ollama', config('llm.model'))
            //     ->withPrompt($repairPrompt)
            //     ->usingTemperature(0.0)  // deterministic repair
            //     ->withMaxTokens(512)
            //     ->generate();
            // return $response->text;

            // Stub — return null until PrismPHP is wired
            return null;

        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->warning('llm.repair.failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
```

---

### STEP 6.4 — Create `app/Services/Llm/PromptManagerService.php`

```php
<?php
// app/Services/Llm/PromptManagerService.php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRequestDto;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;

class PromptManagerService
{
    public function __construct(
        private readonly string $schemaVersion,
        private readonly string $timezone,
        private readonly array  $allowedTools,
    ) {}

    public function buildRequest(string $message, ContextDto $context): LlmRequestDto
    {
        return new LlmRequestDto(
            systemPrompt:    $this->buildSystemPrompt(),
            userPayloadJson: json_encode($this->buildUserPayload($message, $context), JSON_UNESCAPED_UNICODE),
            temperature:     config('llm.temperature'),
            maxTokens:       config('llm.max_tokens'),
            traceId:         null,  // injected by LlmChatService
        );
    }

    public function buildToolFollowUpRequest(
        ToolCallDto $toolCall,
        ToolResultDto $toolResult,
        ContextDto $context,
    ): LlmRequestDto {
        $payload = [
            'instruction'  => 'The tool was executed. Write a short, friendly confirmation message for the user based on the result.',
            'tool_used'    => $toolCall->tool,
            'tool_success' => $toolResult->success,
            'result'       => $toolResult->payload,
        ];

        return new LlmRequestDto(
            systemPrompt:    $this->buildSystemPrompt(),
            userPayloadJson: json_encode($payload, JSON_UNESCAPED_UNICODE),
            temperature:     config('llm.temperature'),
            maxTokens:       256,  // short narration only
        );
    }

    // ── Private builders ───────────────────────────────────────────────

    private function buildUserPayload(string $message, ContextDto $context): array
    {
        return [
            'user_message' => $message,
            'current_time' => $context->now->format(\DateTimeInterface::ATOM),
            'timezone'     => $this->timezone,
            'summary_mode' => $context->isSummaryMode,
            'tasks'        => array_map(fn ($t) => [
                'id'               => "task_{$t->id}",
                'title'            => $t->title,
                'due_date'         => $t->dueDate,
                'priority'         => $t->priority,
                'estimate_minutes' => $t->estimateMinutes,
            ], $context->tasks),
            'upcoming_events' => array_map(fn ($e) => [
                'id'               => "event_{$e->id}",
                'title'            => $e->title,
                'start_datetime'   => $e->startDatetime,
                'duration_minutes' => $e->durationMinutes,
            ], $context->events),
            'recent_messages' => array_map(fn ($m) => [
                'role' => $m->role,
                'text' => $m->text,
            ], $context->recentMessages),
        ];
    }

    private function buildSystemPrompt(): string
    {
        // Full system prompt — see §SYSTEM_PROMPT section below.
        // Loaded from a stub here; move to a Blade/stub file if it grows further.
        return $this->systemPromptText();
    }

    private function systemPromptText(): string
    {
        $version      = $this->schemaVersion;
        $tz           = $this->timezone;
        $toolList     = implode(', ', $this->allowedTools);

        return <<<PROMPT
You are a focused, reliable Task Assistant for a personal productivity student app.
Interpret user messages about tasks, schedules, and prioritization, then return a SINGLE strictly-formatted JSON object.
No markdown. No code fences. No commentary outside the JSON.

CONSTRAINTS (never violate)
- Return only valid JSON matching the canonical envelope exactly.
- Never fabricate IDs, timestamps, or entities. If an ID is unknown, return intent:"clarify" or intent:"error".
- The model proposes only — it MUST NOT execute writes. Tool calls are proposals; writes are done server-side.
- Dates/times: ISO 8601 with offset (e.g. "2026-03-14T19:00:00+08:00"). Assume {$tz} for ambiguous relative times.
- Allowed tool names: {$toolList}. Do not invent other tool names.
- For missing/ambiguous required fields: return intent:"clarify" with data.questions. Do NOT create a tool_call.
- For scheduling: if the slot conflicts with an existing event in context, return intent:"clarify" with alternatives.

CANONICAL ENVELOPE (always return this exact shape)
{
  "schema_version": "{$version}",
  "intent": "schedule|create|update|prioritize|list|general|clarify|error",
  "data": {},
  "tool_call": null | {
    "tool": "create_task|update_task|create_schedule",
    "args": {},
    "client_request_id": "req-<uuid>",
    "confirmation_required": false
  },
  "message": "<= 2 sentences, user-facing only>",
  "meta": { "confidence": 0.0 }
}

INTENT DATA SHAPES
- schedule:   { "scheduled_items": [{ "id": "task_<N>", "start_datetime": "ISO8601", "duration_minutes": 30 }] }
- create:     { "title": "string", "description": "string|null", "due_date": "YYYY-MM-DD|null", "estimate_minutes": int|null }
- update:     { "id": "task_<N>", "fields": { "title"?: "...", "due_date"?: "YYYY-MM-DD", "estimate_minutes"?: int } }
- prioritize: { "ranked_ids": ["task_3","task_1"], "reason": "<= 20 words" }
- list:       { "filter": "due_today|next_7_days|high_priority", "limit": 8 }
- clarify:    { "questions": [{ "id": "q1", "text": "..." }] }
- error:      { "code": "PARSE_ERROR|VALIDATION_ERROR|UNKNOWN_ENTITY", "details": "internal-safe text" }

TOOL DEFINITIONS
1) create_task    args: title(req), description, due_date(YYYY-MM-DD), estimate_minutes, client_request_id(req)
2) update_task    args: id(req), fields:{title?,description?,due_date?,estimate_minutes?}, client_request_id(req)
3) create_schedule args: id(req), start_datetime(ISO8601+offset,req), duration_minutes(int>0,req), client_request_id(req)
   Set confirmation_required:true for destructive/bulk operations.

CONFIDENCE: always output meta.confidence 0.0–1.0.

ON JSON FAILURE: return {"schema_version":"{$version}","intent":"error","data":{"code":"PARSE_ERROR","details":"Failed to produce valid JSON."},"tool_call":null,"message":"Sorry, I couldn't understand that. Can you rephrase?","meta":{"confidence":0}}

FEW-SHOT EXAMPLES (follow structure exactly)

User: "Schedule my Physics task for tomorrow at 7pm for 1 hour."
{"schema_version":"{$version}","intent":"schedule","data":{"scheduled_items":[{"id":"task_123","start_datetime":"2026-03-15T19:00:00+08:00","duration_minutes":60}]},"tool_call":{"tool":"create_schedule","args":{"id":"task_123","start_datetime":"2026-03-15T19:00:00+08:00","duration_minutes":60},"client_request_id":"req-<uuid>","confirmation_required":false},"message":"Scheduled Physics for Mar 15 at 7 PM (1 hr).","meta":{"confidence":0.95}}

User: "Which tasks should I do first?"
{"schema_version":"{$version}","intent":"prioritize","data":{"ranked_ids":["task_7","task_3","task_12"],"reason":"Due soonest with highest urgency."},"tool_call":null,"message":"Start with task_7 — nearest deadline.","meta":{"confidence":0.88}}

User: "Schedule my meeting at 7."
{"schema_version":"{$version}","intent":"clarify","data":{"questions":[{"id":"q1","text":"Do you mean 7 AM or 7 PM, and on which date?"}]},"tool_call":null,"message":"I need the exact time — 7 AM or 7 PM, and which date?","meta":{"confidence":0.50}}

SAFETY: never expose secrets, passwords, or PII. All external calls are server-side only.
ENDING RULE: return the canonical envelope only.
PROMPT;
    }
}
```

---

### STEP 6.5 — Create `app/Services/Llm/PostProcessorService.php`

```php
<?php
// app/Services/Llm/PostProcessorService.php

namespace App\Services\Llm;

use App\Actions\Llm\RetryRepairAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\LlmResponseDto;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\Enums\LlmIntent;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Exceptions\Llm\UnknownEntityException;
use Illuminate\Support\Facades\Log;

class PostProcessorService
{
    public function __construct(
        private readonly string           $schemaVersion,
        private readonly float            $confidenceLow,
        private readonly RetryRepairAction $repairAction,
    ) {}

    /**
     * Transforms raw model output into a validated LlmResponseDto.
     * Throws typed exceptions on unrecoverable failures.
     * Returns LlmResponseDto::error() for soft failures (keeps pipeline running).
     */
    public function process(LlmRawResponseDto $raw, ContextDto $context): LlmResponseDto
    {
        $parsed = $this->parseJson($raw->rawText);

        if ($parsed === null) {
            // Attempt single repair
            $repaired = ($this->repairAction)($raw->rawText, 'canonical LLM envelope with intent, data, tool_call, message, meta');
            $parsed   = $repaired ? $this->parseJson($repaired) : null;

            if ($parsed === null) {
                Log::channel(config('llm.log.channel'))->warning('llm.parse_failed', [
                    'raw_snippet' => substr($raw->rawText, 0, 200),
                ]);
                throw new LlmValidationException(
                    'Model output could not be parsed as JSON after repair attempt.',
                    'PARSE_ERROR',
                    $raw->rawText,
                );
            }
        }

        // 1. Schema version check
        $receivedVersion = $parsed['schema_version'] ?? '';
        if ($receivedVersion !== $this->schemaVersion) {
            throw new LlmSchemaVersionException($receivedVersion, $this->schemaVersion);
        }

        // 2. Intent validation
        $intentValue = $parsed['intent'] ?? '';
        $intent = LlmIntent::tryFrom($intentValue);
        if ($intent === null) {
            throw new LlmValidationException(
                "Invalid intent value: [{$intentValue}]",
                'VALIDATION_ERROR',
                $raw->rawText,
            );
        }

        // 3. Confidence
        $confidence = (float) ($parsed['meta']['confidence'] ?? 0.0);

        // 4. Tool call validation
        $toolCall = null;
        if (! empty($parsed['tool_call']) && $intent->canTriggerToolCall()) {
            $toolCall = $this->validateToolCall($parsed['tool_call'], $context, $raw->rawText);
        }

        // 5. Validate referenced task IDs in data
        $this->validateEntityReferences($parsed['data'] ?? [], $context);

        return new LlmResponseDto(
            intent:        $intent,
            data:          $parsed['data'] ?? [],
            toolCall:      $toolCall,
            isError:       $intent === LlmIntent::Error,
            message:       $parsed['message'] ?? '',
            confidence:    $confidence,
            schemaVersion: $receivedVersion,
            raw:           null,  // never pass raw to DTO that reaches UI
        );
    }

    // ── Private helpers ────────────────────────────────────────────────

    private function parseJson(string $text): ?array
    {
        // Strip any accidental markdown fences the model might add
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $clean = preg_replace('/\s*```$/', '', $clean);

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function validateToolCall(array $tc, ContextDto $context, string $rawText): ToolCallDto
    {
        $tool = $tc['tool'] ?? '';
        if (! in_array($tool, config('llm.allowed_tools'), strict: true)) {
            throw new LlmValidationException(
                "Tool [{$tool}] is not in the allowed tools whitelist.",
                'VALIDATION_ERROR',
                $rawText,
            );
        }

        $clientRequestId = $tc['client_request_id'] ?? '';
        if (empty($clientRequestId)) {
            throw new LlmValidationException(
                'Tool call is missing client_request_id.',
                'VALIDATION_ERROR',
                $rawText,
            );
        }

        $args = $tc['args'] ?? [];

        // Validate datetime fields are not in the past
        if (isset($args['start_datetime'])) {
            $start = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $args['start_datetime']);
            $now   = new \DateTimeImmutable('now', new \DateTimeZone(config('llm.timezone')));
            if ($start && $start < $now) {
                throw new LlmValidationException(
                    'Proposed start_datetime is in the past.',
                    'VALIDATION_ERROR',
                    $rawText,
                );
            }
        }

        return new ToolCallDto(
            tool:                $tool,
            args:                $args,
            clientRequestId:     $clientRequestId,
            confirmationRequired: (bool) ($tc['confirmation_required'] ?? false),
        );
    }

    private function validateEntityReferences(array $data, ContextDto $context): void
    {
        $validTaskIds = $context->taskIds();

        // Check scheduled_items references
        foreach ($data['scheduled_items'] ?? [] as $item) {
            $rawId  = $item['id'] ?? '';
            $numericId = (int) str_replace('task_', '', $rawId);
            if (! in_array($numericId, $validTaskIds, strict: true)) {
                throw new UnknownEntityException('task', $rawId);
            }
        }

        // Check update target
        if (isset($data['id'])) {
            $numericId = (int) str_replace('task_', '', $data['id']);
            if (! in_array($numericId, $validTaskIds, strict: true)) {
                throw new UnknownEntityException('task', $data['id']);
            }
        }
    }
}
```

---

### STEP 6.6 — Create `app/Services/Llm/ToolExecutorService.php`

> [!IMPORTANT]
> `Gate::authorize('executeLlmTool', $task)` MUST be called before every task write. Never remove this.
> `LlmToolCall::findByRequestId()` MUST be called before every execution. Never remove this.

```php
<?php
// app/Services/Llm/ToolExecutorService.php

namespace App\Services\Llm;

use App\Actions\Tool\CreateScheduleAction;
use App\Actions\Tool\CreateTaskAction;
use App\Actions\Tool\UpdateTaskAction;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Enums\ToolCallStatus;
use App\Exceptions\Llm\ToolExecutionException;
use App\Exceptions\Llm\UnknownEntityException;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ToolExecutorService
{
    public function __construct(
        private readonly CreateTaskAction     $createTask,
        private readonly UpdateTaskAction     $updateTask,
        private readonly CreateScheduleAction $createSchedule,
    ) {}

    public function execute(ToolCallDto $toolCall, User $user): ToolResultDto
    {
        // 1. Whitelist check — redundant safety net (PostProcessorService already checks)
        if (! in_array($toolCall->tool, config('llm.allowed_tools'), strict: true)) {
            throw new ToolExecutionException(
                "Tool [{$toolCall->tool}] is not whitelisted.",
                $toolCall->tool,
            );
        }

        // 2. IDEMPOTENCY: return cached result if this request was already processed
        if ($existing = LlmToolCall::findByRequestId($toolCall->clientRequestId)) {
            return ToolResultDto::fromStoredPayload($existing->tool_result_payload);
        }

        // 3. AUTHORIZATION: policy gate before any write
        if (in_array($toolCall->tool, ['update_task', 'create_schedule'])) {
            $rawId     = $toolCall->args['id'] ?? null;
            $numericId = (int) str_replace('task_', '', (string) $rawId);
            $task      = Task::find($numericId)
                ?? throw new UnknownEntityException('task', $rawId ?? 'null');

            Gate::authorize('executeLlmTool', $task);  // throws AuthorizationException on fail
        }

        // 4. Execute inside a DB transaction — row inserted in same transaction as domain write
        $result = DB::transaction(function () use ($toolCall, $user) {
            $toolResult = match ($toolCall->tool) {
                'create_task'     => ($this->createTask)($toolCall->args, $user),
                'update_task'     => ($this->updateTask)($toolCall->args, $user),
                'create_schedule' => ($this->createSchedule)($toolCall->args, $user),
                default           => throw new ToolExecutionException(
                    "Unhandled tool: {$toolCall->tool}",
                    $toolCall->tool,
                ),
            };

            // Persist idempotency record inside same transaction
            LlmToolCall::create([
                'client_request_id'   => $toolCall->clientRequestId,
                'user_id'             => $user->id,
                'tool'                => $toolCall->tool,
                'args_hash'           => md5(json_encode($toolCall->args)),
                'tool_result_payload' => $toolResult->toArray(),
                'status'              => ToolCallStatus::Success,
            ]);

            return $toolResult;
        });

        return $result;
    }
}
```

---

### STEP 6.7 — Create `app/Services/Llm/LlmChatService.php`

```php
<?php
// app/Services/Llm/LlmChatService.php

namespace App\Services\Llm;

use App\Actions\Llm\BuildContextAction;
use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Ui\RecommendationDisplayDto;
use App\Enums\ChatMessageRole;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LlmChatService
{
    public function __construct(
        private readonly BuildContextAction  $contextBuilder,
        private readonly PromptManagerService $promptManager,
        private readonly CallLlmAction        $callLlm,
        private readonly PostProcessorService $postProcessor,
        private readonly ToolExecutorService  $toolExecutor,
    ) {}

    public function handle(User $user, string $threadId, string $message, ?string $traceId = null): RecommendationDisplayDto
    {
        $traceId ??= (string) \Illuminate\Support\Str::uuid();

        try {
            // Step 1: Build context
            $context = ($this->contextBuilder)($user, $threadId, $message);

            // Step 2: Build LLM request
            $request = $this->promptManager->buildRequest($message, $context);
            $request = new \App\DataTransferObjects\Llm\LlmRequestDto(
                systemPrompt:    $request->systemPrompt,
                userPayloadJson: $request->userPayloadJson,
                temperature:     $request->temperature,
                maxTokens:       $request->maxTokens,
                options:         $request->options,
                traceId:         $traceId,
            );

            // Step 3: Call model
            $rawResponse = ($this->callLlm)($request);

            // Step 4: Validate and post-process
            $llmResponse = $this->postProcessor->process($rawResponse, $context);

            // Step 5: Execute tool if proposed and valid
            $toolResult = null;
            if ($llmResponse->hasToolCall()) {
                $toolResult = $this->toolExecutor->execute($llmResponse->toolCall, $user);
            }

            // Step 6: Optional follow-up narration call
            $finalMessage = $llmResponse->message;
            if ($toolResult?->success) {
                try {
                    $followUp    = $this->promptManager->buildToolFollowUpRequest($llmResponse->toolCall, $toolResult, $context);
                    $followRaw   = ($this->callLlm)($followUp);
                    $followParsed = json_decode($followRaw->rawText, true);
                    $finalMessage = $followParsed['message'] ?? $finalMessage;
                } catch (\Throwable) {
                    // Follow-up failure is non-fatal — use original message
                }
            }

            // Step 7: Persist assistant message
            ChatMessage::create([
                'thread_id'    => $threadId,
                'role'         => ChatMessageRole::Assistant,
                'content_text' => $finalMessage,
                'content_json' => [
                    'intent'     => $llmResponse->intent->value,
                    'data'       => $llmResponse->data,
                    'tool_call'  => $llmResponse->toolCall ? [
                        'tool'   => $llmResponse->toolCall->tool,
                        'args'   => $llmResponse->toolCall->args,
                    ] : null,
                    'tool_result' => $toolResult?->toArray(),
                ],
                'meta' => [
                    'confidence' => $llmResponse->confidence,
                    'trace_id'   => $traceId,
                    'latency_ms' => $rawResponse->latencyMs,
                    'tokens'     => $rawResponse->tokensUsed,
                ],
            ]);

            return new RecommendationDisplayDto(
                primaryMessage: $finalMessage,
                cards:          $this->buildCards($llmResponse->data, $llmResponse->intent),
                actions:        $this->buildActions($llmResponse, $toolResult),
                isError:        false,
                traceId:        $traceId,
            );

        } catch (LlmValidationException | LlmSchemaVersionException $e) {
            Log::channel(config('llm.log.channel'))->warning('llm.validation_error', [
                'trace_id' => $traceId,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);
            $this->persistErrorMessage($threadId, $traceId);
            return new RecommendationDisplayDto(
                primaryMessage: "I couldn't understand that. Please try rephrasing.",
                isError:        true,
                traceId:        $traceId,
            );

        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->error('llm.unexpected_error', [
                'trace_id' => $traceId,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);
            $this->persistErrorMessage($threadId, $traceId);
            return new RecommendationDisplayDto(
                primaryMessage: "Something went wrong. Please try again.",
                isError:        true,
                traceId:        $traceId,
            );
        }
    }

    private function persistErrorMessage(string $threadId, string $traceId): void
    {
        ChatMessage::create([
            'thread_id'    => $threadId,
            'role'         => ChatMessageRole::Assistant,
            'content_text' => "Sorry, I couldn't process that request.",
            'meta'         => ['error' => true, 'trace_id' => $traceId],
        ]);
    }

    private function buildCards(array $data, \App\Enums\LlmIntent $intent): array
    {
        // Map intent-specific data into UI card structures
        // Implement based on your Livewire card component expectations
        return [];
    }

    private function buildActions(\App\DataTransferObjects\Llm\LlmResponseDto $response, ?\App\DataTransferObjects\Llm\ToolResultDto $toolResult): array
    {
        // Build CTA button definitions for the UI
        // Implement based on your Livewire action component expectations
        return [];
    }
}
```

---

### STEP 6.8 — Create Tool Actions

```php
<?php
// app/Actions/Tool/CreateTaskAction.php

namespace App\Actions\Tool;

use App\DataTransferObjects\Llm\ToolResultDto;
use App\Models\Task;
use App\Models\User;

class CreateTaskAction
{
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $task = Task::create([
            'user_id'          => $user->id,
            'title'            => $args['title'],
            'description'      => $args['description'] ?? null,
            'due_date'         => $args['due_date'] ?? null,
            'estimate_minutes' => $args['estimate_minutes'] ?? null,
            'is_completed'     => false,
        ]);

        return new ToolResultDto(
            tool:    'create_task',
            success: true,
            payload: ['id' => $task->id, 'title' => $task->title],
        );
    }
}
```

```php
<?php
// app/Actions/Tool/UpdateTaskAction.php

namespace App\Actions\Tool;

use App\DataTransferObjects\Llm\ToolResultDto;
use App\Exceptions\Llm\UnknownEntityException;
use App\Models\Task;
use App\Models\User;

class UpdateTaskAction
{
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $numericId = (int) str_replace('task_', '', (string) ($args['id'] ?? ''));
        $task      = Task::where('user_id', $user->id)->find($numericId)
            ?? throw new UnknownEntityException('task', $args['id'] ?? 'null');

        $task->update(array_filter($args['fields'] ?? [], fn ($v) => $v !== null));

        return new ToolResultDto(
            tool:    'update_task',
            success: true,
            payload: ['id' => $task->id, 'title' => $task->title],
        );
    }
}
```

```php
<?php
// app/Actions/Tool/CreateScheduleAction.php

namespace App\Actions\Tool;

use App\DataTransferObjects\Llm\ToolResultDto;
use App\Exceptions\Llm\UnknownEntityException;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;

class CreateScheduleAction
{
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $numericId = (int) str_replace('task_', '', (string) ($args['id'] ?? ''));
        $task      = Task::where('user_id', $user->id)->find($numericId)
            ?? throw new UnknownEntityException('task', $args['id'] ?? 'null');

        $start    = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $args['start_datetime']);
        $duration = (int) $args['duration_minutes'];
        $end      = $start->modify("+{$duration} minutes");

        $schedule = Schedule::create([
            'user_id'          => $user->id,
            'task_id'          => $task->id,
            'start_datetime'   => $start,
            'end_datetime'     => $end,
            'duration_minutes' => $duration,
        ]);

        return new ToolResultDto(
            tool:    'create_schedule',
            success: true,
            payload: [
                'schedule_id'    => $schedule->id,
                'task_id'        => $task->id,
                'task_title'     => $task->title,
                'start_datetime' => $args['start_datetime'],
                'duration_minutes' => $duration,
            ],
        );
    }
}
```

---

## Phase 7 — Queued Job

> [!IMPORTANT]
> The job is the ONLY entry point from HTTP into the LLM pipeline. Never call `LlmChatService::handle()` directly from a controller or Livewire component.

---

### STEP 7.1 — Create `app/Jobs/ProcessLlmRequestJob.php`

```php
<?php
// app/Jobs/ProcessLlmRequestJob.php

namespace App\Jobs;

use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Llm\LlmChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLlmRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;

    public function __construct(
        public readonly User       $user,
        public readonly ChatThread $thread,
        public readonly string     $message,
        public readonly string     $clientRequestId,
        public readonly string     $traceId,
    ) {
        $this->timeout = config('llm.queue.timeout');
        $this->tries   = config('llm.queue.tries');
        $this->onQueue(config('llm.queue.name'));
        $this->onConnection(config('llm.queue.connection'));
    }

    public function handle(LlmChatService $service): void
    {
        $service->handle(
            user:      $this->user,
            threadId:  (string) $this->thread->id,
            message:   $this->message,
            traceId:   $this->traceId,
        );

        // Broadcast to Livewire via event or polling
        // event(new LlmResponseReady($this->user->id, $this->thread->id));
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel(config('llm.log.channel'))->critical('llm.job.permanent_failure', [
            'trace_id'  => $this->traceId,
            'user_id'   => $this->user->id,
            'thread_id' => $this->thread->id,
            'exception' => $exception->getMessage(),
        ]);

        // Ensure the UI is never left hanging with no assistant reply
        ChatMessage::create([
            'thread_id'    => $this->thread->id,
            'role'         => ChatMessageRole::Assistant,
            'content_text' => "Sorry, I couldn't process that request. Please try again.",
            'meta'         => ['error' => true, 'trace_id' => $this->traceId],
        ]);
    }
}
```

---

### STEP 7.2 — Create `app/Http/Controllers/ChatThreadController.php`

```php
<?php
// app/Http/Controllers/ChatThreadController.php

namespace App\Http\Controllers;

use App\Enums\ChatMessageRole;
use App\Http\Requests\Chat\CreateChatThreadRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Jobs\ProcessLlmRequestJob;
use App\Models\ChatThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ChatThreadController extends Controller
{
    public function store(CreateChatThreadRequest $request): JsonResponse
    {
        $thread = ChatThread::create([
            'user_id'        => $request->user()->id,
            'title'          => $request->input('title'),
            'schema_version' => config('llm.schema_version'),
        ]);

        return response()->json(['thread_id' => $thread->id], 201);
    }

    public function sendMessage(StoreChatMessageRequest $request, ChatThread $thread): JsonResponse
    {
        $traceId = (string) Str::uuid();

        // Persist user message immediately (optimistic UI support)
        $thread->messages()->create([
            'role'              => ChatMessageRole::User,
            'author_id'         => $request->user()->id,
            'content_text'      => $request->input('message'),
            'client_request_id' => $request->input('client_request_id'),
            'meta'              => ['trace_id' => $traceId],
        ]);

        // Dispatch job — NEVER call LlmChatService inline here
        ProcessLlmRequestJob::dispatch(
            user:            $request->user(),
            thread:          $thread,
            message:         $request->input('message'),
            clientRequestId: $request->input('client_request_id'),
            traceId:         $traceId,
        );

        return response()->json([
            'status'   => 'queued',
            'trace_id' => $traceId,
        ], 202);
    }

    public function messages(ChatThread $thread): JsonResponse
    {
        $this->authorize('view', $thread);

        return response()->json(
            $thread->messages()
                ->select(['id', 'role', 'content_text', 'meta', 'created_at'])
                ->get()
        );
    }

    public function update(StoreChatMessageRequest $request, ChatThread $thread): JsonResponse
    {
        $this->authorize('update', $thread);
        $thread->update(['title' => $request->input('title')]);
        return response()->json(['updated' => true]);
    }

    public function destroy(ChatThread $thread): JsonResponse
    {
        $this->authorize('delete', $thread);
        $thread->delete();
        return response()->json(['deleted' => true]);
    }
}
```

**✅ Verify:** `php artisan tinker --execute="dispatch(new App\Jobs\ProcessLlmRequestJob(...))"` dispatches without error (mock user/thread). Check queue: `php artisan queue:work --queue=llm --once`.

---

## Phase 8 — Pest Tests

> [!NOTE]
> **Never call real Ollama in tests.** Always mock `CallLlmAction` using `$this->mock()` or Pest's `mock()`.

---

### STEP 8.1 — Create `tests/Unit/Enums/LlmIntentTest.php`

```php
<?php
// tests/Unit/Enums/LlmIntentTest.php

use App\Enums\LlmIntent;

it('resolves all expected cases from string values', function () {
    expect(LlmIntent::from('schedule'))->toBe(LlmIntent::Schedule);
    expect(LlmIntent::from('error'))->toBe(LlmIntent::Error);
});

it('correctly identifies tool-triggering intents', function () {
    expect(LlmIntent::Schedule->canTriggerToolCall())->toBeTrue();
    expect(LlmIntent::Create->canTriggerToolCall())->toBeTrue();
    expect(LlmIntent::Prioritize->canTriggerToolCall())->toBeFalse();
    expect(LlmIntent::Error->canTriggerToolCall())->toBeFalse();
});

it('correctly identifies read-only intents', function () {
    expect(LlmIntent::Prioritize->isReadOnly())->toBeTrue();
    expect(LlmIntent::Schedule->isReadOnly())->toBeFalse();
});

it('returns all allowed values as strings', function () {
    expect(LlmIntent::allowedValues())->toContain('schedule', 'create', 'error', 'clarify');
});
```

---

### STEP 8.2 — Create `tests/Unit/Services/PostProcessorServiceTest.php`

```php
<?php
// tests/Unit/Services/PostProcessorServiceTest.php

use App\Actions\Llm\RetryRepairAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\Enums\LlmIntent;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Exceptions\Llm\UnknownEntityException;
use App\Services\Llm\PostProcessorService;

function makeProcessor(?RetryRepairAction $repair = null): PostProcessorService
{
    return new PostProcessorService(
        schemaVersion: '2026-03-01.v1',
        confidenceLow: 0.4,
        repairAction:  $repair ?? new RetryRepairAction(),
    );
}

function makeContext(array $taskIds = [1, 2, 3]): ContextDto
{
    $tasks = array_map(fn ($id) => new TaskContextItem($id, "Task {$id}", null, 0, null), $taskIds);
    return new ContextDto(new DateTimeImmutable, $tasks, [], []);
}

function validEnvelope(array $overrides = []): string
{
    return json_encode(array_merge([
        'schema_version' => '2026-03-01.v1',
        'intent'         => 'general',
        'data'           => [],
        'tool_call'      => null,
        'message'        => 'Test message.',
        'meta'           => ['confidence' => 0.9],
    ], $overrides));
}

it('processes a valid general intent response', function () {
    $raw    = new LlmRawResponseDto(validEnvelope(), 100);
    $result = makeProcessor()->process($raw, makeContext());

    expect($result->intent)->toBe(LlmIntent::General);
    expect($result->isError)->toBeFalse();
    expect($result->confidence)->toBe(0.9);
});

it('throws LlmValidationException on unparseable JSON', function () {
    $repair = mock(RetryRepairAction::class)
        ->expect(__invoke: fn () => null);

    $raw = new LlmRawResponseDto('NOT JSON AT ALL', 10);

    expect(fn () => makeProcessor($repair)->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});

it('throws LlmSchemaVersionException on version mismatch', function () {
    $raw = new LlmRawResponseDto(validEnvelope(['schema_version' => '1999-01-01.v0']), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmSchemaVersionException::class);
});

it('throws LlmValidationException on invalid intent', function () {
    $raw = new LlmRawResponseDto(validEnvelope(['intent' => 'fly_to_mars']), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});

it('throws UnknownEntityException when model references a task ID not in context', function () {
    $raw = new LlmRawResponseDto(validEnvelope([
        'intent'    => 'schedule',
        'data'      => ['scheduled_items' => [['id' => 'task_9999', 'start_datetime' => '2030-01-01T10:00:00+08:00', 'duration_minutes' => 30]]],
        'tool_call' => ['tool' => 'create_schedule', 'args' => ['id' => 'task_9999', 'start_datetime' => '2030-01-01T10:00:00+08:00', 'duration_minutes' => 30], 'client_request_id' => 'req-' . Str::uuid()],
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext([1, 2, 3])))
        ->toThrow(UnknownEntityException::class);
});

it('rejects a past start_datetime in tool_call', function () {
    $raw = new LlmRawResponseDto(validEnvelope([
        'intent'    => 'schedule',
        'data'      => [],
        'tool_call' => ['tool' => 'create_schedule', 'args' => ['id' => 'task_1', 'start_datetime' => '2000-01-01T10:00:00+08:00', 'duration_minutes' => 30], 'client_request_id' => 'req-uuid'],
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});
```

---

### STEP 8.3 — Create `tests/Feature/Chat/PolicyEnforcementTest.php`

```php
<?php
// tests/Feature/Chat/PolicyEnforcementTest.php

use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\Models\ChatThread;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

it('rejects tool execution when the proposed task belongs to a different user', function () {
    $owner  = User::factory()->create();
    $attacker = User::factory()->create();

    // Task belongs to $owner, NOT $attacker
    $task   = Task::factory()->create(['user_id' => $owner->id]);
    $thread = ChatThread::factory()->create(['user_id' => $attacker->id]);

    // Mock the LLM to propose an update to $owner's task
    $this->mock(CallLlmAction::class)
        ->shouldReceive('__invoke')
        ->andReturn(new LlmRawResponseDto(json_encode([
            'schema_version' => config('llm.schema_version'),
            'intent'         => 'update',
            'data'           => ['id' => "task_{$task->id}", 'fields' => ['title' => 'hacked']],
            'tool_call'      => [
                'tool'              => 'update_task',
                'args'              => ['id' => "task_{$task->id}", 'fields' => ['title' => 'hacked']],
                'client_request_id' => 'req-' . Str::uuid(),
            ],
            'message'        => 'Updated.',
            'meta'           => ['confidence' => 0.9],
        ]), 100));

    // Act as attacker, attempt to modify owner's task
    $this->actingAs($attacker)
        ->postJson("/chat/threads/{$thread->id}/messages", [
            'message'           => 'Update task ' . $task->id,
            'client_request_id' => (string) Str::uuid(),
        ]);

    // Task must NOT have been modified
    expect($task->fresh()->title)->not->toBe('hacked');
});
```

---

### STEP 8.4 — Create `tests/Feature/Chat/IdempotencyTest.php`

```php
<?php
// tests/Feature/Chat/IdempotencyTest.php

use App\Actions\Tool\CreateTaskAction;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Enums\ToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Services\Llm\ToolExecutorService;
use Illuminate\Support\Str;

it('returns cached result and does not create a duplicate task when client_request_id is replayed', function () {
    $user            = User::factory()->create();
    $clientRequestId = 'req-' . Str::uuid();

    $toolCall = new ToolCallDto(
        tool:            'create_task',
        args:            ['title' => 'Idempotency test task'],
        clientRequestId: $clientRequestId,
    );

    $service = app(ToolExecutorService::class);

    // First call — should create the task
    $result1 = $service->execute($toolCall, $user);
    expect($result1->success)->toBeTrue();

    $taskCountAfterFirst = \App\Models\Task::where('user_id', $user->id)->count();

    // Second call with SAME client_request_id — must return cached result, no new task
    $result2 = $service->execute($toolCall, $user);
    expect($result2->success)->toBeTrue();

    $taskCountAfterSecond = \App\Models\Task::where('user_id', $user->id)->count();

    // Exactly one task created, one llm_tool_calls row
    expect($taskCountAfterSecond)->toBe($taskCountAfterFirst);
    expect(LlmToolCall::where('client_request_id', $clientRequestId)->count())->toBe(1);
});
```

---

### STEP 8.5 — Create `tests/Feature/Jobs/ProcessLlmRequestJobTest.php`

```php
<?php
// tests/Feature/Jobs/ProcessLlmRequestJobTest.php

use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\Enums\ChatMessageRole;
use App\Jobs\ProcessLlmRequestJob;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Str;

it('persists an assistant ChatMessage after successful job execution', function () {
    $user   = User::factory()->create();
    $thread = ChatThread::factory()->create(['user_id' => $user->id]);

    $this->mock(CallLlmAction::class)
        ->shouldReceive('__invoke')
        ->andReturn(new LlmRawResponseDto(json_encode([
            'schema_version' => config('llm.schema_version'),
            'intent'         => 'general',
            'data'           => [],
            'tool_call'      => null,
            'message'        => 'Here are your tasks.',
            'meta'           => ['confidence' => 0.85],
        ]), 200));

    ProcessLlmRequestJob::dispatchSync(
        user:            $user,
        thread:          $thread,
        message:         'Show me my tasks',
        clientRequestId: (string) Str::uuid(),
        traceId:         (string) Str::uuid(),
    );

    expect(
        $thread->messages()->where('role', ChatMessageRole::Assistant->value)->exists()
    )->toBeTrue();
});

it('persists a safe error ChatMessage when the job permanently fails', function () {
    $user   = User::factory()->create();
    $thread = ChatThread::factory()->create(['user_id' => $user->id]);

    $job = new ProcessLlmRequestJob(
        user:            $user,
        thread:          $thread,
        message:         'test',
        clientRequestId: (string) Str::uuid(),
        traceId:         (string) Str::uuid(),
    );

    $job->failed(new \RuntimeException('Ollama is down'));

    expect(
        $thread->messages()
            ->where('role', ChatMessageRole::Assistant->value)
            ->whereJsonContains('meta->error', true)
            ->exists()
    )->toBeTrue();
});
```

**✅ Verify (all tests):** `php artisan test --filter=LlmIntent` and `php artisan test --filter=PostProcessor` pass. `php artisan test --filter=Idempotency` passes. Zero real Ollama calls made.

---

## Phase 9 — Livewire Chat Component

> [!NOTE]
> **Depends on:** All previous phases. Keep this component thin — it only dispatches jobs and reads ChatMessages.

---

### STEP 9.1 — Create `app/Livewire/Chat/ChatFlyout.php`

```php
<?php
// app/Livewire/Chat/ChatFlyout.php

namespace App\Livewire\Chat;

use App\Enums\ChatMessageRole;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Jobs\ProcessLlmRequestJob;
use App\Models\ChatThread;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ChatFlyout extends Component
{
    public string $message   = '';
    public bool   $isOpen    = false;
    public bool   $isWaiting = false;

    #[Locked]
    public ?int $threadId = null;

    #[Computed]
    public function thread(): ?ChatThread
    {
        return $this->threadId
            ? ChatThread::find($this->threadId)
            : null;
    }

    #[Computed]
    public function messages(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->threadId) {
            return collect();
        }

        return ChatThread::find($this->threadId)
            ?->messages()
            ->select(['id', 'role', 'content_text', 'meta', 'created_at'])
            ->get()
            ?? collect();
    }

    public function openThread(int $threadId): void
    {
        $thread = ChatThread::findOrFail($threadId);
        $this->authorize('view', $thread);
        $this->threadId = $threadId;
        $this->isOpen   = true;
    }

    public function createThread(): void
    {
        $thread          = ChatThread::create([
            'user_id'        => auth()->id(),
            'schema_version' => config('llm.schema_version'),
        ]);
        $this->threadId  = $thread->id;
        $this->isOpen    = true;
    }

    public function sendMessage(): void
    {
        $this->validate(['message' => 'required|string|min:1|max:2000']);

        $thread = ChatThread::findOrFail($this->threadId);
        $this->authorize('sendMessage', $thread);

        $traceId         = (string) Str::uuid();
        $clientRequestId = (string) Str::uuid();

        // Persist user message immediately (optimistic)
        $thread->messages()->create([
            'role'              => ChatMessageRole::User,
            'author_id'         => auth()->id(),
            'content_text'      => $this->message,
            'client_request_id' => $clientRequestId,
            'meta'              => ['trace_id' => $traceId],
        ]);

        // Dispatch job — NEVER inline LlmChatService here
        ProcessLlmRequestJob::dispatch(
            user:            auth()->user(),
            thread:          $thread,
            message:         $this->message,
            clientRequestId: $clientRequestId,
            traceId:         $traceId,
        );

        $this->message   = '';
        $this->isWaiting = true;
    }

    public function pollForResponse(): void
    {
        // Called by Livewire polling wire:poll.2s or Alpine interval
        // Stop waiting once an assistant message appears
        if ($this->threadId) {
            $hasReply = ChatThread::find($this->threadId)
                ?->messages()
                ->where('role', ChatMessageRole::Assistant->value)
                ->latest()
                ->exists();

            if ($hasReply) {
                $this->isWaiting = false;
            }
        }
    }

    public function render()
    {
        return view('livewire.chat.chat-flyout');
    }
}
```

---

## Implementation Order Summary

Execute steps in this exact order. Each step's ✅ Verify check must pass before proceeding.

```
Phase 1  → config/llm.php  →  3 Enums  →  4 Exceptions
Phase 2  → 3 Migrations (run php artisan migrate)  →  Model scopes (Task, Schedule)  →  3 new Models
Phase 3  → 9 DTOs
Phase 4  → 3 Policies  →  Register in AuthServiceProvider
Phase 5  → LlmServiceProvider  →  2 Form Requests  →  Routes
Phase 6  → BuildContextAction  →  CallLlmAction  →  RetryRepairAction
          → PromptManagerService  →  PostProcessorService  →  ToolExecutorService
          → LlmChatService  →  3 Tool Actions
Phase 7  → ProcessLlmRequestJob  →  ChatThreadController
Phase 8  → 5 Pest test files  (php artisan test --testsuite=Feature)
Phase 9  → ChatFlyout Livewire component  →  Blade view
```

---

## Final Acceptance Checklist

Run these checks after completing all phases:

```bash
# Config loads
php artisan config:clear && php artisan tinker --execute="dump(config('llm'))"

# Migrations applied
php artisan migrate:status | grep -E "chat_threads|chat_messages|llm_tool_calls"

# Routes registered with middleware
php artisan route:list | grep chat

# Policies registered
php artisan policy:list | grep -E "ChatThread|Task|LlmToolCall"

# All tests pass
php artisan test

# Queue worker picks up jobs (no Ollama needed — CallLlmAction stub returns valid JSON)
php artisan queue:work --queue=llm --once
```

- [ ] All `php artisan test` tests pass — zero failures, zero Ollama calls in CI
- [ ] `client_request_id` has unique DB constraint on `llm_tool_calls`
- [ ] `Gate::authorize('executeLlmTool', $task)` present in `ToolExecutorService` and not bypassable
- [ ] No magic strings — all `intent`, `role`, `status` values go through enums
- [ ] No config values hardcoded in class files — all read from `config('llm.*')`
- [ ] `ProcessLlmRequestJob` is the only path from HTTP into `LlmChatService`
- [ ] `llm_raw` is never returned in any HTTP response or Livewire property
- [ ] `failed()` handler on the job creates a safe error `ChatMessage` so the UI never hangs

---

*Ready for Cursor implementation. Execute phases in order. Use ✅ Verify checks as stopping points.*
