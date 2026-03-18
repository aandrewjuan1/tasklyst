# Task Assistant Response Processor

## Overview

The `TaskAssistantResponseProcessor` is a centralized service that validates and formats all LLM responses from the Hermes3:3b task assistant. It ensures consistent, student-friendly output across all interaction flows while maintaining robust validation and error handling.

## Architecture

### Core Components

1. **TaskAssistantResponseProcessor** - Main service class
2. **Flow Validators** - Flow-specific validation logic
3. **Formatters** - Student-friendly text formatting
4. **Retry Logic** - Automatic retry with correction messages
5. **Fallback System** - Safe defaults when all retries fail

### Integration Points

- **TaskAssistantService** - Uses processor for all flows
- **Frontend Components** - Always display formatted text
- **Database** - Stores formatted content + structured metadata

## Supported Flows

### 1. Advisory Flow
- **Purpose**: General advice and guidance
- **Validation**: Summary (5+ words), bullets (10+ chars), follow-ups
- **Format**: Summary paragraph + bullet points + follow-up questions
- **Example**:
  ```
  Focus on completing your urgent tasks first to stay on track.
  
  Key points to remember:
  • Complete the math assignment due tomorrow
  • Review science notes for upcoming test
  
  Would you like help with:
  – Breaking down large tasks?
  – Time management strategies?
  ```

### 2. Task Choice Flow
- **Purpose**: Help students choose next task
- **Validation**: Task ID exists in snapshot, descriptive text
- **Format**: Selected task + reason + next steps
- **Example**:
  ```
  Next task: [123] Math Assignment
  
  Focus on your math assignment to meet the deadline.
  
  Why this task:
  This task has the highest priority and is due soon.
  
  Your next steps:
  1. Review the assignment requirements
  2. Complete the first three problems
  ```

### 3. Daily Schedule Flow
- **Purpose**: Create structured daily schedules
- **Validation**: Time format (HH:MM), task/event ID validation
- **Format**: Summary + time blocks with reasons
- **Example**:
  ```
  A balanced schedule with focused work blocks.
  
  Your schedule:
  09:00–10:30 — Study Time
    Why: Focused morning block for important work
  14:00–15:30 — Review Session
    Why: Afternoon review to reinforce learning
  ```

### 4. Study Plan Flow
- **Purpose**: Create structured study/review plans
- **Validation**: Item labels (5+ chars), time estimates
- **Format**: Summary + numbered items with time estimates
- **Example**:
  ```
  Comprehensive study plan covering theory and practice.
  
  Your study plan:
  1. Review algebra concepts (30 min)
     Focus: Foundation for advanced problems
  2. Practice problem sets (45 min)
     Focus: Apply concepts practically
  ```

### 5. Review Summary Flow
- **Purpose**: Summarize completed and remaining tasks
- **Validation**: Task ID cross-reference, comprehensive summary
- **Format**: Completed tasks + remaining tasks + next steps
- **Example**:
  ```
  You've made good progress with 2 tasks completed.
  
  Recently completed:
  ✓ Math Homework
  ✓ Science Lab Report
  
  Still to do:
  ○ History Essay
  ○ Programming Project
  
  Recommended next steps:
  1. Focus on the history essay due tomorrow
  ```

### 6. Mutating Flow
- **Purpose**: Handle tool execution results
- **Validation**: Basic structure validation
- **Format**: Tool result messages
- **Example**:
  ```
  Task created successfully and added to your schedule.
  ```

## Validation Strategy

### Content Quality Checks
- Minimum word counts for summaries
- Minimum character counts for bullet points
- Business logic validation (task IDs in snapshot)
- Time format validation (HH:MM)

### Retry Logic
- **Max Retries**: 2 attempts per flow
- **Correction Messages**: Specific guidance for each flow
- **Fallback Data**: Safe defaults when retries fail

### Error Handling
- Graceful degradation to readable content
- Preservation of structured data in metadata
- Comprehensive logging for debugging

## Formatting Standards

### Student-Friendly Output
1. **Clear Structure**: Logical sections with headers
2. **Readable Format**: Bullet points, numbered lists
3. **Actionable Content**: Specific, concrete suggestions
4. **Appropriate Length**: Concise but comprehensive

### Text Formatting
- **Summary Paragraphs**: 1-2 sentences overview
- **Bullet Points**: Actionable, clear, max 160 chars
- **Numbered Lists**: Step-by-step instructions
- **Time Estimates**: When relevant (e.g., study plans)

## Implementation Details

### Usage in TaskAssistantService

```php
// Process response through ResponseProcessor
$processedResponse = $this->responseProcessor->processResponse(
    flow: 'advisory',
    data: $payload,
    snapshot: $snapshot,
    thread: $thread,
    originalUserMessage: $userMessageContent
);

// Use formatted content for display
$assistantContent = $processedResponse['formatted_content'];

// Store structured data in metadata
$metadata = [
    'structured' => $processedResponse['structured_data'],
    'validation_errors' => $processedResponse['errors'],
    'processed' => $processedResponse['valid'],
];
```

### Frontend Integration

The frontend now always displays formatted text:

```php
// Before: Conditional JSON vs text display
$decoded = json_decode($message->content, true);
$display = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT) : $message->content;

// After: Always formatted text
$display = $message->content; // Already formatted by ResponseProcessor
```

## Testing

### Unit Tests
- Individual flow validation
- Formatting logic
- Error scenarios
- Fallback behavior

### Feature Tests
- Integration with TaskAssistantService
- Database persistence
- Metadata handling
- Retry logic

### Integration Tests
- End-to-end flow testing
- Consistency across flows
- Frontend display verification

## Benefits

### Student Experience
- **Consistent Formatting**: All responses are readable and structured
- **Clear Action Items**: Bullet points and numbered lists
- **Better Comprehension**: Summary + details structure

### Developer Experience
- **Centralized Logic**: Single place for validation/formatting
- **Easier Maintenance**: Changes affect all flows uniformly
- **Better Debugging**: Structured data preserved in metadata

### System Reliability
- **Robust Validation**: Multiple layers of quality checks
- **Graceful Failures**: Safe fallbacks for edge cases
- **Comprehensive Logging**: Detailed error tracking

## Migration Notes

### Before ResponseProcessor
- Advisory flow showed raw JSON to users
- Inconsistent formatting across flows
- Manual validation in individual runners

### After ResponseProcessor
- All flows show formatted, readable text
- Consistent validation and formatting
- Centralized retry and fallback logic
- Preserved structured data for debugging

## Future Enhancements

### Potential Improvements
1. **Content Personalization**: Adaptive formatting based on user preferences
2. **Multilingual Support**: Language-specific formatting rules
3. **Analytics**: Track validation failures and retry patterns
4. **Performance**: Cache frequently used fallback responses

### Extensibility
- Easy to add new flows with validation/formatting
- Pluggable formatters for different output styles
- Configurable validation rules per flow
