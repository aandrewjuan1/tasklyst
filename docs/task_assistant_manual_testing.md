# Task Assistant (LLM module) — Manual testing guide

Step-by-step checklist to verify the task assistant works end-to-end: Ollama, queue, Reverb, chat UI, and tool calls.

---

## Prerequisites

- **Ollama** installed and the **hermes3:3b** model available.
- **.env** (or environment) with at least:
  - `OLLAMA_URL=http://localhost:11434` (default; only set if Ollama is elsewhere).
  - `QUEUE_CONNECTION=database` (or whatever you use; queue must be processed).
  - Reverb / broadcast vars if you use Reverb (see below).

---

## Step 1: Pull the model (once)

In a terminal:

```bash
ollama pull hermes3:3b
```

Wait until the model is fully downloaded. You can confirm with:

```bash
ollama list
```

You should see `hermes3:3b` in the list.

---

## Step 2: Start Ollama (if not already running)

Ollama often runs as a service. If not:

```bash
ollama serve
```

Leave this running. The app will call `OLLAMA_URL` (e.g. `http://localhost:11434`) for completions.

---

## Step 3: Start the queue worker

The chat dispatches a **queued job** (`BroadcastTaskAssistantStreamJob`) to run the LLM and broadcast the stream. You must run a worker:

```bash
cd /path/to/tasklyst
php artisan queue:work
```

Leave it running. If the queue is not processed, sending a message will do nothing (no reply, no errors in the UI).

---

## Step 4: Start Reverb (WebSocket server)

The assistant streams replies over **Reverb**. Start the Reverb server:

```bash
php artisan reverb:start
```

Leave it running. Ensure your `.env` has Reverb app credentials and that your frontend build uses the same (e.g. `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`). If Reverb is not running, the UI will not receive streamed text (the job may still run and persist the message in the DB, but you won’t see it live).

---

## Step 5: Build / run frontend (if needed)

If you changed JS or env, rebuild so Echo and Reverb config are up to date:

```bash
npm run build
# or, for development with hot reload:
npm run dev
```

Then (if using Herd) the app is at **https://tasklyst.test** (or your configured URL).

---

## Step 6: Log in and open the dashboard

1. Open the app in the browser (e.g. `https://tasklyst.test`).
2. Log in as a user.
3. Go to the **Dashboard** (e.g. `https://tasklyst.test/dashboard`).

---

## Step 7: Open the Task Assistant chat

1. In the **sidebar**, find the control that opens the task assistant (e.g. “Open task assistant” or an icon).
2. Click it to open the **chat flyout** (panel or slide-out with “Task assistant” heading and a message input).

---

## Step 8: Send a simple message (no tools)

1. In the chat input, type a short message, e.g. **“Hello”** or **“What can you do?”**.
2. Press Enter or click Send.

**What to expect:**

- Your message appears in the chat immediately (user bubble).
- A **streaming reply** appears (assistant bubble): text appears gradually (word by word or in chunks), possibly with a blinking cursor.
- When the reply is done, streaming stops and the final text stays in the bubble.

If you see **“Working…”** briefly, that can indicate tool activity; for a simple “Hello” you might not see it.

**If nothing happens:**

- Check the **queue worker** terminal: the job should be processed (no errors). If the job fails, you’ll see the exception there.
- Check **Ollama**: ensure `ollama list` shows `hermes3:3b` and that `ollama serve` (or the service) is running.
- Check **Reverb**: ensure `reverb:start` is running and that the browser can connect (e.g. no mixed HTTP/HTTPS issues; correct `VITE_REVERB_*` for your URL).

---

## Step 9: Send a message that uses a tool

Try a request that should trigger a **tool**, e.g.:

- **“List my tasks.”**
- **“Create a task called Test task.”**
- **“What projects do I have?”** (if you have a list_projects-style tool).

**What to expect:**

1. Your message appears.
2. You may see **“Working…”** while the model calls the tool.
3. The assistant’s reply streams in and should reflect the **tool result** (e.g. “You have no tasks” or “Here are your tasks: …”, or “Task ‘Test task’ created.”).

This confirms: queue → Ollama → Prism → tool execution → broadcast → UI.

---

## Step 10: Verify in the database (optional)

To confirm persistence and tool calls:

1. **Threads and messages**

   ```sql
   SELECT id, user_id, title, created_at FROM task_assistant_threads ORDER BY id DESC LIMIT 5;
   SELECT id, thread_id, role, LEFT(content, 80) AS content_preview, created_at
   FROM task_assistant_messages ORDER BY id DESC LIMIT 10;
   ```

   You should see your user and assistant messages (role `user` / `assistant`).

2. **Tool calls** (if you triggered a tool)

   ```sql
   SELECT id, thread_id, tool_name, status, operation_token, created_at
   FROM llm_tool_calls ORDER BY id DESC LIMIT 10;
   ```

   You should see rows with `tool_name` (e.g. `list_tasks`, `create_task`) and `status` = `success` (or `failed` if something went wrong).

---

## Step 11: Check logs (if something fails)

- **Laravel log:** `storage/logs/laravel.log`  
  Look for Prism/Ollama errors or job exceptions.
- **Queue worker:** Exceptions from the job are printed in the terminal where `queue:work` is running.
- **Reverb:** Logs in the terminal where `reverb:start` is running (connection/broadcast issues).
- **Browser console (F12):**  
  Check for WebSocket or Echo errors (e.g. wrong Reverb host/port/scheme).

---

## Quick checklist

| Step | Action | Expected |
|------|--------|----------|
| 1 | `ollama pull hermes3:3b` | Model appears in `ollama list` |
| 2 | Ollama running (`ollama serve` or service) | Requests to `OLLAMA_URL` succeed |
| 3 | `php artisan queue:work` | Jobs are processed when you send a message |
| 4 | `php artisan reverb:start` | Streamed reply appears in the chat UI |
| 5 | `npm run build` or `npm run dev` | App loads with correct Reverb config |
| 6 | Log in, go to dashboard | Dashboard loads |
| 7 | Open task assistant (Workspace page, Assistant button) | Chat flyout opens |
| 8 | Send “Hello” | User message + streamed assistant reply |
| 9 | Send “List my tasks” or “Create a task called X” | “Working…” then reply using tool result |
| 10 | DB: `task_assistant_*`, `llm_tool_calls` | Rows for threads, messages, and tool calls |

---

## Troubleshooting

- **No reply at all:** Queue not running or job failing (check queue worker output and `storage/logs/laravel.log`). Also confirm Ollama is up and the model is available.
- **Reply in DB but not in UI:** Reverb not running or frontend not connected (check Reverb terminal and browser console; verify `VITE_REVERB_*` and that the app URL matches).
- **Timeout / slow:** Increase `PRISM_REQUEST_TIMEOUT` or `config('prism.request_timeout')` (e.g. 60+ seconds) for Ollama.
- **Tool not called:** Try a clearer prompt (e.g. “List my tasks” or “Create a task titled X”). Check `llm_tool_calls` for `failed` and `result_json` for error details.
- **“Too many method calls” (Livewire):** Streaming sends many `.text_delta` events; each triggers a component update. The app raises `config('livewire.payload.max_calls')` (e.g. to 250) for the task-assistant. If you still hit the limit, increase it further in `config/livewire.php`.
- **“[vite] server connection lost” in browser log:** Harmless when you are not running `npm run dev`. It appears when the page expects a Vite dev server and it is not running. Use `npm run build` for production or ignore the message.
