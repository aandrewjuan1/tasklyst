# Task Assistant Manual UI Test Guide

This guide is for manual testing of the Task Assistant chat UI using realistic student prompts.

Scope:
- End users: students
- Main capability under test: prioritization + scheduling + multiturn continuity
- Dataset baseline: `StudentLifeSampleSeeder` (student account `andrew.juan.cvt@eac.edu.ph`)
- Expected-output strictness: strict on flow/schema/correctness, flexible on wording

## How to use this guide

For each case:
1. Open the chat flyout and start a fresh thread (unless the case says multiturn continuation).
2. Send the exact prompt(s).
3. Validate:
   - **Flow** (`general_guidance`, `prioritize`, `schedule`, or clarification behavior)
   - **Correctness** (ranking/filtering/selection logic)
   - **UI behavior** (chips/proposals/status updates)
4. Treat the provided “good output example” as a **quality target**, not exact wording.

---

## Section 1 - General Queries And Out-Of-Scope Queries

These should mostly route to `general_guidance`.

### Case 1.1 - Greeting only
- Prompt: `hey`
- Expected flow: `general_guidance` (`friendly_general`)
- Checks:
  - Response is supportive and task-oriented.
  - No task list or schedule is forced immediately.
  - It asks user to choose between prioritize vs schedule (in guidance section).
- Good output example:
  - `Hi, I'm TaskLyst-your task assistant. I can help you get organized. I can either prioritize your tasks or schedule time blocks for them. Which one do you want to do first?`

### Case 1.2 - Vague help request
- Prompt: `i'm overwhelmed, help me`
- Expected flow: `general_guidance` (`friendly_general`)
- Checks:
  - Empathetic but concise tone.
  - No invented task details.
  - Ends with clear two-option next step (prioritize/schedule).
- Good output example:
  - `I hear you-this can feel heavy. We can make this manageable one step at a time. I can prioritize your tasks first or schedule time blocks for them. Which should we do first?`

### Case 1.3 - Gibberish
- Prompt: `asdkjzxqwe`
- Expected flow: `general_guidance` (`gibberish_unclear`)
- Checks:
  - Includes one short clarifying question.
  - Avoids random task/schedule assumptions.
- Good output example:
  - `I did not fully understand what you meant yet.`
  - `Can you rephrase your request in one short sentence?`

### Case 1.4 - Off-topic personal question
- Prompt: `who is the best ufc fighter of all time?`
- Expected flow: `general_guidance` (`off_topic`)
- Checks:
  - Brief boundary: assistant stays in task-management domain.
  - Redirects to task-help options.
- Good output example:
  - `I can't help with that unrelated topic. I'm focused on helping you manage tasks and study workload. I can prioritize your tasks or schedule time blocks for them-which do you want first?`

### Case 1.5 - Time query
- Prompt: `what time is it right now?`
- Expected flow: `general_guidance` (time-query handling)
- Checks:
  - Mentions current time for user context.
  - Still redirects to task-focused next step.
- Good output example:
  - `Right now, it's <time> for you. If you want, I can prioritize your tasks or schedule time blocks next-which one first?`

### Case 1.6 - Emotional but non-task domain
- Prompt: `my partner left me and i feel so sad`
- Expected flow: `general_guidance` (`off_topic` style boundary)
- Checks:
  - Gentle acknowledgment + boundary.
  - No therapy-style deep advice.
  - Redirects back to task assistance.
- Good output example:
  - `I'm sorry you're feeling this way. I can't help with personal relationship advice, but I can help you get unstuck with your tasks. Want me to prioritize your tasks or build a time-block schedule first?`

---

## Section 2 - Prioritization + Scheduling Multiturn

This section has 3 parts, as requested.

### Part A - Normal tasks prioritization into scheduling

#### Case 2A.1 - Classic top priorities then schedule
- Turn 1: `what should i do first today?`
- Turn 2: `schedule those`
- Expected flow:
  - Turn 1 -> `prioritize`
  - Turn 2 -> `schedule` (uses selected entities from prior listing)
- Correctness checks:
  - Turn 1 list favors overdue + urgent/high tasks from seeded data (for example the overdue stress-test task should rank very high).
  - Turn 2 schedules the same referenced items, not unrelated tasks.
- Good output example (Turn 1 excerpt):
  - `1. Impossible 5h study block before quiz ... overdue ...`
  - `2. ITCS 101 - Midterm Project Checkpoint ... urgent ...`
- Good output example (Turn 2 excerpt):
  - Time-block plan for those selected items + schedule proposal cards.

