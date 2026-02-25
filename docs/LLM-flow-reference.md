# LLM Task, Event, and Project Management Integration Workflow
## High-Level Reference Guide for TaskLyst Implementation

---

## Core Workflow Overview

The user communicates with the system through a chatbot interface (conversational UI), so all requests and responses flow through that chat surface.

```
User Natural Language Input (via Chatbot UI)
    ↓
Intent Classification + Entity Detection (Fast - No LLM)
    ↓
Route to Appropriate Handler (Task/Event/Project)
    ↓
Context Retrieval + Preparation (Minimal, Surgical, Structured)
    ↓
LLM Inference (Hermes 3 3B)
    ↓
Structured Output (JSON with Reasoning)
    ↓
Chatbot Response to User (Explainable Recommendations + Actions)
    ↓
User Action (Accept/Modify/Reject)
    ↓
Backend Execution (Validation → Database Update)
    ↓
Feedback Loop & Logging
```

---

## Phase 1: Intent Classification

### Purpose
Determine what the user is trying to do **without calling the LLM**. This is the gatekeeper that prevents unnecessary AI inference.

### What Happens
- Input: User's natural language request
- Processing: Lightweight regex pattern matching + keyword detection for both intent and entity type
- Output: Intent type + entity type + confidence score

### Entity Detection
Before intent classification, detect which entity type the user is referring to:

- **Tasks**: "task", "todo", "work item", "action item"
- **Events**: "event", "meeting", "appointment", "calendar", "schedule"
- **Projects**: "project", "initiative", "milestone", "deliverable"

### Intent Types

#### Scheduling Intents
1. **schedule_task** - User wants to time a specific task
   - Keywords: "finish", "by", "deadline", "schedule", "when" + task keywords
   - Example: "Schedule my dashboard task by Friday"

2. **schedule_event** - User wants to schedule a calendar event
   - Keywords: "schedule", "book", "set up", "plan" + event keywords
   - Example: "Schedule a team meeting for next Tuesday"

3. **schedule_project** - User wants to plan a project timeline
   - Keywords: "plan", "timeline", "schedule", "start" + project keywords
   - Example: "Schedule the website redesign project"

#### Prioritization Intents
4. **prioritize_tasks** - User wants to rank/order tasks
   - Keywords: "priority", "important", "urgent", "rank", "order" + task keywords
   - Example: "What tasks should I focus on today?"

5. **prioritize_events** - User wants to prioritize calendar events
   - Keywords: "priority", "important", "urgent", "rank" + event keywords
   - Example: "Which events are most important this week?"

6. **prioritize_projects** - User wants to prioritize projects
   - Keywords: "priority", "important", "urgent", "rank" + project keywords
   - Example: "What projects should I prioritize?"

#### Dependency Resolution
7. **resolve_dependency** - User wants to manage blocking across entities
   - Keywords: "blocked", "waiting", "depends", "after"
   - Works across tasks, events, and projects
   - Example: "I'm blocked on the API integration task"

#### Adjustment Intents
8. **adjust_task_deadline** - User wants to change task timing
   - Keywords: "extend", "move", "delay", "push", "earlier" + task keywords
   - Example: "Can we push the dashboard task deadline to next week?"

9. **adjust_event_time** - User wants to reschedule an event
   - Keywords: "move", "reschedule", "change time", "shift" + event keywords
   - Example: "Can we move the team meeting to Thursday?"

10. **adjust_project_timeline** - User wants to adjust project dates
    - Keywords: "extend", "move", "delay", "push", "timeline" + project keywords
    - Example: "Can we extend the website project timeline?"

#### General Query
11. **general_query** - Doesn't match other categories
    - Fallback for unclear requests

### Why This Step Matters
- ✅ **Speed**: Instant decision (<10ms) vs. waiting for LLM
- ✅ **Cost**: No token usage for simple operations
- ✅ **Reliability**: Deterministic, no hallucination risk
- ✅ **User Experience**: Fast initial feedback

### Output Structure
```json
{
  "intent": "schedule_task",
  "entity_type": "task",
  "confidence": 0.9
}
```

### Success Criteria
- Classification accuracy >90% on test cases
- Entity type detection accuracy >85%
- Confidence scores meaningful and calibrated
- Fallback to `general_query` gracefully when uncertain

---

## Phase 2: Context Preparation

### Purpose
Gather **only the necessary data** to help Hermes 3 make an informed decision. More context = slower + more tokens + higher hallucination risk.

### What Happens
1. **Retrieve**: query the database for relevant information tailored to the detected intent.
2. **Structure**: transform that raw data into a small, predictable, machine-readable payload (typically JSON) using an intent + entity-specific schema.
3. **Inject**: include that structured context payload in the LLM request (alongside the system prompt and the user’s chat message).

This ensures the LLM sees consistent fields (instead of ad-hoc text dumps), which improves parsing reliability and reduces hallucinations.

### Context Structure by Intent and Entity Type

#### For Task Intents (`schedule_task`, `prioritize_tasks`, `adjust_task_deadline`)

##### `schedule_task` Intent
```
Required Data:
- Target task details (title, description, deadline, duration estimate)
- User work preferences (timezone, work hours, focus times)
- Blocking tasks (what must complete before this task)
- Dependent tasks (what depends on this completing)
- Recent similar tasks (for effort estimation patterns)
- Conflicting events (if task has scheduled time)

Maximum: 5-10 tasks total
Maximum tokens: ~800-1000
```

