# LLM Prioritization UI Test Prompts (Manual)

This file contains 5 realistic student-user prompts for the **prioritization intent** and the **expected UI output**.

## Important setup note
The `task_choice` flow deterministically selects the focus item (task/event/project), and deterministically generates the `steps` + the line:
`The task I'm referring to is "<title>".`

The `suggestion` + `reason` come from the LLM. To make this test deterministic, the expected outputs below use the **LLM failure fallback** text (so you can compare reliably). If the LLM succeeds, your `suggestion`/`reason` may differ, but the task title line + steps should still match.

Also, bucket words like `due today` are time-sensitive. For best results, set all `ends_at` for “due today” tasks to a late time (e.g. `23:59`) so they don’t become `overdue` while you test.

## Assumed test tasks (must exist and match these titles)
Create these tasks with `status = to_do` and the specified `priority`, `duration_minutes`, and `ends_at` date:

1. `Math practice set` — `priority=urgent`, `ends_at=today 23:00`, `duration_minutes=25`
2. `Writing essay introduction` — `priority=high`, `ends_at=tomorrow 10:00`, `duration_minutes=60`
3. `Writing lab report summary` — `priority=high`, `ends_at=today + 6 days 16:00`, `duration_minutes=25`
4. `Review study notes` — `priority=medium`, `ends_at=tomorrow 09:00`, `duration_minutes=90`
5. `Study group agenda` — `priority=medium`, `ends_at=today + 3 days 18:00`, `duration_minutes=30`
6. `Coding optional project` — `priority=low`, `ends_at=today + 3 days 19:00`, `duration_minutes=200`
7. `Coding debugging drills` — `priority=low`, `ends_at=today + 5 days 19:00`, `duration_minutes=30`
8. `Reading chapter outline` — `priority=urgent`, `ends_at=today 23:30`, `duration_minutes=15`
9. `Reading flashcards` — `priority=low`, `ends_at=today 21:00`, `duration_minutes=30`

## Test 1: Urgent math, today
### Prompt
```text
I'm trying to get ahead in math. Which task should I do first? Make it urgent for today.
```

### Expected UI (exact fallback text)
```text
Focus on 'Math practice set' next. This Urgent priority task is due today and matches your specific request.

This task was selected because it's Urgent priority and due today, and it matches your request for urgent priority tasks, and it relates to your interest in math.

The task I'm referring to is "Math practice set".

Start by open 'Math practice set' and list the first 2-3 concrete actions you can do right away, then block 20 focused minutes for 'Math practice set' before the end of today, then treat this as your top priority and delay lower-priority tasks until this block is done, and finally after the block, record progress and decide the next step for 'Math practice set'.
```

## Test 2: High writing, this week
### Prompt
```text
Help me prioritize my writing this week. Which one is the high priority to do next?
```

### Expected UI (exact fallback text)
```text
Focus on 'Writing essay introduction' next. This High priority task is due tomorrow and matches your specific request.

This task was selected because it's High priority and due tomorrow, and it matches your request for high priority tasks, and it relates to your interest in writing.

The task I'm referring to is "Writing essay introduction".

Start by open 'Writing essay introduction' and list the first 2-3 concrete actions you can do right away, then start 'Writing essay introduction' today with a 30-minute kickoff so tomorrow is easier, then handle this before medium/low tasks to keep your deadlines under control, and finally after the block, record progress and decide the next step for 'Writing essay introduction'.
```

## Test 3: Medium study + review (no explicit day)
### Prompt
```text
Which is the most important next step for my study and review? I want something medium priority.
```

### Expected UI (exact fallback text)
```text
Focus on 'Review study notes' next. This Medium priority task is due tomorrow and matches your specific request.

This task was selected because it's Medium priority and due tomorrow, and it matches your request for medium priority tasks, and it relates to your interest in study, review.

The task I'm referring to is "Review study notes".

Start by open 'Review study notes' and list the first 2-3 concrete actions you can do right away, then start 'Review study notes' today with a 30-minute kickoff so tomorrow is easier, then finish this focus block first, then reassess what to do next, and finally after the block, record progress and decide the next step for 'Review study notes'.
```

## Test 4: Low coding, this week
### Prompt
```text
Choose my low priority coding task for this week. What should I do next?
```

### Expected UI (exact fallback text)
```text
Focus on 'Coding optional project' next. This Low priority task is due this week and matches your specific request.

This task was selected because it's Low priority and due this week, and it matches your request for low priority tasks, and it relates to your interest in coding.

The task I'm referring to is "Coding optional project".

Start by open 'Coding optional project' and list the first 2-3 concrete actions you can do right away, then schedule 'Coding optional project' in your next available 60-minute focus block this week, then finish this focus block first, then reassess what to do next, and finally after the block, record progress and decide the next step for 'Coding optional project'.
```

## Test 5: “Today” + reading keyword (no explicit priority word)
### Prompt
```text
What should I focus on today? I need reading time.
```

### Expected UI (exact fallback text)
```text
Focus on 'Reading chapter outline' next. This Urgent priority task is due today and matches your specific request.

This task was selected because it's Urgent priority and due today, and it relates to your interest in reading.

The task I'm referring to is "Reading chapter outline".

Start by open 'Reading chapter outline' and list the first 2-3 concrete actions you can do right away, then block 20 focused minutes for 'Reading chapter outline' before the end of today, then treat this as your top priority and delay lower-priority tasks until this block is done, and finally after the block, record progress and decide the next step for 'Reading chapter outline'.
```

