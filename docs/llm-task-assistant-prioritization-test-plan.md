# Task Assistant Prioritization Flow - UI Test Plan

This document is designed for manual UI verification of the TaskLyst “prioritize” module, focusing on how user prompts affect:

- Routing into `prioritize` (ranking vs list-mode)
- Optional narrative fields (acknowledgment + insight) within the **prioritize** flow
- Follow-up reference resolution like “schedule those / the top 3”

## What to verify in the UI (quick checklist)

When you send a prompt, verify:

- A streamed assistant response completes (no stuck/empty assistant content)
- The assistant’s rendered text follows the prioritize message layout (paragraph order + list formatting)
- Optional sections appear or do not appear as expected:
  - Acknowledgment paragraph appears only for “emotion/tone” triggers
  - `Insight:` paragraph appears only when top items differ in due/priority or include non-task entities

## Assumptions (test data / setup you control)

Because prioritize item membership/order comes from backend ranking/selection, the assistant’s **acknowledgment** and **insight** visibility depends on:

1. **Prompt content** (emotion/tone keywords trigger acknowledgment logic)
2. **Resulting top items** (top 3 rows determine insight inclusion)

Use these setups to make results deterministic:

### Setup A: Insight OFF (top 1 and top 2 match)

Create/arrange tasks so that the **top 2 items** returned to the prioritization narrative layer have:

- Same due bucket/relative due timing (e.g., both due today, or both “no due date”)
- Same priority label (e.g., both `high`, or both `medium`)
- And ensure the **top 3** items are all tasks (no events/projects in top 3)

### Setup B: Insight ON (top 1 and top 2 differ)

Create/arrange tasks so that **top 1 vs top 2** differ by either:

- Due bucket/relative due timing (e.g., top 1 due today, top 2 due tomorrow), and/or
- Priority label (e.g., top 1 high, top 2 medium)

Optional: include an event/project in the top 3 if you want to force “non-task in top 3” insight.

### Setup C: List-mode candidate pool (for list-show prompts)

Your list-mode routing is triggered by the presence of list/show language.
To verify it, compare list-mode vs ranking-mode output behavior using:

- same task data (use Setup A or B)
- prompts that differ only by list/show wording vs prioritize wording

## Expected rendered output format (prioritize flow)

The UI message renderer formats prioritize responses as:

1. (Optional) acknowledgment paragraph
2. framing paragraph
3. numbered list of items (tasks show `priority`, due info, complexity; events/projects show “— Event/Project”)
4. (Optional) `Insight: ...` paragraph
5. reasoning paragraph
6. next actions paragraph:
   - exactly starts with `I recommend ...`
   - contains numbered steps like `1. ...` and `2. ...` on separate lines
7. next options sentence (commonly `If you want, I can schedule these steps for later.`)

### Placeholders used below

- `<Task1Title>`, `<Task2Title>`, `<Task3Title>`: titles shown in the item list
- `<PriorityLabel>`: e.g. `High`, `Medium`, etc. as rendered by the UI
- `<DuePhrase>`: e.g. `due today`, `no due date`, `overdue`, etc. (as your row generator produces)
- `<DueOnOrDash>`: either `—` or a rendered date string
- `<ComplexityLabel>`: e.g. `Not set` or your complexity label
- `<Event1Title>` / `<Project1Title>`
- For follow-up scheduling:
  - `<Block1Start–End>`, `<Block1Label>`
  - `<ReasoningSummary>`, `<StrategyPoints>`, `<NextSteps>`

> Note: Exact wording inside framing/reasoning/suggested steps is LLM-dependent.
> These “expected ideal outputs” focus on structure and required/optional sections.

## UI execution steps (recommended)

1. Start with a new thread (or clear prior state if your UI preserves last listing)
2. Ensure tasks/events match Setup A or B above
3. Send the prompts below in order and compare the rendered assistant output to expectations
4. For follow-up tests, send a second message in the same thread (to test “those/the top 3” references)

---

## Section 1: Default prioritize schema (ack OFF, insight OFF)

Precondition:

- Use **Setup A** (top 1/top 2 match; top 3 are all tasks)
- Use neutral prompts that do not include acknowledgment-trigger emotion/tone keywords

### Expected output (for each prompt in this section)

You should see:

- No acknowledgment paragraph at the top
- No `Insight:` paragraph
- Framing paragraph
- Numbered list lines for `<Task1Title>`, `<Task2Title>`, `<Task3Title>`
- Reasoning paragraph
- `I recommend ...` paragraph with `1. ...` and `2. ...` steps
- Next options sentence

#### Ideal rendered example (replace placeholders)

`<Framing about how the list/focus helps>`  