##### `prioritize_tasks` Intent
```
Required Data:
- All pending/scheduled tasks (limited to 10 most urgent)
- Each task: deadline, estimated duration, priority, dependencies, project_id
- User work patterns (productivity peaks, context switch limits)
- Current date/time context
- Related events that might affect task scheduling

Maximum: 10 tasks total
Maximum tokens: ~1200
```

##### `adjust_task_deadline` Intent
```
Required Data:
- Task being adjusted
- Current deadline vs. requested deadline
- Dependent tasks affected
- User availability in new timeframe
- Conflicting events in new timeframe

Maximum: 5 tasks
Maximum tokens: ~700
```

#### For Event Intents (`schedule_event`, `prioritize_events`, `adjust_event_time`)

##### `schedule_event` Intent
```
Required Data:
- Event details (title, description, location, all_day flag)
- User calendar availability
- Conflicting events (time overlap detection)
- Conflicting tasks (if tasks have scheduled times)
- Recurring event patterns (if applicable)
- Event instances (for recurring events)
- Related tasks (events can have tasks)
- Tags and reminders
- User timezone preferences

Maximum: 5-10 events total
Maximum tokens: ~1000
```

##### `prioritize_events` Intent
```
Required Data:
- All scheduled/upcoming events (limited to 10 most urgent)
- Each event: start_datetime, end_datetime, timezone, status, location
- Related tasks for each event
- User calendar patterns
- Current date/time context

Maximum: 10 events total
Maximum tokens: ~1200
```

##### `adjust_event_time` Intent
```
Required Data:
- Event being adjusted
- Current start/end datetime vs. requested times
- Conflicting events in new timeframe
- Conflicting tasks in new timeframe
- Related tasks that depend on event timing
- Recurring pattern implications (if recurring)

Maximum: 5 events
Maximum tokens: ~800
```

#### For Project Intents (`schedule_project`, `prioritize_projects`, `adjust_project_timeline`)

##### `schedule_project` Intent
```
Required Data:
- Project details (name, description, start_date, end_date)
- All tasks within project (limited to 10 most relevant)
- Task dependencies within project
- Project tags
- Milestone tracking
- User work capacity
- Related events that might affect project timeline

Maximum: 3-5 projects with 5-10 tasks each
Maximum tokens: ~1200
```

##### `prioritize_projects` Intent
```
Required Data:
- All active projects (limited to 5 most relevant)
- Each project: start_date, end_date, task count, completion rate
- Tasks within each project (top 5 per project)
- Project dependencies
- User work capacity
- Current date/time context

Maximum: 5 projects with 5 tasks each
Maximum tokens: ~1500
```

##### `adjust_project_timeline` Intent
```
Required Data:
- Project being adjusted
- Current start/end dates vs. requested dates
- All tasks within project (to recalculate deadlines)
- Dependent projects
- Related events affected by timeline change

Maximum: 1 project with 10 tasks
Maximum tokens: ~1000
```

#### For `resolve_dependency` Intent (Cross-Entity)
```
Required Data:
- Currently blocked entity details (task/event/project)
- Blocking entity status and estimated completion
- Dependent entities waiting on this one
- Critical path analysis across entity types
- Cross-entity relationships (tasks in projects, tasks in events)

Maximum: 3-5 related entities
Maximum tokens: ~800
```

#### Cross-Entity Context Rules
- When scheduling events, include conflicting tasks (if tasks have scheduled times)
- When scheduling projects, include related events that might affect timeline
- When prioritizing, consider tasks, events, and projects together when relevant
- Always respect entity relationships: tasks can belong to projects and events

### Context Filtering Rules

#### For Tasks
✅ **Include:**
- Active task data (status: to_do, doing)
- User preferences and patterns
- Direct dependencies only
- Recent historical patterns (last 30 days)
- Tasks within relevant projects
- Tasks related to relevant events

❌ **Exclude:**
- Completed tasks (status: done, unless showing patterns)
- All 100+ tasks in the backlog
- Irrelevant metadata
- Full task descriptions if not needed
- Tasks older than 90 days
- Archive data

#### For Events
✅ **Include:**
- Active events (status: scheduled, tentative)
- Recurring event patterns (if applicable)
- Event instances for recurring events
- Related tasks
- Tags and reminders

❌ **Exclude:**
- Cancelled or completed events (unless showing patterns)
- Events older than 90 days
- Full event descriptions if not needed
- Archive data

#### For Projects
✅ **Include:**
- Active projects (within date range or no end_date)
- Tasks within project (limited to most relevant)
- Project tags
- Milestone information

❌ **Exclude:**
- Completed projects (end_date in past, unless showing patterns)
- All tasks in project (limit to 10 most relevant)
- Archive data

### Why This Step Matters
- ✅ **Token Efficiency**: 3B model has limited context window (~8K-32K tokens)
- ✅ **Accuracy**: LLM focuses on relevant data, less noise
- ✅ **Speed**: Smaller payloads = faster inference
- ✅ **Cost**: Fewer tokens = lower resource usage

### Success Criteria
- Context payload <1500 tokens consistently
- Includes all necessary decision factors
- Excludes irrelevant noise
- Preparation completes in <150ms

---

## Phase 3: System Prompting

### Purpose
Set up Hermes 3 to understand its role, constraints, and expected output format.

### System Prompt Structure

Each intent gets a **specific, tailored system prompt** (not generic).

#### Template Components
1. **Role Definition**
   - "You are an intelligent task scheduling assistant"
   - "Your goal is to prioritize tasks based on X, Y, Z"

2. **Analysis Framework**
   - Step-by-step reasoning process
   - What to consider first, second, third
   - How to evaluate tradeoffs

