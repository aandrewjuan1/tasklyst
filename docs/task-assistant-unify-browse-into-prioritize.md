# Task Assistant: Unify Browse/Listing into Prioritization Flow

This document is the **implementation plan** for removing the standalone **browse** flow and merging its behavior into **prioritize**, so listing and prioritizing intents share one robust pipeline. Use it as a checklist so nothing regresses.

---

## 1. Goals

| Goal | Detail |
|------|--------|
| **Single user-facing flow** | “List”, “show”, “filter”, “top N”, and “prioritize” style requests all run through **`prioritize`** (one orchestration path). |
| **Preserve browse robustness** | Everything that makes browse strong today must survive: ambiguous vague-list handling, task row enrichment, narrative schema + clamps, Laravel validation strictness, formatter layout, empty-state behavior, `browse_route_context`-style prompting, config-driven limits and school/chores domain tuning. |
| **Keep the app working** | Tests green, streaming/metadata consistent, multiturn listing state (`rememberLastListing`) still correct, schedule follow-ups unchanged. |

**Non-goals (unless you explicitly add them later):** rewriting `TaskPrioritizationService` scoring math; changing chat or daily schedule flows.

---

## 2. Current State (Before)

### 2.1 Two parallel orchestrations

| Concern | **Browse** (`runBrowseFlow`) | **Prioritize** (`runPrioritizeFlow`) |
|--------|------------------------------|--------------------------------------|
| Entry | `ExecutionPlan.flow === 'browse'` | `ExecutionPlan.flow === 'prioritize'` |
| Snapshot | `config('task-assistant.browse.snapshot_task_limit')` | `buildForUser(..., 100)` |
| Selection | `TaskAssistantBrowseListingService::build()` → `prioritizeFocus` → **tasks only**, ambiguous shortcut, enriched rows | `prioritizeFocus` → **tasks + events + projects**, slice by `countLimit` |
| LLM narrative | `refineBrowseListing` + `browseNarrativeSchema` | `refinePrioritize` + `prioritizeNarrativeSchema` |
| Validation | `validateBrowseData` (reasoning + suggested_guidance + items…) | `validatePrioritizeData` (summary, items, limit_used…) |
| Formatter | `formatBrowseMessage` | `formatPrioritizeMessage` |
| Metadata key | `browse` | `prioritize` |
| `generationResult.valid` | Always `true` (empty list still builds payload) | `false` if no items selected |

### 2.2 Intent routing today

- `TaskAssistantIntentResolutionService::compositeScores()` produces scores for **`browse`**, **`prioritize`**, **`schedule`**.
- LLM enum `TaskAssistantUserIntent::Listing` feeds the **browse** composite; `Prioritization` feeds **prioritize**.
- `resolveSignalOnly()` uses `listing` → `browse`, `prioritization` → `prioritize`.

### 2.3 Shared engine (unchanged by merge)

- `TaskAssistantTaskChoiceConstraintsExtractor`
- `TaskPrioritizationService::prioritizeFocus()` (+ `applyContextFilters`, school/chores domain, keywords)
- Config under `config('task-assistant.browse.*')` for domain tags/patterns (used by prioritization helpers today)

---

## 3. Target State (After)

### 3.1 One flow: `prioritize`

- **`runBrowseFlow` is removed** from `TaskAssistantService`; **`runPrioritizeFlow`** (or a renamed private method) implements **unified listing + prioritization**.
- External **flow name** for structured streaming, logs, and message metadata: **`prioritize`** only (no `browse`).

### 3.2 Behavioral modes inside prioritize (recommended)

Encode behavior with **explicit options** (constructor param, DTO, or `ExecutionPlan` fields) so tests stay clear:

| Mode / flag | Purpose |
|-------------|---------|
| **`entity_scope`** | `tasks_only` (default for merged “listing” behavior) vs `mixed` (tasks + events + projects) if you still want cross-type focus in some cases. **Decision required** (see §4). |
| **`ambiguous_list_shortcut`** | When true, apply browse’s **ambiguous** reset + `ambiguous_top_limit` slice (vague “list my tasks”). |
| **`listing_style_narrative`** | When true, use browse’s **Prism schema** (`reasoning` + `suggested_guidance`), `refineBrowseListing` prompts, clamps, and **browse-style formatter** for the message body. When false, legacy prioritize narrative (optional if you fully standardize on browse narrative). **Plan: default true for all prioritize after merge** so browse quality wins. |
| **`count_limit`** | Same as today: from `ExecutionPlan.countLimit`, but must interact with `max_items` / ambiguous limits consistently. |

