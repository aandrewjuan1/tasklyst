## TaskLyst LLM Assistant — Chat UI/UX Guidelines

This document captures the design guidelines for building the **Hermes 3B (hermes3:3b) task assistant UI** on top of the existing Laravel + Prism + Ollama backend. The goal is a **small, focused chat UI** embedded in a **Flux flyout modal**.

---

## 1. High-level goals

- **Assist, don’t overwhelm**: The assistant should feel like a light, focused helper for tasks/events/projects, not a full-screen chatbot.
- **Grounded in TaskLyst data**: UI should reflect that answers are based on the user’s tasks, events, and projects (LLM context + structured output), not general-purpose chat.
- **Fast to access, easy to dismiss**: The assistant lives in a **Flux `flyout` modal**, available from anywhere but not blocking the main workflow.

---

## 2. Embedding in a Flux flyout modal

Use a Flux modal with the `flyout` prop to host the chat UI, based on the official Flux docs [`flux:modal flyout`](https://fluxui.dev/components/modal#flyout).

### 2.1. Basic structure

- **Trigger**: A `flux:modal.trigger` (e.g. “Assistant”, “Need help planning?”) in the header or a floating button.
- **Flyout modal**:
  - `flyout` enabled.
  - Position: default right is usually fine for a side assistant.
  - Classes to make it act like a small side panel, e.g. `class="flex flex-col h-full md:w-lg"`.

Conceptual structure inside the modal:

- **Header**:
  - Title, e.g. “TaskLyst Assistant”.
  - Short subtitle, e.g. “Helps you prioritise and schedule tasks, events, and projects.”
- **Messages area**:
  - Scrollable, fills most of the modal height.
  - Shows past messages in a chat-like format.
- **Composer**:
  - Sticks to the bottom.
  - Contains the text input and send button, plus optional helper text.

---

## 2.2. Recommended Flux components for the assistant

To keep the UI consistent with the rest of TaskLyst, prefer Flux components wherever possible:

- **Modal shell**:
  - `flux:modal.trigger` — trigger button to open the assistant flyout.
  - `flux:modal` with `flyout` — the side panel container for the entire chat UI ([docs](https://fluxui.dev/components/modal#flyout)).
- **Header area**:
  - `flux:heading` — assistant title (e.g. “TaskLyst Assistant”).
  - `flux:text` — one-line subtitle describing the assistant’s purpose.
  - Optional `flux:badge` — to show model info (e.g. “Hermes 3B”) or “Beta”.
- **Messages area**:
  - Plain `div`/`section` layout with Tailwind for message bubbles.
  - Optionally wrap special notices (guardrail or rate-limit messages) in a `flux:callout` to visually distinguish them ([docs](https://fluxui.dev/components/callout)).
  - Optional `flux:avatar` next to assistant messages if you want a visual identity.
- **Empty-state + suggested prompts**:
  - `flux:button` with lightweight variants (e.g. `variant="ghost"` or `variant="subtle"`) to render clickable suggestion chips ([docs](https://fluxui.dev/components/button)).
- **Composer (input area)**:
  - `flux:textarea` — multi-line chat input with label/placeholder if needed ([docs](https://fluxui.dev/components/textarea)).
  - `flux:button` — primary send button at the right side of the composer.
  - Optional `flux:text` — tiny helper text under the textarea explaining what to ask.

You can still combine Flux components with Tailwind utility classes to achieve a focused chat design without writing custom low-level HTML for every element.

---

## 3. Chat layout inside the flyout

### 3.1. Zones

- **Header zone** (top):
  - Assistant name + 1-line purpose.
  - Optional small badge (e.g. “Hermes 3B · experimental” if you want to emphasise model).
- **Messages zone** (middle, scrollable):
  - Column of messages, newest at the bottom.
  - Enough padding for readability.
- **Composer zone** (bottom, fixed):
  - Thin border-top.
  - Textarea + send button aligned horizontally.

### 3.2. Message styling

- **User messages**:
  - Right-aligned bubbles.
  - Slightly stronger accent colour to differentiate from assistant.
- **Assistant messages**:
  - Left-aligned.
  - Softer background colour; visually consistent with the rest of TaskLyst.
  - Separate paragraphs for:
    - **Recommended action** (first paragraph).
    - **Reasoning** (second paragraph).
- **Timestamps**:
  - Small, muted text under each bubble or in the corner.

### 3.3. Loading and error states

- **Loading**:
  - When waiting for Hermes, show a small “typing/processing” bubble (three-dot indicator) on the assistant side.
  - Disable the send button while a request is in-flight; you may still allow typing in the input.
- **Errors / guardrails**:
  - Use a different visual treatment (e.g. a subtle warning icon and neutral-colour background) when:
    - The relevance guardrail responds (“I only help with tasks/events/projects…”).
    - The rate limiter responds (“You’ve sent quite a few requests…”).
    - A fallback is used due to LLM errors (optional small label).

---

## 4. Conversation scaffolding (reduce “blank page” problem)

Research on LLM interfaces for education shows that structured, guided interfaces perform better than raw chat for usability and cognitive load. Instead of starting from an empty text box:

- **Empty-state suggestions**:
  - When there are no messages yet (or very few), show **suggested prompts** as clickable chips/buttons, e.g.:
    - “What should I focus on today?”
    - “Show my tasks with no due date.”
    - “Help me plan study time for my exam.”
    - “Which tasks can I drop if I’m overwhelmed?”
- **Short helper text** under the composer:
  - Example: “Ask about tasks, events, or projects. Example: ‘Prioritise my tasks for today.’”

This reduces the need for users to “prompt engineer” and encourages interaction that matches what the backend is tuned for.

---

## 5. Using backend structured output in the UI

The backend returns a `RecommendationDisplayDto` (Phase 6) for each inference, which the UI should use rather than raw model JSON:

- **Core properties**:
  - `message` — combined natural-language reply (recommended action + reasoning, including lists and steps).
  - `recommendedAction` — first paragraph / summary.
  - `reasoning` — explanation paragraph.
  - `intent` / `entityType` — which type of recommendation this is (e.g. prioritise tasks vs schedule event).
  - `validationConfidence` — server-side validation of the structured payload.
  - `usedFallback` / `fallbackReason` — whether a rule-based or generic fallback was used.
  - `structured` — safe subset of structured fields for UI (e.g. `ranked_tasks`, `listed_items`, `next_steps`, `start_datetime`, `end_datetime`, `priority`, `blockers`).

### 5.1. How to render an assistant reply

- **Primary content**:
  - Show `message` as the main text inside the assistant bubble.
  - Keep paragraphs intact; do not collapse them into a single paragraph.
- **Ranked lists (prioritise intents)**:
  - If `structured.ranked_tasks` / `ranked_events` / `ranked_projects` exists:
    - Render a **numbered mini-list** under the main message, e.g.:
      - `#1 Task title (2026-03-12T09:00:00Z)`
      - `#2 Task title (no due date)`
- **Filtered lists (general_query + listed_items)**:
  - If `structured.listed_items` exists, render a **bullet list**, with each item:
    - Title.
    - Optional small badges for priority or date if present.
- **Next steps (resolve_dependency)**:
  - If `structured.next_steps` exists:
    - Subtitle “Next steps” then numbered steps:
      - `1. …`
      - `2. …`
- **Fallback / confidence indicators**:
  - If `usedFallback === true`:
    - Optionally show a small label like “Rule-based suggestion” or “Fallback recommendation” in the corner of the message.
  - If `validationConfidence` is low (e.g. < 0.5):
    - Consider a subtle hint (e.g. “Check details before acting”) but avoid scaring users.

---

## 6. Composer (input area) design

### 6.1. Input behaviour

- **Textarea**:
  - Multi-line (2–3 visible lines) with auto-resize up to a small maximum (3–4 lines).
  - Show placeholder like “Ask about your tasks, events, or projects…”.
- **Keyboard shortcuts**:
  - Enter → send.
  - Shift+Enter → newline.

### 6.2. Controls

- **Primary send button**:
  - Flux button with `variant="primary"`.
  - Right-aligned relative to the input.
- **Disabled state**:
  - Disable send while a request is pending to avoid accidental spamming (you can still allow queueing if you implement that later).

---

## 7. TaskLyst-specific touches

### 7.1. Context awareness

Because the backend builds an intentional context payload (tasks/events/projects + time + conversation history), the UI can reflect that:

- **Context chips** under some messages (optional):
  - E.g. “Looking at tasks due this week” or “Filtered: low priority only”.
  - These can be inferred from `intent`, `entityType`, and the filters the backend detected (date / priority / complexity / recurring / all-day).

### 7.2. Follow-up actions

Under certain replies, you can add **quick action buttons** that bridge to the main app:

- Examples:
  - “Open task list” → navigate to the main tasks view filtered appropriately.
  - “Open calendar” → navigate to the calendar view.
  - “View this project” → open project details when the reply is about a specific project.

These actions should respect the backend’s structured output (e.g. if the assistant lists tasks with no due date, you can open the task list pre-filtered to those).

---

## 8. Behaviour within the Flux flyout

### 8.1. Sizing and scrolling

Inside `<flux:modal flyout>`, use a vertical layout:

- Outer wrapper: `class="flex flex-col h-full"` so the modal content uses all vertical space.
- Header: normal height, fixed at the top.
- Messages: `class="flex-1 overflow-y-auto"` so the chat scrolls independently.
- Composer: fixed at the bottom with a subtle border-top to separate from messages.

### 8.2. Closing and persistence

- Closing the flyout should **not delete the conversation**; it only hides the UI. Assistant threads are already persisted in the backend (`AssistantThread` + `AssistantMessage`).
- Re-opening the modal should:
  - Load the latest thread (if any) for the current user.
  - Scroll to the bottom of the conversation.

---

## 9. Summary checklist

When implementing the frontend chat UI for the Hermes 3B assistant inside a Flux flyout:

- **Integration**:
  - [ ] Use `flux:modal.flyout` with a trigger button and a tall, scrollable panel.
  - [ ] Make the modal content a `flex flex-col h-full` layout (header, messages, composer).
- **Messages**:
  - [ ] Render `RecommendationDisplayDto->message` as the main assistant bubble text.
  - [ ] Use `structured` to show ranked lists, filtered lists, and next steps.
  - [ ] Differentiate user vs assistant bubbles clearly.
  - [ ] Show typing/loading indicator while waiting for a response.
- **Composer**:
  - [ ] Multi-line textarea with sensible placeholder.
  - [ ] Enter to send, Shift+Enter for new line.
  - [ ] Primary send button with clear disabled/loading states.
- **Scaffolding**:
  - [ ] Empty-state suggested prompts (chips) to guide first use.
  - [ ] Short explanation of what the assistant can do (tasks/events/projects/scheduling/prioritisation).
- **Safety and clarity**:
  - [ ] Optionally show a small label when fallbacks are used.
  - [ ] Keep a warm, student-focused tone to match backend prompts.

These guidelines are intended as a practical reference while you design and implement the frontend UI for the TaskLyst LLM assistant.