3. **User Context**
   - Work patterns to respect
   - Preferences to honor
   - Constraints to observe

4. **Output Requirements**
   - Response format (JSON only, no markdown)
   - Required fields
   - No extra text before/after JSON
   - Must conform to the provided JSON schema for the detected intent + entity type (field names, types, required/optional fields)

5. **Tone & Behavior**
   - Be concise
   - Be confident but humble
   - Explain reasoning clearly

### System Prompt Examples

#### For Task Scheduling
```
Role: Task scheduling assistant for a developer

Goal: Suggest optimal time slots that respect:
- Deadlines and hard constraints
- Task dependencies
- User's work patterns (morning focus, context switches)
- Effort estimation
- Conflicts with events

Analysis steps:
1. When is the deadline?
2. What tasks block this one?
3. How long will this realistically take?
4. What's the optimal time considering user patterns?
5. Check for conflicts with events and buffer time

Output: JSON with suggested date/time and reasoning
```

#### For Event Scheduling
```
Role: Event scheduling assistant

Goal: Suggest optimal time slots for calendar events that respect:
- User calendar availability
- Timezone handling
- All-day event considerations
- Recurring pattern requirements
- Location conflicts
- Conflicts with tasks and other events

Analysis steps:
1. What is the event duration and type?
2. Is this a recurring event? What pattern?
3. What times is the user available?
4. Are there conflicting events or tasks?
5. What timezone considerations apply?
6. Suggest optimal start/end datetime

Output: JSON with suggested start_datetime, end_datetime, timezone, all_day, and reasoning
```

#### For Project Planning
```
Role: Project timeline planning assistant

Goal: Suggest optimal project timeline that respects:
- Project duration and scope
- Task dependencies within project
- Milestone dates
- User work capacity
- Related events that affect timeline

Analysis steps:
1. What is the project scope and estimated duration?
2. What tasks are in this project?
3. What are the task dependencies?
4. What milestones need to be hit?
5. What is the user's work capacity?
6. Are there events that affect the timeline?
7. Suggest optimal start_date and end_date

Output: JSON with suggested start_date, end_date, milestones, task_sequence, and reasoning
```

#### For Task Prioritization
```
Role: Task prioritization expert

Goal: Rank tasks by true urgency, considering:
- Deadline proximity
- Task dependencies (what blocks others)
- Effort vs. urgency (RICE scoring)
- User's work patterns and capacity
- Project context

Analysis steps:
1. Identify hard deadline constraints
2. Map dependency graph
3. Calculate impact of each task
4. Determine priority scores (0-100)
5. Highlight critical blockers

Output: JSON with prioritized list and reasoning
```

#### For Event Prioritization
```
Role: Event prioritization expert

Goal: Rank events by importance, considering:
- Event timing and urgency
- Related tasks
- User calendar patterns
- Recurring vs. one-time events
- Location and travel time

Analysis steps:
1. Identify time-sensitive events
2. Consider related tasks
3. Evaluate recurring patterns
4. Determine priority scores (0-100)
5. Highlight critical events

Output: JSON with prioritized event list and reasoning
```

#### For Project Prioritization
```
Role: Project prioritization expert

Goal: Rank projects by strategic importance, considering:
- Project deadlines and milestones
- Task completion rates
- Resource allocation
- Dependencies between projects
- User work capacity

Analysis steps:
1. Identify deadline constraints
2. Evaluate project progress
3. Map project dependencies
4. Calculate impact scores
5. Determine priority scores (0-100)

Output: JSON with prioritized project list and reasoning
```

### Prompt Best Practices
- ✅ Be specific about output format
- ✅ Include/attach a JSON schema (or an explicit schema-like field list) and instruct Hermes 3 to output **only** JSON that conforms to it
- ✅ Provide step-by-step reasoning framework
- ✅ Include user context inline
- ✅ Set temperature to 0.3 (low creativity, high consistency)
- ✅ Limit output tokens to 500 (concise responses)
- ❌ Don't use vague language
- ❌ Don't ask for multiple response formats
- ❌ Don't include conflicting instructions

### Success Criteria
- Output is always valid JSON
- Reasoning is transparent and logical
- Recommendations are actionable
- Temperature 0.3 produces consistent results

---

## Phase 4: LLM Inference

### Purpose
Send minimal context + structured prompt to Hermes 3 and receive structured recommendations.

### Implementation Note (PrismPHP Structured Output)
If you implement the LLM call using PrismPHP, prefer **structured output with an explicit schema** (i.e., use Prism’s structured JSON mode + a schema) so the model is constrained to the expected shape. This should align with the same intent/entity schemas used in Phase 2 and validated by the response parser. In practice, this means using Prism's structured API (for example, `Prism::structured()->using(...)->schema(...)->asStructured()`) and **never trusting free‑form JSON** from the model without schema validation.

### What Happens
1. **Format Request**
   - System prompt (role + analysis framework)
   - User input (natural language request)
   - Context (minimal, curated data from Phase 2)

2. **Call Ollama/Hermes 3**
   - Model: `hermes2:3.1b` or `hermes2:7b`
   - Temperature: 0.3
   - Max tokens: 500
   - Timeout: 30 seconds

3. **Parse Response**
   - Extract JSON from response (handle markdown code blocks if the model misbehaves)
   - Validate against the intent/entity JSON schema (types + required fields)
   - Handle parsing errors gracefully

4. **Log Interaction**
   - Tokens used
   - Processing time
   - Response quality indicator
   - User ID + intent type + entity type