**Recommendation:** After merge, **always** use browse **schema + formatter + validation** for the unified prioritize payload, and **drop** the old prioritize-only narrative/formatter path to avoid two UX shapes. If you need a migration period, keep a feature flag for one release only.

### 3.3 Intent routing after merge

- Remove **`browse`** from flow score arrays and from `IntentRoutingPolicy` / `TaskAssistantService::buildExecutionPlan` branches.
- **Listing** and **prioritization** LLM intents should both resolve to **`prioritize`**:
  - Option A: In `compositeScores()`, set **`prioritize`** score to `max(listingComposite, prioritizationComposite)` or `w1*listing + w2*prioritization` (tune so listing utterances still win).
  - Option B: Map `TaskAssistantUserIntent::Listing` to contribute **only** to `prioritize` score (not a separate `browse` key).
- Update `resolveSignalOnly()` so **`listing` signal** maps to **`prioritize`**, not `browse`.
- Update clarification copy: remove **`browse`** branch in `buildClarificationQuestion()`; merge wording into **prioritize** / default.
- **`TaskAssistantIntentInferenceService`** system text: clarify that “listing” and “prioritization” both mean the same **ranked task list** flow (wording TBD).

### 3.4 Payload shape (single contract)

**Target:** one validated structure compatible with **browse-level** fields:

- `items[]`: enriched task rows as today’s browse (`entity_type`, `entity_id`, `title`, `priority`, `due_bucket`, `due_phrase`, `due_on`, `complexity_label`, …).
- `reasoning`, `suggested_guidance`, `limit_used`.
- Optionally keep **optional** prioritize fields (`summary`, `strategy_points`, …) **only if** the formatter still needs them; **prefer deleting** them to match browse-only payload and avoid dual validation.

**Rule:** `TaskAssistantResponseProcessor` should expose **one** `validatePrioritizeData` (or rename to `validateListingData`) that **replaces** both old validate methods for this flow.

---

## 4. Product / Architecture Decisions (resolve before coding)

Document the team’s choice for each:

1. **Tasks-only vs mixed entities**  
   - Browse today: **tasks only**. Prioritize today: **tasks + events + projects**.  
   - Unified flow: **tasks only always**, **mixed always**, or **mixed only when user says “focus” / “priorities”** and tasks-only for “list/show”?  

2. **Single narrative style**  
   - Adopt **browse** (`reasoning` + `suggested_guidance`) for **all** prioritize responses and remove `prioritizeNarrativeSchema` usage from this flow? **Recommended: yes** for “browse is most robust.”

3. **Empty results**  
   - Browse builds valid payload + fallbacks. Prioritize sets `generationResult.valid` false when empty.  
   - Unified: follow **browse** behavior (always valid generation path with deterministic/fallback copy) so users never hit only generic failure when the list is empty.

4. **Backward compatibility for old messages**  
   - Threads with `metadata.browse` or `structured.flow === 'browse'`: **read-only** support in UI (if any) vs migration script vs ignore (historical only).

5. **Config naming**  
   - Rename `task-assistant.browse.*` → `task-assistant.listing.*` or `task-assistant.prioritize.*` for clarity, or keep keys with deprecation comments to reduce churn.

---

## 5. Browse Capabilities — Migration Checklist

Copy each item into the unified prioritize path and tick when done.

### 5.1 Deterministic selection (`TaskAssistantBrowseListingService`)

- [ ] `prioritizeFocus($snapshot, $context)` with same `context` as today.
- [ ] **Ambiguous** detection (`isAmbiguousBrowseListRequest`) + context reset when ambiguous.
- [ ] Limits: `ambiguous_top_limit`, `max_items` (align with `ExecutionPlan.countLimit` — define precedence: e.g. `min(countLimit, max_items)`).
- [ ] **Tasks-only** filter after rank.
- [ ] Per-row enrichment: `due_bucket`, `due_phrase`, `due_on`, `complexity_label`, etc.
- [ ] `describeFilters` / `buildDeterministicSummary` for hybrid narrative prompt (`filter_context_for_prompt`, `deterministic_summary`, `ambiguous` flag).

**Implementation note:** Either **rename** `TaskAssistantBrowseListingService` → `TaskAssistantListingSelectionService` (or merge into a new `TaskAssistantPrioritizeListingBuilder`) and call it from **only** `runPrioritizeFlow`, or **inline** into one private method with clear sections. Do not leave dead “browse” naming in the public API long-term.