#### Case 2A.2 - Ask for next slice then schedule
- Turn 1: `show my top tasks`
- Turn 2: `show next 3`
- Turn 3: `schedule them`
- Expected flow: `prioritize` -> `prioritize` (followup) -> `schedule`
- Correctness checks:
  - Turn 2 returns unseen items only.
  - Turn 3 resolves `them` against latest shown list.
- Good output example:
  - Turn 2: `Here are your next 3 priorities...` with three unseen numbered items.
  - Turn 3: schedule response includes proposal cards tied to those three items.

#### Case 2A.3 - Prioritize first then morning scheduling
- Turn 1: `prioritize my tasks`
- Turn 2: `schedule those in the morning`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Scheduling hints prefer morning window behavior.
- Good output example:
  - `From 8:30 AM-10:00 AM you'll work on ...` style blocks, plus proposals.

#### Case 2A.4 - Prioritize then afternoon scheduling
- Turn 1: `give me top 3 priorities`
- Turn 2: `schedule those for later afternoon`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Afternoon-oriented block times or proposal windows.
- Good output example:
  - `From 3:00 PM-4:30 PM...` / `From 4:30 PM-6:00 PM...` pattern for selected items.

### Part B - 1 task prioritization into scheduling

#### Case 2B.1 - Single item from start
- Turn 1: `what should i do first`
- Turn 2: `schedule this`
- Expected flow: `prioritize` (`count_limit` effectively 1) -> `schedule`
- Correctness checks:
  - First response is singular top focus.
  - Second response schedules that exact single target.
- Good output example:
  - Turn 1: one top item shown first with clear reason (`overdue`, `urgent`, or `due today`).
  - Turn 2: one primary schedule proposal targeting that item.

#### Case 2B.2 - Single filtered by keyword
- Turn 1: `what coding task should i do first?`
- Turn 2: `schedule this tonight`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Candidate should be coding-related seeded tasks (for example CS 220 lab or coding interview practice, based on urgency).
  - Scheduling uses evening preference.
- Good output example:
  - `Top coding focus: CS 220 - Lab 5: Linked Lists` (or another coding task that is more urgent), then evening blocks.

#### Case 2B.3 - Single school draft task
- Turn 1: `pick one writing task i should finish first`
- Turn 2: `schedule this for tomorrow morning`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Writing/ENG-type task selection should be reasonable given due urgency.
- Good output example:
  - Prioritize chooses a writing-related item like `ENG 105 - Draft 2: Comparative Essay`, then schedules morning focus block(s).

### Part C - Filtered prioritization into scheduling (school/chores)

#### Case 2C.1 - School-only then schedule
- Turn 1: `prioritize my school related tasks only`
- Turn 2: `schedule those`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Prioritize output should avoid household-only chores like `Wash dishes after dinner`.
  - Should include coursework-style tasks (ITCS/MATH/CS/ENG/ITEL).
- Good output example:
  - Top list includes school subject tasks only; no household chores in listed items.

#### Case 2C.2 - Chores-only then schedule
- Turn 1: `show me chores i should do first`
- Turn 2: `schedule them tonight`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Output should focus on household/health recurring tasks (`Wash dishes`, `Walk 10k steps`, `Prepare tomorrow's school bag`).
  - Scheduling should target those chores, not random school tasks.
- Good output example:
  - Prioritize list starts with chores/health tasks, then schedule assigns tonight slots for those chores.

#### Case 2C.3 - Exam-focused then schedule
- Turn 1: `prioritize exam related tasks`
- Turn 2: `schedule those tomorrow`
- Expected flow: `prioritize` -> `schedule`
- Correctness checks:
  - Includes quiz/exam-tagged seeded tasks (for example MATH quiz/exam submissions).
- Good output example:
  - Prioritize output highlights exam/quiz items, then schedule places them into tomorrow-focused study blocks.

---

## Section 3 - Automatic Scheduling With Prioritization Combo (No Multiturn)

User asks schedule directly, but the assistant should internally use prioritization logic to pick what to schedule.

### Case 3.1
- Prompt: `schedule my most important task`
- Expected flow: `schedule`
- Correctness checks:
  - Scheduled target should align with top-priority logic (urgent/overdue should dominate).
- Good output example:
  - Schedule centers on `Impossible 5h study block before quiz` or the highest urgency equivalent from current seeded ranking.

### Case 3.2
- Prompt: `plan my day around the most urgent school task`
- Expected flow: `schedule`
- Correctness checks:
  - School-focused top urgent item is selected and scheduled.