### Inference Parameters

| Parameter | Value | Why |
|-----------|-------|-----|
| Model | hermes2:3.1b | Lightweight, fast, local |
| Temperature | 0.3 | Consistent, deterministic |
| Top K | 20 | Prevent random outputs |
| Max Tokens | 500 | Avoid rambling |
| Timeout | 30s | Prevent hanging |

### Response Structure
Hermes 3 should always return structured JSON with entity type support:

#### For Tasks
```json
{
  "entity_type": "task",
  "entity_id": 123,
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "scheduled_date": "2025-12-10",
  "scheduled_time": "09:00",
  "estimated_duration": 120,
  "priority_score": 92,
  "blockers": ["issue 1", "issue 2"],
  "alternative_options": [
    { "option": "...", "tradeoff": "..." }
  ]
}
```

#### For Events
```json
{
  "entity_type": "event",
  "entity_id": 456,
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "start_datetime": "2025-12-10T09:00:00Z",
  "end_datetime": "2025-12-10T10:00:00Z",
  "timezone": "America/New_York",
  "all_day": false,
  "location": "Conference Room A",
  "recurring_pattern": null,
  "conflicts": ["event 789"],
  "alternative_options": [
    { "option": "...", "tradeoff": "..." }
  ]
}
```

#### For Projects
```json
{
  "entity_type": "project",
  "entity_id": 789,
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "start_date": "2025-12-01",
  "end_date": "2026-01-15",
  "milestones": [
    { "name": "Phase 1 Complete", "date": "2025-12-15" }
  ],
  "task_sequence": [123, 124, 125],
  "blockers": ["task 123"],
  "alternative_options": [
    { "option": "...", "tradeoff": "..." }
  ]
}
```

### Error Handling
- If JSON parsing fails → Use fallback recommendation
- If response times out → Show cached previous result
- If Ollama is down → Activate rule-based fallback
- If confidence <0.6 → Flag for human review

### Success Criteria
- Response always valid JSON
- Processing time <3-5 seconds
- Reasoning includes concrete facts (not vague)
- All required fields present
- Confidence score provided

---

## Phase 5: Structured Output Display

### Purpose
Show the user **both the recommendation AND the reasoning** in a transparent, understandable format, rendered directly in the chatbot interface (with clear action buttons for Accept / Modify / Reject).

### Display Components

#### Primary Recommendation
- Clear visual hierarchy
- Entity-specific key metrics at top:
  - **Tasks**: date, time, priority, duration
  - **Events**: start/end datetime, timezone, location, all-day indicator, recurring pattern
  - **Projects**: start/end dates, milestone dates, task count, progress indicators
- Color coding for urgency/confidence
- Not overwhelming with information

#### Reasoning Section
- Show step-by-step logic
- Reference concrete facts from context
- Explain tradeoffs considered
- Format: "Step 1: X. Step 2: Y. Step 3: Z."

#### Blockers/Dependencies
- List items blocking this entity (tasks, events, or projects)
- Current status of blockers
- Estimated completion time
- Cross-entity blockers (e.g., event blocking task)
- Yellow/red warning colors

#### Smart Suggestions
- 2-3 actionable recommendations
- Based on LLM's analysis
- Entity-specific examples:
  - Tasks: "Schedule this Tuesday morning for best results"
  - Events: "Move to Thursday 2pm to avoid conflicts"
  - Projects: "Start next week to align with team availability"
- Format: Bullet points with checkmarks

#### Confidence Indicator
- 0-100 score
- Visual representation (bar, color)
- Interpretation guide ("Very confident", "Moderate", etc.)

### Layout Principles
- ✅ Information hierarchy (most important first)
- ✅ Visual separations between sections
- ✅ Color coding for quick scanning
- ✅ Mobile-friendly responsive design
- ✅ Clear call-to-action buttons

### User Feedback Elements
- **Accept** button (green, primary action)
- **Modify** button (grey, secondary)
- **Reject/Try Again** button (subtle)
- Show processing state ("Generating...", spinner)
- Show success state ("Scheduled!", checkmark)

### Success Criteria
- User understands recommendation at a glance
- Reasoning is transparent and not magical
- User has clear action options
- Design builds trust, not confusion

---

## Phase 6: User Validation & Action

### Purpose
User reviews the recommendation and decides to accept, modify, or reject it.

### User Actions

#### Option 1: Accept
- User clicks "✅ Accept" button
- Proceeds to Phase 7 (Backend Execution)
- No modification needed

#### Option 2: Modify
- User adjusts parameters (date, time, priority)
- LLM recommendation is overridden
- User's input takes precedence
- Still proceeds to Phase 7 with modified values

#### Option 3: Reject
- User clicks "Try Again" or "Cancel"
- Reverts to input form
- Can enter new request or modify input
- Starts workflow over from Phase 1

### Modification Workflow
If user modifies recommendation:

#### For Tasks:
1. User sees input fields for: date, time, duration, priority
2. User changes one or more values
3. Modified values override LLM suggestion
4. Display updated recommendation
5. Show warning if modification creates conflicts with events or other tasks
6. Proceed to backend execution with modified values

#### For Events:
1. User sees input fields for: start_datetime, end_datetime, timezone, all_day, location, recurring_pattern
2. User changes one or more values
3. Modified values override LLM suggestion
4. Display updated recommendation
5. Show warning if modification creates conflicts with other events or tasks
6. If recurring event, show implications for all instances
7. Proceed to backend execution with modified values