### 5.2 LLM narrative (`TaskAssistantHybridNarrativeService`)

- [ ] Use **`refineBrowseListing`** (or rename to `refinePrioritizeListing`) with `browseNarrativeSchema`.
- [ ] Generation route `browse_narrative` → merge into **`prioritize_narrative`** config (single set of temperature/max_tokens) or keep key alias in config.
- [ ] Fallbacks: `browseNarrativeFallbacks` + `TaskAssistantBrowseDefaults` clamps.
- [ ] **Remove or bypass** `refinePrioritize` for the unified flow if narrative is fully replaced.

### 5.3 Schema (`TaskAssistantSchemas`)

- [ ] **`browseNarrativeSchema`** becomes the single narrative schema for this flow (rename to `listingNarrativeSchema` or `prioritizeListingNarrativeSchema` for clarity).
- [ ] Remove unused `prioritizeNarrativeSchema` if nothing calls it.

### 5.4 Validation (`TaskAssistantResponseProcessor`)

- [ ] Merge **`validateBrowseData`** rules into **`validatePrioritizeData`** (or one new method).
- [ ] Remove `case 'browse'` from `validateFlowData`.

### 5.5 Formatter (`TaskAssistantMessageFormatter`)

- [ ] **`formatBrowseMessage`** becomes the default for **`prioritize`** (rename to `formatPrioritizeListingMessage` or keep private method name).
- [ ] Remove **`formatPrioritizeMessage`** if obsolete.
- [ ] **`humanizeFilterDescription`**: still used anywhere → wire from unified prompts if needed.

### 5.6 Defaults (`TaskAssistantBrowseDefaults`)

- [ ] Rename file/class to **`TaskAssistantListingDefaults`** (optional) and update all references; or keep class name and update docblocks.

### 5.7 Orchestration (`TaskAssistantService`)

- [ ] Delete `runBrowseFlow` and `if ($plan->flow === 'browse')`.
- [ ] Route former browse intent to **`prioritize`** in `buildExecutionPlan` / decision mapping.
- [ ] Inject listing builder where browse service was; **single** `executeStructuredFlow(..., flow: 'prioritize', metadataKey: 'prioritize')`.
- [ ] `promptData['route_context']`: merge **`browse_route_context`** text into prioritize system/route prompt (or global `task-assistant` config key).
- [ ] `rememberLastListing(..., 'prioritize', ...)` only; remove **`browse`** from `source_flow` union or migrate stored state.

### 5.8 Conversation state (`TaskAssistantConversationStateService`)

- [ ] PHPDoc: `'prioritize'` only (or keep `'browse'` in DB for legacy reads).
- [ ] Any code branching on `source_flow === 'browse'` → **`prioritize`** or treat both as equivalent during transition.

### 5.9 Listing reference resolver (`TaskAssistantListingReferenceResolver`)

- [ ] Tests use `source_flow` => `browse`; update to **`prioritize`** and add legacy test if you support old metadata.

### 5.10 Flow execution engine (`TaskAssistantFlowExecutionEngine`)

- [ ] `summarizeGenerationPayload`: remove **`browse`** branch; **`prioritize`** covers listing item counts.

### 5.11 Prism client options (`TaskAssistantHybridNarrativeService::resolveClientOptionsForRoute`)

- [ ] Remove **`browse`** / **`browse_narrative`** routes if collapsed.

### 5.12 Tools / generation profile (`TaskAssistantService`)

- [ ] `resolveToolsForRoute`, `resolveGenerationProfileForRoute`, `mapFlowToGenerationRoute`: drop **`browse`** entries.
- [ ] `config/task-assistant.php`: `tools.routes.browse` → remove; merge generation keys.

### 5.13 Config (`config/task-assistant.php`)

- [ ] Consolidate `browse`, `browse_narrative`, `browse_route_context` into **prioritize**-scoped keys (or `listing` subsection).
- [ ] Keep **school/chores** arrays (`school_academic_tag_keywords`, etc.) — they are consumed by `TaskPrioritizationService` regardless of flow name.

### 5.14 Intent layer

- [ ] `TaskAssistantIntentResolutionService`: composite scores and `resolveSignalOnly` — no **`browse`** key.
- [ ] `IntentRoutingPolicy` / `buildExecutionPlan`: allowed flows list without `browse`.
- [ ] `TaskAssistantUserIntent::Listing`: map to **prioritize** execution (naming can stay for LLM labels).