- Good output example:
  - Blocks focus on school coursework item(s), not household chores.

### Case 3.3
- Prompt: `schedule what i should do first this week`
- Expected flow: `schedule`
- Correctness checks:
  - Selection should reflect this-week urgency ranking.
- Good output example:
  - Schedule favors items due soon this week and explains sequencing briefly.

### Case 3.4
- Prompt: `time block my highest priority coding task tonight`
- Expected flow: `schedule`
- Correctness checks:
  - Coding-related high-priority target selected, evening-oriented scheduling.
- Good output example:
  - Evening time blocks for coding task(s) such as CS 220 lab/interview practice.

---

## Section 4 - Reference Resolution And Multiturn Memory

### Case 4.1 - Explicit top N reference
- Turn 1: `show my top 5 tasks`
- Turn 2: `schedule top 2`
- Expected:
  - Turn 2 schedules the first two items from previous listing.
- Good output example:
  - Turn 2 proposals match exactly item #1 and #2 from prior list.

### Case 4.2 - Explicit last N reference
- Turn 1: `show my top 5 tasks`
- Turn 2: `schedule last 2`
- Expected:
  - Turn 2 schedules only last two from that list.
- Good output example:
  - Turn 2 proposals match exactly trailing two listed items.

### Case 4.3 - Pronoun reference
- Turn 1: `show my top 3 tasks`
- Turn 2: `schedule those`
- Expected:
  - `those` resolves to prior listing entities.
- Good output example:
  - Scheduling output references and plans the previously shown set only.

### Case 4.4 - After schedule, pronoun remains schedule-context aware
- Turn 1: `show my top 3 tasks`
- Turn 2: `schedule those`
- Turn 3: `reschedule them for evening`
- Expected:
  - Turn 3 should refer to the previously scheduled targets (not stale unrelated listing).
- Good output example:
  - Turn 3 updates timing/window for same scheduled targets without swapping to unrelated tasks.

---

## Section 5 - Clarification And Routing Safety

### Case 5.1 - Ambiguous help then choose prioritize
- Turn 1: `help me i dont know where to start`
- Turn 2: `prioritize`
- Expected:
  - Turn 1 gives guidance.
  - Turn 2 forces prioritize path cleanly.
- Good output example:
  - Turn 1 supportive guidance; Turn 2 returns ranked task list with clear top item.

### Case 5.2 - Ambiguous help then choose schedule
- Turn 1: `can you help`
- Turn 2: `schedule my day`
- Expected:
  - Turn 1 guidance.
  - Turn 2 schedule flow.
- Good output example:
  - Turn 2 provides schedule blocks/proposals instead of another generic guidance-only response.

### Case 5.3 - Clarify schedule scope
- Turn 1: `schedule those`
- If clarification appears, Turn 2: `fresh plan for whole day`
- Expected:
  - System should interpret this as whole-day planning, not only previous selected subset.
- Good output example:
  - Response includes broad day plan blocks, not only one or two previously selected tasks.

---

## Section 6 - Schedule Proposal Card UI Actions

Use any schedule response with visible proposal cards.

### Case 6.1 - Accept proposal
- Action: Click **Accept** on one proposal.
- Expected:
  - Proposal status updates to `accepted`.
  - Corresponding entity update applies (task/event/project time updates).

### Case 6.2 - Decline proposal
- Action: Click **Decline** on one proposal.
- Expected:
  - Proposal status updates to `declined`.
  - No apply update for that declined proposal.

### Case 6.3 - Mixed accept/decline
- Action: Accept one, decline one.
- Expected:
  - Independent status tracking per proposal.

---

## Quick pass/fail checklist

- Prioritization:
  - Urgent/overdue/near-deadline items rank above lower-risk items.
  - Filters (school/chores/exam/keyword/time) are respected.
- Scheduling:
  - Produces coherent blocks/proposals.
  - Uses referenced entities in multiturn prompts (`those`, `top 2`, `last 2`).
- General guidance:
  - Friendly for valid prompts, bounded for off-topic, clarifying for gibberish.
- UI:
  - Prioritize chips appear on latest assistant message.
  - Schedule proposals support accept/decline and status updates.

---

## Notes for manual testers

- Do not fail a case only due to wording differences if logic/schema behavior is correct.
- Fail a case when flow routing is wrong, filtering/ranking is wrong, or multiturn references break.
- Re-run failing cases in a fresh chat thread to isolate state carry-over effects.
