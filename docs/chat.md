# ADR-0001: Folio Chat & Search Architecture

**Status:** Proposed
**Date:** 2026-05-23
**Deciders:** Tac Tacelosky
**Context repo:** `survos-sites/showcase` (canonical), applies across `ssai/scanstation`, `survos/folio-bundle` (née `pixie-bundle`), `harvest`, `md`, `media`, `ai-tools`

---

## Context

ScanStationAI and Museado will host thousands of museum collections, each represented as a **folio** — a portable SQLite file produced by `survos/folio-bundle`. The original plan to give each folio its own Meilisearch index does not scale: Meili carries non-trivial per-index overhead (LMDB environment, in-memory structures, file handles), and the majority of folios will receive zero or bot-only traffic. Paying Meili's idle cost for a long tail of sleepy collections is the wrong economics.

Simultaneously, the previous semantic-search approach (Meili embedders driven by Liquid templates) required an upfront embedding cost that scaled with corpus size — also wrong economics for a corpus dominated by rarely-queried items.

This ADR records the new search-and-chat architecture and the rationale.

---

## Decision

### 1. Two-tier search architecture

| Tier | Engine | Scope | Lifecycle |
|------|--------|-------|-----------|
| **Per-folio** | SQLite FTS5 (+ optional `sqlite-vec`) | One museum collection | Ships inside the folio file; zero idle cost |
| **Cross-collection** | Meilisearch | Object-type indexes (photos, wills, newspapers, statues, …) — a few dozen total | Persistent Meili instances; warrant the overhead |

**Federated search** across object-type Meili indexes uses Meili's `/multi-search` federation endpoint for "search everything" UX.

### 2. Chat over folios uses BM25 retrieval + LLM, not vector search

Chat over a single folio is a RAG loop implemented in application code, not a search-engine feature:

1. **Query planning** (optional LLM call): rewrite the user message into an FTS5 query, expand synonyms.
2. **Retrieval** (FTS5): `SELECT … FROM items_fts WHERE items_fts MATCH ? ORDER BY bm25(items_fts) LIMIT 20`.
3. **Context assembly**: per result, concatenate `title + denseSummary + FTS5 snippet`.
4. **LLM call**: system prompt + assembled context + user message → Anthropic API.
5. **Stream response** to user with citations linking to item detail pages.

The `denseSummary` field (precomputed at folio-build time) does the heavy lifting that vector search would otherwise do: the LLM reasons over 20 well-written summaries, which usually beats reasoning over 20 vector-matched chunks.

### 3. denseSummary is precomputed at folio-build, cached by content hash

denseSummary is generated **once** per item, at folio-build time, via Anthropic Haiku through the **Message Batches API** (50% discount, sub-1-hour turnaround typical, 24-hour ceiling).

A **content-hash cache** sits outside the folio database (separate SQLite file, mounted as a persistent volume in Dokku). Cache key: `sha256(model_name + prompt_version + source_text)`. On folio build:

```
for each item:
    key = sha256(model + prompt_version + text)
    if cache.has(key):
        denseSummary = cache.get(key)
    else:
        denseSummary = call_llm_batch(text)   # production
        # OR call_local_llm(text)             # testing via Ollama
        cache.set(key, denseSummary)
```

This makes the current daily-drop-and-reimport development cycle free on the AI side after the first run. In production it makes re-builds, bug-fix re-imports, and prompt iterations cheap.

**Cache hash inputs must include:**
- `model_name` — so Haiku → Sonnet doesn't serve stale summaries.
- `prompt_version` — a manually-bumped string constant (`summary_v1`, `summary_v2`, …) for meaningful prompt changes.
- Exact normalized source text.

### 4. Meilisearch is opt-in per folio, with LRU eviction

For folios that warrant it (large collections, frequent human traffic, faceted-browse use cases), Meili indexing is **on-demand**:

- User clicks "enable advanced search" or "enable chat" → backend spins up a Meili index for that folio, shows progress UI.
- Trigger requires a real user action (POST with CSRF, not a GET a crawler can follow). Bots get the FTS5 tier.
- Per-folio metadata table (`meili_index_status`) tracks `last_indexed_at`, `last_queried_at`, `item_count`, `index_size_bytes`.
- Nightly cron evicts indexes where `last_queried_at < now() - 30 days`.
- Large collections (above a size threshold TBD — likely ~50k items) bypass the on-demand path and are always indexed or nightly-batched.

**Chat does not require Meili.** FTS5 retrieval is sufficient for chat. "Enable Meili" and "enable chat" are separate concepts.

### 5. Vectors (sqlite-vec) deferred but architected for