`1. <Task1Title> — <PriorityLabel> priority · <DuePhrase> (<DueOnOrDash>) · Complexity: <ComplexityLabel>`  
`2. <Task2Title> — <PriorityLabel> priority · <DuePhrase> (<DueOnOrDash>) · Complexity: <ComplexityLabel>`  
`3. <Task3Title> — <PriorityLabel> priority · <DuePhrase> (<DueOnOrDash>) · Complexity: <ComplexityLabel>`  

`<Reasoning: why this ordering matches the request>`  

`I recommend you take these next steps.`  
`1. <Start with top task and complete one small step>`  
`2. <Then move to the next item and work for a short focused session>`  

`If you want, I can schedule these steps for later.`

### Student prompts (neutral)

1. “What should I do first?”
2. “Prioritize my tasks for me.”
3. “What tasks should I do next?”
4. “Give me the top tasks to focus on.”
5. “Which tasks are most important right now?”
6. “What should I tackle next?”
7. “Rank my tasks by urgency.”
8. “What are my top priorities today?”

## Section 2: Acknowledgment schema (ack ON, insight OFF)

Precondition:

- Use **Setup A** (insight should remain OFF)
- Prompts include “emotion/tone” keywords that should trigger acknowledgment

### Expected output differences vs Section 1

- You should see an acknowledgment paragraph first (non-empty)
- You should still see NO `Insight:` paragraph

#### Ideal rendered example

`<Acknowledgment sentence>`  

`<Framing ...>`  

`<Numbered list lines for top 3 tasks>`  

`<Reasoning ...>`  

`I recommend you take these next steps.`  
`1. ...`  
`2. ...`  

`If you want, I can schedule these steps for later.`

### Student prompts (ack triggers)

Use any of these:

1. “I’m overwhelmed. What should I do first?”
2. “I feel stressed and don’t know where to start.”
3. “I’m stuck—what are my top priorities?”
4. “I’m anxious. Prioritize my tasks, please.”
5. “I’m worried about my workload. What should I tackle next?”
6. “I feel nervous. Help me pick what to work on.”
7. “I’m frustrated—what should I do first?”
8. “I’m panicked. Give me my top tasks to focus on.”

## Section 3: Insight schema (ack OFF, insight ON)

Precondition:

- Use **Setup B** (insight should be ON because top items differ by due and/or priority)
- Prompts are neutral (no overwhelm/stress triggers)

### Expected output differences vs Section 1

- No acknowledgment paragraph
- You MUST see a paragraph that starts with `Insight:`
- Items should remain the numbered list lines for the top tasks

#### Ideal rendered example

`<Framing ...>`  

`<Numbered list lines>`  

`Insight: <one-sentence insight>`  

`<Reasoning ...>`  

`I recommend ...`  
`1. ...`  
`2. ...`  

`If you want, I can schedule these steps for later.`

### Student prompts (neutral)

1. “What should I do first?”
2. “Prioritize my tasks by importance.”
3. “Give me the top tasks to focus on.”
4. “What are the most urgent tasks?”
5. “Rank my tasks and tell me what matters most.”
6. “Which tasks should I tackle today?”
7. “What should I focus on next?”
8. “Help me choose the next step.”

## Section 4: Acknowledgment + Insight schema (ack ON, insight ON)

Precondition:

- Use **Setup B** (insight ON)
- Use emotion keywords (ack ON)

### Expected output differences

- acknowledgment paragraph first
- includes `Insight: ...`

#### Ideal rendered example

`<Acknowledgment sentence>`  

`<Framing ...>`  

`<Numbered list lines>`  

`Insight: <one-sentence insight>`  

`<Reasoning ...>`  

`I recommend ...`  
`1. ...`  
`2. ...`  

`If you want, I can schedule these steps for later.`

### Student prompts

1. “I’m overwhelmed. What should I do first?”
2. “I’m stressed and need clarity—prioritize my tasks.”
3. “I feel anxious. Help me pick what to work on next.”
4. “I’m worried about everything. What should I tackle first?”
5. “I’m stuck—what are my top priorities?”
6. “I’m panicked. Give me the most urgent tasks.”
7. “I’m frustrated—rank my tasks please.”
8. “I feel nervous. Prioritize what matters most.”

---

## Section 5: List-mode prioritize (same schema contract, different route)

Goal:

Verify that “list/show” prompts still produce the same prioritize message contract (items + framing/reasoning/next steps),
but are routed through list-mode selection.

Precondition:

- Use **Setup A** or **Setup B** depending on whether you want insight on/off
- Ensure prompts do NOT include the words `prioritize` or `focus` (or else list-mode routing may be disabled)

### Expected output

Same structural expectations as Sections 1–4, depending on whether your data triggers ack/insight:

- Acknowledgment should appear only if your prompt includes emotion keywords
- `Insight:` should appear only if your resulting top items differ (Setup B) or include non-task entities

### Student prompts (list-mode triggers)

1. “Show me all my tasks.”
2. “List my tasks for me.”
3. “Give me my tasks.”
4. “What tasks do I have?”
5. “Display my tasks.”
6. “Show all tasks.”
7. “Give me tasks.”
8. “Show my tasks.”

