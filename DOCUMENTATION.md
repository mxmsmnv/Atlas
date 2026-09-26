# Atlas — API reference

Atlas is a RAG store: it embeds text via [Squad](https://github.com/mxmsmnv/Squad), keeps the vectors in one ProcessWire database table (`atlas_vectors`), and ranks by cosine similarity in PHP. This document covers the full public API of the `Atlas` module and the `AtlasStore` engine.

- [Concepts](#concepts)
- [Atlas module API](#atlas-module-api)
- [Search options](#search-options)
- [Result shape](#result-shape)
- [ProcessAtlas reindex hooks](#processatlas-reindex-hooks)
- [AtlasStore (engine)](#atlasstore-engine)
- [Schema](#schema)
- [Notes & limits](#notes--limits)

---

## Concepts

- **Collection** — a string namespace for a set of entries (e.g. `faq`, `site`, `olivia_site`). There is no separate registry: a collection exists once it has at least one entry, and disappears when its last entry is removed.
- **Ref** — a caller-chosen unique id within a collection. `put`/`add` upsert by `(collection, ref)`.
- **Chunk** — `addChunked()` splits a long document and stores parts as `{ref}#0`, `{ref}#1`, … with `meta.parent = ref` and `meta.chunk = index`.
- **Embedding** — produced by `Squad::embed()`. All entries in a collection must use the same model (same vector length), or mismatched entries are skipped at query time.

---

## Atlas module API

`$atlas = $modules->get('Atlas');`

### isReady(): bool
True when Squad is installed and has an embedding-capable provider with a key. Squad/module exceptions are caught and return `false`; inspect `readinessError()` for diagnostics.

### readinessError(): string
Diagnostic message from the latest `isReady()` check. Kept separate from `lastError()` so provider health cannot be mistaken for a storage/read failure.

### add(string $collection, string $ref, string $text, array $meta = [], array $opts = []): bool
Embed `$text` and upsert it under `(collection, ref)`. `$opts` is passed to `Squad::embed()`. Returns success.

### addMany(string $collection, array $items, array $opts = []): int
Batch upsert with a **single** embed call and one Store transaction. `$items = [['ref'=>, 'text'=>, 'meta'=>[]], …]`. Returns the number stored. Entries with empty `ref`/`text` are skipped; an oversized ref rejects the batch before embedding. A database failure rolls back the complete prepared batch.

### addChunked(string $collection, string $ref, string $text, array $meta = [], array $opts = [], int $maxChars = 1500): int
Split `$text` into ≤ `$maxChars` chunks (paragraph → sentence → hard-slice) and store each as `{ref}#i` with `meta.parent`/`meta.chunk`. One embed call for all chunks. Returns the chunk count.

### search(string $collection, string $query, int $topK = 5, array $opts = []): array
Embed `$query` and return the top-`$topK` entries by cosine similarity. See [Search options](#search-options) and [Result shape](#result-shape). If collection rows exist but none use compatible dimensions/model, the empty result carries a diagnostic in `lastError()` rather than pretending there were simply no matches.

### lastError(): string
Diagnostic message from the latest read, add, search, delete, clear or collection-replacement operation. An empty array from `search()` or `collections()` is a valid empty result when `lastError()` is empty; a non-empty message means embedding or storage failed. Provider exceptions are caught and reported here instead of escaping the module API. The admin uses the same diagnostic channel for recoverable data-loading, Add and Reindex errors.

### count(string $collection): int
Number of entries in a collection.

### collections(): array
`[['collection' => name, 'count' => n], …]` for every collection present.

Internal collections whose names begin with the reserved `__atlas_stage_` prefix are used by resumable admin reindexing and excluded. Admin Add, View, Delete, Clear and Reindex target operations reject this prefix. `replaceCollection()` likewise requires a normal target and an internal-prefix staging source.

### cleanupStaging(int $maxAge = 86400): int
Remove abandoned internal reindex rows older than `$maxAge` seconds. Returns the number of rows deleted. Active session-owned staging data is removed directly on completion, failure or cancellation.

### entries(string $collection, int $limit = 50, int $offset = 0): array
Browse rows (no vectors), ordered by ref: `[['ref','text','meta','model','dims','modified'], …]`.

### stats(string $collection): array
Aggregates: `['total', 'docs', 'dims', 'avg_chars', 'last_modified', 'models']`. `docs` counts distinct base refs (ref before `#`).

### chunkText(string $text, int $maxChars = 1500): array
Public access to the chunker — split text into chunks without storing (useful to pre-chunk and batch many documents through one `addMany()`).

### lexicalSearch(string $collection, string $query, int $topK = 5, array $opts = []): array
Retrieve ranked entries using significant local query terms without calling the
embedding provider. Use this as a low-latency first pass and fall back to
`search()` when it returns no result. Supported options are `filter` and
`minScore` (`0..1`, default `0.42`).

### delete(string $collection, string $ref): bool
Delete one exact ref. Returns whether the database operation succeeded.

### deleteRef(string $collection, string $ref): bool
Delete a base ref **and** its chunk children (`{ref}#0`, `{ref}#1`, …). Returns whether the database operation succeeded. LIKE wildcards in the ref are escaped, so `page-5` does not also match `page-50`.

### clearCollection(string $collection): bool
Remove every entry in a collection. Returns whether the database operation succeeded.

---

## Search options

`search()`'s `$opts` array — rerank keys are consumed by Atlas, everything else is forwarded to `Squad::embed()` (e.g. `provider`, `model`).

| Key | Type | Effect |
|---|---|---|
| `keyword` | string | Entries whose **text** contains it get a +0.15 score boost (hybrid). |
| `keywordRequire` | bool | Keep only entries containing the keyword. |
| `filter` | array | Metadata equality filter — every key must match. An array value acts as an “in” list: `['lang' => ['en','de']]`. |
| `mmr` | bool | Maximal Marginal Relevance re-ranking — diversifies results so near-duplicate chunks don't fill the top. |
| `mmrLambda` | float | 0..1 relevance↔diversity tradeoff (default `0.7`; lower = more diverse). |

---

## Result shape

`search()` and `lexicalSearch()` return an array in ranking order (score descending without MMR; MMR selection order when diversification is enabled) of:

```php
[
  'ref'   => 'manual#3',
  'text'  => 'the chunk text…',
  'meta'  => ['parent' => 'manual', 'chunk' => 3, 'title' => '…', 'url' => '…'],
  'model' => 'gemini-embedding-001',
  'score' => 0.83,   // cosine (+0.15 if a keyword hit)
]
```

---

## ProcessAtlas reindex hooks

`ProcessAtlas` exposes ProcessWire hookable methods so another module can extend reindexing without patching Atlas.
Register class hooks from `init()` in an autoload ProcessWire module so they are active when the admin reindex job runs.

By default, Atlas creates a general-purpose ProcessWire document: the page title, labelled values from common semantic field types (`Text`, `Textarea`, numeric, date/time, boolean, Options and Page references), and hierarchy context for short pages. Composite values are supported without requiring ProFields: Table columns/rows, Combo subfields and option labels, Textareas, Multiplier, Repeater and Repeater Matrix nested fields are converted to labelled, indented text blocks through their public APIs. `PageTable` and `PageIDs` references are also recognized. Image/File fields retain absolute HTTP URLs while binaries and file-object internals are ignored. Associative arrays retain their keys on separate lines; unknown iterable objects are not flattened positionally. Repeater Matrix system fields and presentation fields such as `icon`, `css_class` and `style` are also ignored. Metadata includes `template`, `name`, `path` and `parent_id` when available; Atlas adds `parent`, `id`, `title`, `url` and `chunk` when storing each chunk.

### `ProcessAtlas::buildPageFieldText`

Called by the default document adapter for every field. Hook this when a module only needs to add, format or suppress one field type; return an empty string to exclude the field. The arguments are the current `Page` and `Field`, and the return value is the labelled plain text inserted into the document.

```php
$wire->addHookAfter('ProcessAtlas::buildPageFieldText', function(HookEvent $event) {
    $page = $event->arguments(0);
    $field = $event->arguments(1);

    if ($field->name === 'internal_notes') {
        $event->return = '';
    } elseif ($field->name === 'coordinates') {
        $event->return = 'Coordinates: ' . (string) $page->get($field->name);
    }
});
```

### `ProcessAtlas::buildPageDocument`

Called for every selected page when the job is prepared and again immediately before its changed content is embedded. The return value is:

```php
[
    'text' => 'Text to embed',
    'meta' => ['application' => 'catalog'],
    'skip' => false,
]
```

An `addHookAfter` callback may replace `text`, extend `meta`, or set `skip` to `true`. Atlas owns the stable `page-{id}` ref and the `parent`, `id`, `title`, `url`, and `chunk` metadata keys. Content returned by the hook participates in the page hash, so a changed hook result is re-embedded automatically.

```php
$wire->addHookAfter('ProcessAtlas::buildPageDocument', function(HookEvent $event) {
    $page = $event->arguments(0);
    $collection = $event->arguments(1);
    $document = $event->return;

    if ($page->template->name === 'private') {
        $document['skip'] = true;
    } else {
        $document['text'] .= "\n" . (string) $page->summary;
        $document['meta']['application'] = 'catalog';
        $document['meta']['collection'] = $collection;
    }

    $event->return = $document;
});
```

### `ProcessAtlas::reindexReady`

Called after all page batches have succeeded but before staging replaces the live collection. Arguments are the Atlas module, live collection name, internal staging collection name, and summary. Integrations may add their own entries to staging with the normal Atlas API. Return the summary, adjusting `pages`/`chunks` when appropriate.

```php
$wire->addHookAfter('ProcessAtlas::reindexReady', function(HookEvent $event) {
    $atlas = $event->arguments(0);
    $staging = $event->arguments(2);
    $summary = $event->return;

    $items = [
        ['ref' => 'catalog-product-42', 'text' => 'Product text', 'meta' => ['application' => 'catalog']],
    ];
    $added = $atlas->addMany($staging, $items);
    if ($added !== count($items)) {
        throw new WireException($atlas->lastError() ?: 'Catalog indexing failed');
    }

    $summary['chunks'] += $added;
    $event->return = $summary;
});
```

An exception or Atlas error from this hook aborts promotion, clears staging, and leaves the live collection unchanged.

### Success notifications

- `ProcessAtlas::pageIndexed(Page $page, string $collection, array $state)` runs after the page's `$page->meta('atlas_index')` state has been saved.
- `ProcessAtlas::collectionReindexed(string $collection, array $summary)` runs after successful atomic promotion. The summary contains `pages`, `chunks`, `unchanged`, and `model`.

Notification hook failures are logged after promotion and do not roll back a successfully published collection.

---

## AtlasStore (engine)

`AtlasStore` is the storage/ranking engine behind the module. Use the `Atlas` module API in normal code; reach for `AtlasStore` only to embed it elsewhere or to swap in a different backend.

- `ensureTable()` / `dropTable()`
- `put(collection, ref, text, vector, meta = [], model = ''): bool` — store a **precomputed** vector.
- `putMany(collection, items): int` — atomically upsert precomputed rows with one compatibility check and reusable prepared statement.
- `nearest(collection, queryVector, topK = 5, opts = []): array` — cosine ranking with the same `opts` as `search()` (filter/keyword/keywordRequire/mmr/mmrLambda).
- `lastError(): string` — diagnostic message from the latest write, delete, retrieval or collection-replacement operation.
- `entries` / `count` / `collections` / `collectionStats` / `delete` / `deleteRef` / `clearCollection`
- `cosine(a, b, aNorm = null): float`

To back Atlas with an external vector DB, reimplement `put()` and `nearest()` against your store and keep the rest of the module unchanged.

---

## Schema

Table `atlas_vectors`:

| Column | Notes |
|---|---|
| `id` | PK |
| `collection` | namespace, indexed |
| `ref` | unique per collection (`coll_ref` unique key) |
| `text` | the stored text |
| `meta` | JSON metadata |
| `model` | embedding model used |
| `dims` | vector length |
| `vector` | JSON array of floats |
| `created` / `modified` | timestamps |

---

## Notes & limits

- **Admin permission.** Setup → Atlas requires the dedicated `atlas-admin` permission (superusers always qualify). ProcessWire installs it on fresh installs, while the ProcessAtlas upgrade hook creates it for existing installations; broad `module-admin` access is not required.
- **Cosine is computed in PHP** over all rows of a collection — fine to ~10⁴ entries; beyond that, use an external vector DB behind `put()`/`nearest()`. Rows are streamed through a small bounded working pool that is repeatedly pruned to the exact Top K shortlist (or `max(topK*6, 30)` candidates for MMR), rather than retaining the whole collection.
- **Corrupt vectors are isolated.** Stored vectors with invalid values/dimensions and non-finite cosine results are skipped. Invalid query magnitude is reported through `lastError()`, and equal scores use `ref` as a deterministic tie-breaker.
- **One model per collection.** Writes with a different model or vector length are rejected; incompatible legacy rows are skipped at query time.
- **Atomic replacement.** `addChunked()` keeps the previous chunks if embedding/storage fails. The admin reindex builds a staging collection in resumable browser-driven batches, exposes progress/cancellation, and promotes it only after every batch succeeds.
- **Non-empty promotion precondition.** `replaceCollection()` locks and verifies the staging collection inside its transaction before deleting the live target, then verifies that at least one row was promoted. Missing or empty staging rolls back without touching live data.
- **Replacement preflight.** `replaceRef()` validates every row before deleting existing chunks: refs must belong to the base document, refs must be unique, and all vectors/models must be consistent. Invalid input never reaches the database transaction.
- **Safe selector handling.** Reindex defaults to the first 1,000 matching pages. An administrator may raise the per-job bound to at most 10,000 for a reviewed, focused selector. A selector matching no pages leaves the existing collection untouched.
- **Reserved staging namespace.** `__atlas_stage_` is centralized in `AtlasStore::STAGING_PREFIX`; internal collections cannot be targeted through admin URLs/actions or promoted as live targets.
- **Health gates.** Squad readiness exceptions cannot break the admin page. Reindex is unavailable after a Store read failure, and a staging-cleanup failure leaves any active job untouched instead of replacing it.
- **Recoverable network failures.** Reindex retries interrupted batch requests up to five times with bounded exponential backoff. A persistent failure stops in an accessible alert while retaining the session job and Cancel action; reload/Try again resumes from saved progress. JSON diagnostics substitute invalid UTF-8 instead of producing an unreadable response.
- **Per-job identity.** Every reindex has a random token embedded in its batch and Cancel forms in addition to CSRF. Requests from stale tabs cannot process, cancel or clear a newer job/staging collection; they are told to reload instead.
- **Manual entry drafts.** Add entry accepts up to 100,000 characters. Validation, embedding or storage failures preserve the sanitized draft across the redirect and reopen the form; a successful add clears it.
- **Admin input boundaries.** Manual titles are limited to 255 characters and advanced ProcessWire selectors to 1,000. Metadata links accept only local absolute paths or valid credential-free HTTP(S) URLs with a host. `Require keyword` is disabled until a literal keyword is present.
- **Identifier preflight.** The Atlas facade checks collection/ref presence and schema lengths before calling Squad, so invalid add/search requests cannot consume an embedding API call. `addMany()` rejects an invalid non-empty ref before embedding any item in that batch.
- **Embedding response preflight.** `addMany()` validates every returned vector and requires consistent dimensions before the first Store write, preventing a malformed provider response from partially writing the batch.
- **DDL is request-cached.** A successful `ensureTable()` is remembered by the `AtlasStore` instance, so batch writes do not repeat `CREATE TABLE IF NOT EXISTS` for every item. `dropTable()` resets the cache.
- **Batch writes are atomic.** `addMany()` delegates one `putMany()` transaction: collection compatibility is checked once, the UPSERT is prepared once, and any failed row rolls back the whole storage batch.
- **Compatibility checks are transactional.** `put()`, `putMany()` and `replaceRef()` perform their model/dimension check with row locking on MySQL/PostgreSQL. SQLite transactions begin with `BEGIN IMMEDIATE`, which takes the database write lock before the same check, preventing parallel first writes from creating a mixed collection without unsupported `FOR UPDATE` syntax.
- **Read failures are not empty data.** Overview, Collections, Search and collection browsing show a database error state when reads fail instead of claiming that no knowledge exists.
- **Pagination is bounded.** A stale or hand-edited collection offset is clamped to the final valid page, and Search reports the number of results actually returned rather than the requested limit.
- **`addMany()` is one embed call** — for very large reindexes, batch in groups (the ProcessAtlas reindex uses groups of 64).
- **MMR** bounds its candidate pool to `max(topK*6, 30)` for performance.