#### For Projects:
1. User sees input fields for: start_date, end_date, milestone dates
2. User changes one or more values
3. Modified values override LLM suggestion
4. Display updated recommendation
5. Show warning if modification affects project tasks or related events
6. Show cascade impact on task deadlines
7. Proceed to backend execution with modified values

### Why This Step Matters
- ✅ **User Control**: LLM is assistant, not dictator
- ✅ **Safety**: Prevents automatic bad decisions
- ✅ **Trust**: User sees and approves changes
- ✅ **Feedback**: User modifications inform future improvements
- ✅ **Flexibility**: Humans catch edge cases LLM misses

### Success Criteria
- User understands the recommendation
- User has clear options (accept/modify/reject)
- Modification flow is smooth and intuitive
- Changes are reflected immediately

---

## Phase 7: Backend Execution

### Purpose
**Now the system makes actual database changes** (not before!). Backend is the source of truth.

### Validation Layer
Before any database change:

1. **Syntax Validation**
   - **Tasks**: Dates valid format, times within work hours, durations reasonable (>0, <12 hours typically) — all computed and verified in backend code, not by the LLM
   - **Events**: DateTime valid format, timezone valid, end_datetime > start_datetime — overlaps and gaps are calculated in backend code only
   - **Projects**: Dates valid format, end_date >= start_date — any roll‑up timeline math is done in backend code

2. **Business Logic Validation**
   - **Tasks**: No scheduling conflicts with events or other tasks, dependencies met, user capacity not exceeded, deadline respected — conflicts and capacity checks are fully deterministic rules, not delegated to the LLM
   - **Events**: No overlapping events (configurable), no conflicts with tasks, timezone handling correct — all overlap detection and timezone math is done in backend code
   - **Projects**: Milestone dates within project range, task dependencies respected, timeline realistic — project‑level constraints are enforced by backend rules, with LLM used only for ranking and suggestion

3. **Cross-Entity Validation**
   - Event/task conflicts (if task has scheduled time)
   - Project timeline vs event conflicts
   - Task dependencies across projects

4. **Data Integrity Checks**
   - Entity exists and belongs to user
   - No race conditions
   - Concurrent modification safe

### Execution Steps

#### For Tasks:
1. **Validate** recommendation against rules
2. **Update** task in database:
   - start_date, end_date
   - duration (minutes)
   - priority (enum: low, medium, high, urgent)
   - status (to_do → doing, or to_do → done)
   - project_id (if applicable)
   - event_id (if applicable)
   - llm_reasoning (store for audit trail)

3. **Cascade Updates**
   - If dependent tasks affected, re-prioritize them
   - Update blockers relationships
   - If task belongs to project, update project progress
   - If task belongs to event, notify event context
   - Notify dependent task owners if shared workspace

#### For Events:
1. **Validate** recommendation against rules
2. **Update** event in database:
   - start_datetime, end_datetime
   - timezone
   - all_day (boolean)
   - location
   - status (scheduled, cancelled, completed, tentative)
   - llm_reasoning (store for audit trail)

3. **Handle Recurring Events** (if applicable):
   - Update recurring_events table
   - Update or create event_instances
   - Handle event_exceptions if needed

4. **Cascade Updates**
   - If event timing changes, notify related tasks
   - Update conflicting events (if configurable)
   - Notify reminders system

#### For Projects:
1. **Validate** recommendation against rules
2. **Update** project in database:
   - start_date, end_date
   - llm_reasoning (store for audit trail)

3. **Cascade Updates**
   - Recalculate deadlines for all tasks within project
   - Update project-related events if timeline changes
   - Re-prioritize project tasks based on new timeline

#### Common Steps (All Entities):
4. **Log Changes**
   - Store in audit log
   - Record: entity_type, entity_id, what changed, why (LLM reasoning), when, by whom
   - For analytics: track LLM recommendation accuracy per entity type

5. **Notify User**
   - Show success message
   - Display updated entity view
   - Optionally: show next recommended action

### Database Transactions
- ✅ Use transactions for multi-step updates
- ✅ Rollback on any validation failure
- ✅ Prevent partial updates

### API/Response Structure

#### For Tasks:
```json
{
  "success": true,
  "message": "Task scheduled for Wednesday, Dec 10 at 9:00 AM",
  "entity_type": "task",
  "entity": {
    "id": 123,
    "title": "...",
    "start_date": "2025-12-10",
    "end_date": "2025-12-10",
    "duration": 120,
    "priority": "high",
    "status": "doing"
  },
  "next_action": "Your next recommended task is..."
}
```

#### For Events:
```json
{
  "success": true,
  "message": "Event scheduled for Wednesday, Dec 10 at 9:00 AM",
  "entity_type": "event",
  "entity": {
    "id": 456,
    "title": "...",
    "start_datetime": "2025-12-10T09:00:00Z",
    "end_datetime": "2025-12-10T10:00:00Z",
    "timezone": "America/New_York",
    "all_day": false,
    "location": "Conference Room A",
    "status": "scheduled"
  },
  "next_action": "Your next recommended event is..."
}
```

#### For Projects:
```json
{
  "success": true,
  "message": "Project timeline set from Dec 1, 2025 to Jan 15, 2026",
  "entity_type": "project",
  "entity": {
    "id": 789,
    "name": "...",
    "start_date": "2025-12-01",
    "end_date": "2026-01-15"
  },
  "cascade_updates": {
    "tasks_updated": 15,
    "milestones_set": 3
  },
  "next_action": "Your next recommended project is..."
}
```

