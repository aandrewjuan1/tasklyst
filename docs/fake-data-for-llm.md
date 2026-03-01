# Task Assistant Test Dataset Guide

**For Laravel 12 Seeder + Factory Testing**

This document defines structured fake data you should seed into your database to stress-test an AI task assistant system that handles:

* task prioritization
* scheduling
* event planning
* deadline reasoning
* constraint validation
* ambiguity handling

The goal is to simulate real-world messy user data, not clean demo data.

---

# 1. Core Design Principle

Do **NOT** seed only clean data.

Real users have:

* conflicting deadlines
* duplicate tasks
* vague titles
* missing durations
* impossible schedules
* outdated entries
* overlapping meetings

Your dataset should intentionally include these.

---

# 2. Suggested Tables + Edge Cases

---

## USERS

Create multiple user profiles with different productivity styles.

Examples:

| Name   | Behavior Type      |
| ------ | ------------------ |
| Alex   | Overbooker         |
| Sam    | Procrastinator     |
| Jamie  | Structured planner |
| Taylor | Chaotic            |
| Jordan | Minimalist         |

Seeder logic idea:

* some users → 50 tasks
* some → 3 tasks
* some → none

---

## TASKS

Include realistic + problematic tasks.

### Normal Tasks

```
Finish report — 2h — due tomorrow
Send email — 15m — today
Prepare slides — 1h — Friday
```

---

### Edge Case Tasks

Include these intentionally:

#### Missing fields

```
Title: "Project thing"
Duration: null
Deadline: null
```

#### Impossible deadlines

```
Duration: 5h
Deadline: in 2 hours
```

#### Duplicate tasks

```
Submit proposal
Submit proposal
Submit proposal
```

#### Conflicting priorities

```
Task A — priority 1
Task B — priority 1
Task C — priority 1
```

#### Vague tasks

```
Fix stuff
Work on project
Do the thing
Important task
```

---

## EVENTS / CALENDAR

### Valid events

```
Meeting 9:00–10:00
Lunch 12:00–13:00
Gym 18:00–19:00
```

---

### Edge case events

#### Overlapping

```
Meeting 1:00–2:00
Call 1:30–2:30
```

#### Back-to-back edge

```
Task 10:00–11:00
Task 11:00–12:00
```

#### Impossible

```
Start 3:00 PM
End 2:00 PM
```

#### Ultra-short

```
Duration: 2 minutes
```

#### Multi-day

```
Hackathon — 36 hours
```

---

## PROJECTS

Include variety:

| Project       | Deadline  | Complexity |
| ------------- | --------- | ---------- |
| Website       | tomorrow  | high       |
| App redesign  | 3 months  | high       |
| Notes cleanup | none      | low        |
| Migration     | yesterday | high       |

Edge cases:

* deadline already passed
* no deadline
* 10+ tasks linked
* zero tasks linked

---

## CONTEXT MEMORY (if you store embeddings / notes)

Seed stored context like:

```
User prefers mornings
User works best after coffee
User hates meetings
User is on vacation May 10
User has ADHD
User works night shift
```

Include contradictions:

```
Prefers mornings
Prefers late nights
```

---

# 3. Seeder Scenarios to Generate

Your factories should generate *patterns*, not just rows.

---

## Scenario A — Overloaded Day

User has:

* 12 tasks
* 6 meetings
* only 4 free hours

Expected AI behavior:
→ prioritization suggestions

---

## Scenario B — Empty Schedule

User has:

* zero tasks
* zero events

Expected AI behavior:
→ ask what to plan

---

## Scenario C — Contradiction User

User data contains:

* 2 different deadlines for same project
* 2 different priorities

Expected AI behavior:
→ detect inconsistency

---

## Scenario D — Impossible Planning

User asks scheduling with:

```
5 tasks × 2h each
Free time = 3h
```

Expected AI behavior:
→ say impossible

---

---

# 4. Factory Generation Ratios

Use weighted randomness.

Recommended distribution:

| Data Type   | Percentage |
| ----------- | ---------- |
| Clean       | 40%        |
| Messy       | 30%        |
| Conflicting | 15%        |
| Incomplete  | 15%        |

Real users ≠ perfect data.

---

---

# 5. Seeder Difficulty Levels

Add environment flag:

```
TEST_AI_LEVEL=easy
TEST_AI_LEVEL=realistic
TEST_AI_LEVEL=nightmare
```

---

### EASY

* clean data only

### REALISTIC

* mix of everything

### NIGHTMARE (best for testing)

Includes:

* duplicate tasks
* null fields
* conflicts
* impossible times
* outdated deadlines
* vague tasks

---

---

# 6. Golden Test Users (must include)

Create these specific seeded users:

---

### 1. "The Impossible Planner"

Has:

* impossible schedule
* overlapping meetings
* 20 urgent tasks

---

### 2. "The Ghost"

Has:

* no tasks
* no events
* no projects

---

### 3. "The Contradiction"

Has:

* same task with different deadlines
* conflicting priorities

---

### 4. "The Realistic Human"

Has:

* some overdue tasks
* some future tasks
* vague notes
* incomplete entries

---

---

# 7. Validation Queries You Should Run Against Dataset

Use these manually:

```
What should I work on first?
Plan my day
What is overdue?
Do I have conflicts?
What should I postpone?
```

If your pipeline is correct:

* answers differ per user
* no hallucinated tasks
* detects impossible schedules

---

---

# 8. Pro Tip

If your dataset is too clean:

> your AI will look smart in testing but fail in production

The messier your seed data, the more reliable your system.

---

---

# Final Goal

Your seed data should simulate:

> a stressed human with messy productivity habits

Not:

> a perfectly organized demo account