### 5.15 Tests (update or merge)

| File / area | Action |
|-------------|--------|
| `tests/Feature/TaskAssistantBrowseFlowTest.php` | Rename to **prioritize listing** test; assert `flow === 'prioritize'`, metadata `prioritize`, same behaviors. |
| `tests/Feature/TaskAssistantServiceTest.php` | Browse multiturn test → **prioritize** `source_flow`. |
| `tests/Unit/IntentRoutingPolicyTest.php` | Listing maps to **prioritize**, not browse. |
| `tests/Unit/TaskAssistantMessageFormatterTest.php` | `format('prioritize', ...)` with browse-shaped payload. |
| `tests/Unit/TaskAssistantResponseProcessorTest.php` | Browse cases → **prioritize** flow string. |
| `tests/Unit/TaskAssistantOrchestrationConfigTest.php` | Remove browse tool route tests or map to prioritize. |
| `tests/Unit/TaskAssistantBrowseDefaultsTest.php` | Rename if class renamed. |

### 5.16 Frontend / streaming (verify)

- [ ] `resources/views/components/assistant/⚡chat-flyout/chat-flyout.php` (and related): any **`structured.flow === 'browse'`** or metadata key **`browse`** → **prioritize** / **`prioritize` key**.
- [ ] Search JS/Blade for `'browse'` in assistant context.

---

## 6. Implementation Phases (suggested order)

### Phase A — Routing only (feature-flag optional)

1. Map listing intent + signals to **`prioritize`** while **still** calling old browse code paths behind a renamed internal method (temporary).
2. Update tests for intent resolution.

### Phase B — Merge orchestration

1. Fold `runBrowseFlow` logic into `runPrioritizeFlow` with mode flags.
2. Single metadata key **`prioritize`**; remove duplicate streaming branches.

### Phase C — Single payload contract

1. Validator + formatter + hybrid narrative unified per §3.4 / §5.
2. Delete dead code paths (`refinePrioritize` + old formatter if fully replaced).

### Phase D — Cleanup

1. Remove `browse` from config, enums in routing, comments.
2. Rename classes/files if desired (`Browse` → `Listing`).
3. Full test suite run; manual smoke: listing, top N, school filter, ambiguous “list my tasks”, empty list, schedule follow-up referencing last listing.

---

## 7. Verification Checklist (before merge to main)

- [ ] `php artisan test --compact` with focus on Task Assistant tests.
- [ ] Manual: “list my tasks”, “list my top 5 school-related tasks”, “what should I prioritize”, empty workspace, schedule “those tasks”.
- [ ] Log grep: no stray **`task-assistant.flow` `browse`** unless intentional legacy.
- [ ] New threads: `last_listing.source_flow` is **`prioritize`**.

---

## 8. Rollback Plan

- Keep refactor in a **single branch** with clear commits per phase (A→D).
- If routing changes land first, reverting intent mapping restores browse-only path if old code still exists; after code deletion, rollback requires **revert commit** or restore from VCS.

---

## 9. File Touch List (quick reference)

Likely edited:

- `app/Services/LLM/TaskAssistant/TaskAssistantService.php`
- `app/Services/LLM/Browse/TaskAssistantBrowseListingService.php` (rename / relocate)
- `app/Services/LLM/TaskAssistant/TaskAssistantHybridNarrativeService.php`
- `app/Services/LLM/TaskAssistant/TaskAssistantResponseProcessor.php`
- `app/Services/LLM/TaskAssistant/TaskAssistantMessageFormatter.php`
- `app/Services/LLM/Intent/TaskAssistantIntentResolutionService.php`
- `app/Services/LLM/TaskAssistant/IntentRoutingPolicy.php` (if flow list lives here)
- `app/Support/LLM/TaskAssistantSchemas.php`
- `app/Support/LLM/TaskAssistantBrowseDefaults.php`
- `config/task-assistant.php`
- `resources/views/components/assistant/**/*chat-flyout*`
- All tests listed in §5.15

---

## 10. Open Questions Log

_Use this section during implementation._

| # | Question | Decision |
|---|----------|----------|
| 1 | Tasks-only vs mixed entities for unified flow? | |
| 2 | Rename config `browse.*` → ? | |
| 3 | Feature flag for rollout? | |

---

*Last updated: plan created for refactor tracking; update this doc as decisions land.*