### Error Responses
```json
{
  "success": false,
  "error": "Scheduling conflict detected",
  "entity_type": "task",
  "details": "Task overlaps with 'Team Meeting' event already scheduled for 9am-10am",
  "suggestion": "Try scheduling for Thursday instead"
}
```

### Why This Step Matters
- ✅ **Data Integrity**: All changes validated before commit
- ✅ **Audit Trail**: Track what LLM recommended vs. what actually happened
- ✅ **Rollback Capability**: Undo changes if needed
- ✅ **Monitoring**: Detect if LLM makes consistently bad recommendations

### Success Criteria
- All database changes are validated
- Transactions ensure consistency
- Audit trail is complete
- User gets clear feedback on success/failure

---

## Phase 8: Feedback Loop & Logging

### Purpose
Track LLM performance, user acceptance, and gather data to improve future recommendations.

### What Gets Logged

#### Per Interaction
- **User ID** - who made the request
- **Intent Type** - what they were trying to do
- **Entity Type** - task, event, or project
- **Input Context** - what data was sent to LLM
- **LLM Response** - what the model returned
- **Tokens Used** - for cost tracking
- **Processing Time** - for performance monitoring
- **User Action** - did they accept/modify/reject?

#### Metrics Tracked
- **LLM Accuracy** - did user accept the recommendation? (per entity type)
- **Token Efficiency** - how many tokens per decision type and entity type?
- **Latency** - how fast was the full workflow? (per entity type)
- **Error Rate** - how often did parsing/inference fail? (per entity type)
- **User Satisfaction** - do users modify recommendations often? (per entity type)
- **Cross-Entity Conflicts** - how often do events conflict with tasks?

### Analytics Dashboard (Future)
Questions to answer with logs:
- Which intent types have highest acceptance rate?
- Which entity types (tasks/events/projects) have highest acceptance rate?
- What time of day are recommendations most accurate?
- How does recommendation quality correlate with context size?
- Are certain user types accepting recommendations more?
- What's the most common modification pattern?
- Do users modify events more than tasks?
- How often do cross-entity conflicts occur?
- Which entity types require the most user modifications?

### Continuous Improvement
Use logged data to:
1. **Refine prompts** - If acceptance <70%, tweak system prompt
2. **Adjust confidence** - Calibrate confidence scores against actual acceptance
3. **Tune parameters** - Find optimal temperature, max_tokens, context size
4. **Detect edge cases** - Find patterns in rejections, address them

### Success Criteria
- All interactions logged consistently
- Logs contain both input and output
- Processing times tracked
- User acceptance data recorded
- Data structure supports future analysis

---

## Complete Workflow Decision Tree

```
User Input
├─ IntentClassifier + EntityDetector
│  ├─ schedule_task (90% conf, entity: task) → Task schedule handler
│  ├─ schedule_event (85% conf, entity: event) → Event schedule handler
│  ├─ schedule_project (80% conf, entity: project) → Project schedule handler
│  ├─ prioritize_tasks (85% conf, entity: task) → Task prioritize handler
│  ├─ prioritize_events (80% conf, entity: event) → Event prioritize handler
│  ├─ prioritize_projects (75% conf, entity: project) → Project prioritize handler
│  ├─ resolve_dependency (70% conf, entity: any) → Cross-entity dependency handler
│  ├─ adjust_task_deadline (80% conf, entity: task) → Task deadline handler
│  ├─ adjust_event_time (75% conf, entity: event) → Event time handler
│  ├─ adjust_project_timeline (70% conf, entity: project) → Project timeline handler
│  └─ general_query (?) → Help message
│
├─ ContextPreparer
│  ├─ Fetch minimal relevant data from DB
│  ├─ Include cross-entity context (events for tasks, tasks for projects, etc.)
│  └─ Filter by entity type and intent
│
├─ OllamaService
│  ├─ System Prompt (intent + entity-specific)
│  ├─ Context (capped at ~1000-1500 tokens)
│  └─ Hermes 3 Inference (temp 0.3, max 500 tokens)
│
├─ Response Parser
│  ├─ Extract JSON from response
│  ├─ Validate structure (entity_type required)
│  └─ Fallback if parsing fails
│
├─ Display Layer
│  ├─ Render recommendation (entity-specific UI)
│  ├─ Show reasoning
│  ├─ Highlight blockers (cross-entity aware)
│  └─ Provide action buttons
│
├─ User Action
│  ├─ Accept → Backend execution
│  ├─ Modify → Updated backend execution (entity-specific fields)
│  └─ Reject → Reset form
│
└─ Backend + Logging
   ├─ Validate all changes (entity-specific rules)
   ├─ Update database (tasks/events/projects tables)
   ├─ Cascade updates (cross-entity aware)
   ├─ Log interaction (with entity_type)
   └─ Show success/failure
```

---

## Performance Targets

| Stage | Duration | Target |
|-------|----------|--------|
| Intent Classification | <10ms | Instant, no waiting |
| Context Preparation | 50-150ms | Fast DB queries |
| LLM Inference | 1-3 seconds | Acceptable wait |
| Display Rendering | <100ms | Instant |
| **Total Round Trip** | **2-4 seconds** | **Snappy UX** |

---

## Fallback Strategy

If LLM fails at any point:

1. **LLM Timeout** → Use cached previous recommendation
2. **JSON Parse Error** → Show generic suggestion + "Try rephrasing"
3. **Ollama Offline** → Activate rule-based prioritization
4. **Confidence <60%** → Flag for manual review
5. **Unknown Intent** → Show help examples

Rule-based fallback logic:
- Prioritize by deadline only
- Schedule by availability only
- Do NOT make assumptions