> Verification tip: send one list-mode prompt and one ranking-mode prompt back-to-back (same underlying task data).
> The output should look similar, but list-mode should behave like a list/list-filter request rather than a “pure ranking” request.

---

## Section 6: Routing ambiguity checks (prioritize vs schedule vs guidance)

Goal:

Verify the system doesn’t always force a hard `prioritize` route when the user intent is ambiguous.

Precondition:

- Use prompts that look like they might be scheduling/time-related, or might just be general “help”
- Confirm the assistant response is either a clarification question (`clarify`) or general guidance

### Student prompts (likely ambiguity)

1. “I need to get things done this week. What should I do?”
2. “I’m not sure. Should I make a plan or prioritize tasks?”
3. “Help me figure out what to do for today.”
4. “When should I work on stuff?”
5. “I’m overwhelmed—what now?”
6. “What should I do first and when should I do it?”
7. “I don’t know where to start, can you guide me?”
8. “I need a plan.”

### Expected output (ideal)

You should see either:

- A clarification question that matches one of the internal “clarificationQuestion” templates, OR
- A general guidance response (“I can help...”), depending on confidence/threshold behavior

Example ideal clarification question texts (exact match may vary if config changes):

- If it’s resolving to schedule: “Do you want me to build a schedule for selected tasks, or create a fresh plan for your whole day?”
- If it’s resolving to prioritize: “Should I prioritize your top tasks now, or help schedule them on your calendar?”
- Default: “Do you want to list or filter tasks, prioritize what to do next, or schedule time for them?”

---

## Section 7: Follow-up scheduling references (“those / top 3 / the above”)

Goal:

After a successful prioritize response, verify follow-ups correctly resolve which items to schedule.

Precondition:

- Send a prioritize prompt first in the same thread (Sections 1–4)
- Ensure your prior prioritize response produced a non-empty top list
- Then send one of the follow-up prompts below

### Expected flow behavior

- Follow-up should route to `schedule` (not re-run prioritize) when references indicate scheduling intent

### Expected rendered schedule output (ideal structure)

The schedule formatter produces a message with (in order):

1. `<summary>` paragraph (may mention the requested window)
2. `<reasoning>` paragraph
3. block sentence(s) like:
   - “From `<Block1Start–End>` you'll work on `<Block1Label>`”
   - (other blocks similarly, joined)
4. “To make this schedule work, ...” if `strategy_points` exist
5. “Next, ...” if `suggested_next_steps` exist
6. optional “I assumed that ...” if `assumptions` exist
7. “Accept or decline each proposed item ...” if proposals exist

### Follow-up prompts (references)

After you receive a prioritize list:

1. “Schedule those for tomorrow.”
2. “Schedule the top 3 for later afternoon.”
3. “Plan those tasks for the evening.”
4. “Schedule the above for morning.”
5. “Can you put those on my calendar?”
6. “Schedule those for this week—just pick times.”
7. “Schedule the top tasks I mentioned earlier.”
8. “Time-block those for next time I work.”

### What “ideal” should look like in the blocks

In the schedule message, you should see block labels matching the targeted selected items:

- block text should mention `<Task1Title>` / `<Task2Title>` / `<Task3Title>` (or their equivalents)
- it should not invent new tasks that weren’t in the prioritize top list

---

## Optional advanced combinations (extra coverage)

Use these to hit “more combinations” beyond simple ack/insight toggles. For these, reuse Setup A/B.

### Acknowledgment + count-shaping prompts

Send one emotional prompt and adjust count focus by including “top 3”, “top 2”, etc.

1. “I’m overwhelmed—what are my top 3 priorities?”
2. “I’m stressed. Show me my top 2 tasks.”

Expected:

- ack present/absent based on emotion trigger
- number of items should respect the user’s count hint (within app’s clamping rules)
- insight behavior based on Setup A/B

### List-mode + emotion

Use list-mode prompts plus emotion wording:

1. “Show me my tasks—I’m overwhelmed.”
2. “List my tasks. I feel anxious.”

Expected:

- list-mode route
- ack appears
- insight based on Setup A/B

---

## Traceability to code triggers (sanity check)

This section is for your own verification while you test:

- Acknowledgment trigger: `TaskAssistantHybridNarrativeService::detectPrioritizeUxIncludes()` (emotion regex)
- Insight trigger: same method, based on top-items due/priority differences or non-task presence
- List-mode routing: `TaskAssistantService::shouldUsePrioritizeTaskListMode()` (regex for list/show + exclusion of `prioritize|focus`)
- Prioritize flow message contract: `TaskAssistantMessageFormatter::formatPrioritizeListingMessage()`
- Prioritize schema contract: `TaskAssistantSchemas::prioritizeNarrativeSchema()` and runtime validation in `TaskAssistantResponseProcessor::validatePrioritizeListingData()`