`sqlite-vec` is reserved for the small number of folios that genuinely need semantic similarity (e.g., "find photos that look like this photo," conceptual queries where keyword expansion isn't enough). When added:

- Vectors are computed at folio-build time, same cache pattern as denseSummary.
- Embedding model choice is a one-way door per folio — model used at build must match model used at query. Default candidate: `text-embedding-3-small` (cheap, 1536 dims, stable).
- Hybrid retrieval: merge FTS5 BM25 results with sqlite-vec nearest-neighbor results, dedupe by ULID.
- Pay the embedding cost only for collections that warrant it.

### 6. Translation follows the same on-demand pattern

- `title` and `denseSummary` translated at folio-build into a small fixed set of languages (cheap, bounded cost).
- Full-item translation happens on-demand when a non-English-speaking user actually views the item; result cached in the folio's SQLite. Bots get source language.

---

## Service Decomposition (Symfony 8)

Lives in `survos/folio-bundle` (or a new `survos/folio-chat-bundle` if separation is cleaner):

- **`FolioChatService`** — orchestrates the RAG loop. Inputs: folio handle, user message, conversation history. Output: streamed SSE response.
- **`FolioRetriever`** — wraps FTS5 queries against a folio's SQLite. Returns ranked items with snippets + denseSummary.
- **`QueryPlanner`** — optional LLM call that rewrites user messages into FTS5 queries with synonym expansion. v1 may skip this and pass messages directly to FTS5 with light cleanup.
- **`SummaryCache`** — content-hash cache for denseSummary results. Backed by a SQLite file outside the folio. Methods: `get($key): ?string`, `set($key, $value): void`.
- **`DenseSummaryGenerator`** — calls Anthropic API (Batch in production, sync in development, Ollama in testing). Always goes through `SummaryCache` first.
- **`MeiliIndexManager`** — handles on-demand index creation, status tracking, eviction. Reads from `meili_index_status` table.
- **`ChatController`** — SSE endpoint. Takes folio ID + message + optional history, calls `FolioChatService`, streams response.

Conventions: `#[AsCommand]` on methods, `#[Argument('desc')]` for positional args, `#[MapInput]` DTOs, `Command::SUCCESS/FAILURE/INVALID` constants, no `extends Command`. Entities use `survos/field-bundle`. ULIDs as primary keys throughout.

---

## Cost Model

| Activity | When | Cost basis |
|----------|------|------------|
| denseSummary generation | Folio build, once per item | Haiku × 0.5 (Batch API), cached by content hash → near-zero on rebuilds |
| Vector embedding (when enabled) | Folio build, once per item | OpenAI 3-small or equivalent, cached |
| Translation of title + denseSummary | Folio build, fixed N languages | Haiku × 0.5 |
| FTS5 search | Per query | Zero (local SQLite) |
| Chat query | Per chat message | 1× LLM call (optionally 2 with query planner) — Haiku or Sonnet |
| Meili indexing | On-demand or scheduled | Meili instance overhead + indexing CPU |
| Meili search | Per query | Meili instance (already paid) |
| Full-item translation | On-demand per view | Haiku × 0.5, cached per-item-per-language |

**Net effect:** folios with zero traffic cost zero ongoing AI dollars after build. Folios with traffic pay proportionally per query.

---

## What We Give Up vs Meili-with-Vectors-and-Chat

Honest accounting:

- **Recall on conceptual queries** — "items about resilience" won't match unless that word (or a synonym after query expansion) appears. Mitigation: query expansion in the planner step; sqlite-vec for high-value collections.
- **Tuned query rewriting** — Meili's chat has been tuned; ours starts naive. Iteration required on the planner prompt.
- **Cross-encoder reranking** — Meili can do this if configured; we rely on BM25 ordering + LLM judgment over the top-N. Usually fine.
- **Orchestration code we own** — a few hundred lines of service code instead of one Meili API call. Trade-off: we control the loop, can swap models, can mix retrievers.

---

## Implementation Order

1. **`SummaryCache` service + content-hash key discipline.** Unblocks free local iteration immediately. ~80 lines of PHP.
2. **`DenseSummaryGenerator` with Ollama fallback** (env-var-switched between Anthropic and local Llama/Qwen for testing).
3. **`FolioRetriever` + `FolioChatService` MVP** — no query planner, direct FTS5, single LLM call. Wire into a minimal `ChatController` with SSE.
4. **Query planner** — added when recall problems become visible in real use.
5. **`MeiliIndexManager`** — on-demand indexing, eviction policy, size-threshold routing.
6. **Federated search across object-type Meili indexes** — separate workstream, not folio-bundle.
7. **sqlite-vec hybrid retrieval** — only when a specific collection demands it.
8. **Batch API integration** — replace sync Anthropic calls at folio-build with Batch submissions.
9. **On-demand translation layer** — last, after the chat path is stable.

---

## Open Questions

- **Meili index status registry location:** does `meili_index_status` live in the folio itself, or in a central registry (Museado-side Postgres / Redis)? Centralized makes eviction cron simpler; per-folio makes the folio more portable. Leaning centralized.
- **Conversation history persistence:** per-visitor per-folio history dramatically improves chat UX ("tell me more about the third one") but adds a session store. v1: in-memory per request. v2: session-scoped server-side store.
- **denseSummary prompt:** needs to be drafted and versioned as `summary_v1`. Should produce 2–4 sentence summaries that capture subject, date if present, people/places named, and notable features — written to be useful as LLM retrieval context, not as user-facing display text.
- **Threshold for "always index in Meili":** likely 50k items, but should be measured against real folio-size distribution before fixing.

---

## References

- Anthropic Message Batches API — 50% off, most batches under 1 hour, 24-hour ceiling, up to 100k requests per batch.
- Anthropic prompt caching — stackable with Batch for ~95% reduction on repeated context (relevant if summary prompt is long and consistent).
- `sqlite-vec` — loadable SQLite extension for vector similarity, ships in same file as FTS5.
- Meilisearch federated search via `/multi-search` endpoint.
- SymfonyCon Amsterdam talk (TT, prior) — semantic search with Meilisearch and Symfony, for historical context on the previous approach.