---

## Key Design Principles

### 1. Progressive Disclosure
- Show what matters first (recommendation)
- Details available on demand (reasoning)
- Don't overwhelm user with data

### 2. Trust Through Transparency
- Always show why LLM made a decision
- Include concrete facts in reasoning
- Admit uncertainty via confidence scores

### 3. User Control
- LLM suggests, user approves
- Easy modification options
- User can always override

### 4. Fail Gracefully
- Errors are not crashes
- Fallback to simple rules
- Clear error messages

### 5. Efficient Context
- Less is more (minimal context window)
- Every token counts (cost + quality)
- Curate ruthlessly

### 6. Intent First
- Don't call LLM for simple decisions
- Classify before computing
- Route intelligently

### 7. Structured Over Free-Form
- JSON output, never free text
- Parseable, predictable format
- Prevents hallucination

### 8. Audit Everything
- Log all decisions
- Store reasoning
- Enable continuous improvement

---

## Implementation Checklist

### Phase 1: Intent Classification
- [ ] Define regex patterns for each intent and entity type
- [ ] Implement entity detection (task/event/project)
- [ ] Test classification accuracy >90%
- [ ] Test entity detection accuracy >85%
- [ ] Implement confidence scoring
- [ ] Add fallback to `general_query`

### Phase 2: Context Preparation
- [ ] Design context schema per intent type and entity type
- [ ] Implement data filtering:
  - Tasks: max 10 tasks
  - Events: max 10 events
  - Projects: max 5 projects with 5-10 tasks each
- [ ] Set token budget (~1000-1500 max)
- [ ] Add caching for user preferences
- [ ] Implement cross-entity context (events for tasks, tasks for projects)

### Phase 3: System Prompting
- [ ] Write intent-specific and entity-specific system prompts
  - [ ] Task scheduling prompts
  - [ ] Event scheduling prompts
  - [ ] Project planning prompts
  - [ ] Prioritization prompts (all entity types)
- [ ] Test with Hermes 3 locally
- [ ] Tune temperature (0.3 recommended)
- [ ] Validate JSON output format (with entity_type)

### Phase 4: LLM Inference
- [ ] Setup Ollama client
- [ ] Implement error handling
- [ ] Add request logging (with entity_type)
- [ ] Setup performance monitoring per entity type
- [ ] Use Prism (or equivalent) structured output mode with explicit JSON schema per intent/entity
- [ ] Reject or fallback on any response that fails schema validation (never silently accept malformed JSON)

### Testing “Golden Paths” (End‑to‑End)
- [ ] Define at least 2–3 **canonical scenarios** per core intent (e.g., `schedule_task`, `prioritize_tasks`, `schedule_event`)
- [ ] For each scenario, fix:
  - [ ] Input utterance (user message)
  - [ ] Expected context payload (Phase 2)
  - [ ] Expected structured LLM output shape (fields + types, not exact wording)
  - [ ] Expected backend validation behavior and final database changes
- [ ] Automate these as end‑to‑end tests so future changes to prompts, schemas, or context preparation cannot silently break the pipeline

### Phase 5: Display Layer
- [ ] Design recommendation card UI (entity-specific)
- [ ] Implement reasoning display
- [ ] Add blocker alerts (cross-entity aware)
- [ ] Create action buttons
- [ ] Entity-specific metrics display:
  - [ ] Tasks: date, time, priority, duration
  - [ ] Events: datetime, timezone, location, all-day, recurring
  - [ ] Projects: timeline, milestones, task count, progress

### Phase 6: User Validation
- [ ] Build accept/modify/reject buttons
- [ ] Implement modify form (entity-specific fields):
  - [ ] Tasks: date, time, duration, priority
  - [ ] Events: start_datetime, end_datetime, timezone, all_day, location, recurring_pattern
  - [ ] Projects: start_date, end_date, milestone dates
- [ ] Add cross-entity conflict detection
- [ ] Show real-time updates

### Phase 7: Backend Execution
- [ ] Validation layer (entity-specific rules):
  - [ ] Tasks: dates, times, durations, conflicts
  - [ ] Events: DateTime, timezone, overlaps, conflicts
  - [ ] Projects: date ranges, milestones, task dependencies
- [ ] Cross-entity validation (event/task conflicts, project timeline vs events)
- [ ] Database update transactions (tasks/events/projects tables)
- [ ] Cascade updates:
  - [ ] Tasks: update dependent tasks, project progress
  - [ ] Events: update recurring patterns, related tasks
  - [ ] Projects: update task deadlines, related events
- [ ] Audit logging (with entity_type)

### Phase 8: Analytics
- [ ] Log all interactions (with entity_type)
- [ ] Track acceptance rates per entity type
- [ ] Monitor token usage per entity type
- [ ] Track cross-entity conflicts
- [ ] Setup performance dashboard

---

## Common Pitfalls to Avoid

❌ **Don't:** Send all 100 tasks/events/projects to LLM
✅ **Do:** Send 5-10 most relevant entities per type

❌ **Don't:** Use free-form LLM responses
✅ **Do:** Force structured JSON output with entity_type

❌ **Don't:** Execute recommendations without user approval
✅ **Do:** Show user first, get approval, then execute

❌ **Don't:** Hide the LLM reasoning
✅ **Do:** Show step-by-step logic transparently

❌ **Don't:** Call LLM for every input
✅ **Do:** Classify first (intent + entity), route intelligently

❌ **Don't:** Ignore LLM failures
✅ **Do:** Implement fallback rules

❌ **Don't:** Use high temperature (>0.7)
✅ **Do:** Use temperature 0.3 for consistency

❌ **Don't:** Skip logging/monitoring
✅ **Do:** Log everything for continuous improvement

❌ **Don't:** Ignore cross-entity conflicts
✅ **Do:** Check event/task conflicts, project timeline vs events

❌ **Don't:** Treat all entity types the same
✅ **Do:** Use entity-specific prompts, validation, and display logic

❌ **Don't:** Forget recurring patterns
✅ **Do:** Handle recurring_tasks and recurring_events properly

---

## Success Metrics

### User Experience
- Workflow completes in <5 seconds
- Recommendation is understood immediately
- User satisfaction >80% (accept rate >80%)
- Mobile-friendly, no scroll needed

### System Performance
- Intent classification: <10ms
- Context prep: <150ms
- LLM inference: <3s (acceptable)
- Total: <4 seconds

### LLM Quality
- Output is valid JSON 99%+ of time
- Reasoning is transparent and logical
- Confidence scores are calibrated
- Accuracy improves over time (feedback loop)

### Business Metrics
- User task completion rate +30%
- User event scheduling efficiency +40%
- Project timeline accuracy +25%
- Time spent scheduling -70% (across all entity types)
- Error rate in scheduling -50%
- Cross-entity conflict detection accuracy >95%
- Users adopt feature within first week

---

## Next Steps (Roadmap)

### Phase 1 (Current)
- [ ] Implement basic scheduling:
  - [ ] schedule_task
  - [ ] schedule_event
  - [ ] schedule_project
- [ ] Implement basic prioritization:
  - [ ] prioritize_tasks
  - [ ] prioritize_events
  - [ ] prioritize_projects

### Phase 2
- [ ] Add dependency resolution (resolve_dependency - cross-entity)
- [ ] Add adjustment intents:
  - [ ] adjust_task_deadline
  - [ ] adjust_event_time
  - [ ] adjust_project_timeline
- [ ] Cross-entity conflict detection

### Phase 3
- [ ] Multi-user collaboration (team scheduling)
- [ ] Recurring patterns:
  - [ ] Recurring tasks (recurring_tasks, task_instances)
  - [ ] Recurring events (recurring_events, event_instances)
- [ ] Time zone intelligence (events)
- [ ] Project milestone tracking

### Phase 4
- [ ] ML-based effort estimation (tasks)
- [ ] Predictive scheduling (predict completion for tasks/projects)
- [ ] Calendar integration (events)
- [ ] Cross-entity optimization (unified calendar view)

### Phase 5
- [ ] Cross-workspace dependencies
- [ ] Resource allocation (across projects)
- [ ] Burndown projections (projects)
- [ ] Advanced recurring pattern management

---

## Reference Links & Resources

### Local LLM
- Ollama: https://ollama.ai
- Hermes 2 Model: huggingface.co/NousResearch/Hermes-2-Pro
- LocalAI Alternative: https://localai.io

### Frameworks
- Laravel 12: https://laravel.com/docs/12.x
- Livewire 3: https://livewire.laravel.com/docs/3.x
- PrismPHP: https://prismphp.com (advanced, optional)

### Prompt Engineering
- Anthropic Prompt Guide: https://docs.anthropic.com/claude/reference/prompt-engineering
- OpenAI Best Practices: https://platform.openai.com/docs/guides/prompt-engineering
- Few-shot Learning: Common prompt patterns

### Performance
- Measure token usage per request
- Monitor inference latency
- Track user acceptance rate
- Log error rates

---

## Questions to Ask During Implementation

1. **Intent Classification**: Am I covering all user intents? Am I detecting entity types correctly? What edge cases am I missing?
2. **Context**: Is my context minimal enough? Am I including cross-entity context when needed? Could I remove any fields?
3. **Prompting**: Is my system prompt clear for each entity type? Does Hermes 3 understand the differences between tasks, events, and projects?
4. **Output**: Is JSON always valid with entity_type? What happens when it's not?
5. **Display**: Can a first-time user understand the recommendation? Is the entity-specific UI clear?
6. **User Action**: Is the accept/modify/reject flow intuitive? Are entity-specific modification fields clear?
7. **Backend**: Am I validating all inputs before database writes? Am I checking cross-entity conflicts?
8. **Logging**: Am I capturing enough data to improve the system? Am I tracking metrics per entity type?
9. **Recurring Patterns**: Am I handling recurring_tasks and recurring_events correctly?
10. **Relationships**: Am I respecting entity relationships (tasks in projects, tasks in events)?

---

## Conclusion

This workflow balances **intelligence (LLM reasoning) with control (user approval)** and **speed (lightweight, minimal context) with quality (structured output, transparent reasoning)** across **tasks, events, and projects**.

The key insight: **LLM should augment, not replace, your business logic.**

- Use LLM for what it's good at: understanding context, ranking options, detecting patterns across entity types
- Use rules for what's critical: validation, safety, non-negotiable constraints, cross-entity conflict detection
- Use user for what only they can do: making final decisions, handling exceptions, managing complex relationships

**Multi-Entity Considerations:**
- Each entity type (tasks, events, projects) has unique characteristics and requires tailored handling
- Cross-entity relationships must be respected (tasks in projects, tasks in events, project timelines vs events)
- Recurring patterns add complexity but are essential for real-world use cases
- Entity-specific prompts, validation, and display logic ensure accuracy and user understanding

This creates a **trustworthy, performant, scalable system** that users will actually adopt and benefit from across all their task, event, and project management needs.

Good luck with your thesis! 🚀