# Atlas Agent Guide

This file tells AI agents how to evaluate, integrate, and operate Atlas when building a ProcessWire website. It is behavioral guidance, not proof of the current site state.

Project-level instructions, an approved architecture, and explicit user decisions override this file. Before using Atlas, inspect the live site and confirm that the module, Squad, collections, fields, templates, and permissions actually exist.

## What Atlas is

Atlas is a lightweight semantic knowledge and retrieval module for ProcessWire. It:

- sends text to Squad for embeddings;
- stores text, metadata, and vectors in MySQL/MariaDB;
- groups entries into named collections;
- retrieves entries by cosine similarity, with optional metadata filters, keyword boosting, and MMR diversification;
- provides `ProcessAtlas` under **Setup → Atlas** for administrators with `atlas-admin` permission.

Capabilities:

- `semantic-retrieval`
- `knowledge-indexing`
- `rag-context`
- `hybrid-search`
- `collection-management`

Atlas is suitable for FAQ retrieval, support assistants, contextual recommendations, editorial knowledge, and grounded AI responses on small and medium ProcessWire sites.

Atlas is not:

- the source of truth for site content;
- an authorization system;
- a replacement for ProcessWire selectors or SQL for exact structured queries;
- a general web crawler;
- a large-scale vector database;
- an LLM response generator.

Use a dedicated vector database when a collection will materially exceed about 10,000 entries.

## Sources of truth

Use evidence in this order when deciding what exists on a site:

1. live ProcessWire state;
2. project Context or inspection output;
3. installed module metadata and configuration;
4. project documentation and project `AGENTS.md`;
5. this module's documentation;
6. model prior knowledge.

For exact Atlas API behavior, use [DOCUMENTATION.md](DOCUMENTATION.md). For purpose and installation, use [README.md](README.md). Inspect the implementation only when the documentation is incomplete or conflicts with the installed version.

If documentation and live state disagree, report the conflict. Do not silently install, configure, reindex, or delete data to make the site match the documentation.

## Prerequisites to verify

Before proposing or implementing an Atlas integration, confirm:

- ProcessWire is version 3.0.200 or newer;
- `Atlas` is installed;
- `Squad` is installed and has an embedding-capable provider;
- `$atlas->isReady()` returns `true`;
- the intended collection name, content scope, languages, and access policy are known;
- the expected collection size is appropriate for PHP cosine ranking;
- sending the selected text and user queries to the configured embedding provider is allowed.

Do not interpret `AGENTS.md` as evidence that Atlas or Squad is installed.

```php
$atlas = $modules->get('Atlas');

if (!$atlas || !$atlas->isReady()) {
    $reason = $atlas ? $atlas->readinessError() : 'Atlas is not installed';
    // Stop or use an explicitly designed non-semantic fallback.
}
```

## How to use Atlas when building a website

### 1. Start from the user experience

Define the concrete feature before designing collections. Examples:

- retrieve FAQ passages for a support answer;
- find related articles by meaning;
- supply trusted site context to an AI assistant;
- search an internal editorial knowledge base.

Do not add Atlas merely because a site has text. Prefer ProcessWire selectors for exact fields, relationships, dates, status, ownership, and permissions.

### 2. Design collection boundaries

Use separate collections when content differs in any of these ways:

- trust or moderation level;
- public versus private audience;
- tenant or customer boundary;
- language, when cross-language retrieval is not intended;
- embedding provider/model lifecycle;
- deletion or reindex schedule;
- product/application ownership.

Use stable, explicit names such as `site`, `faq_en`, or `support_internal`. Never create or target a collection beginning with `__atlas_stage_`; that namespace belongs to atomic reindexing.

All vectors in one collection must use a compatible embedding model and dimension. A provider or model change requires a planned rebuild, not mixed writes.

### 3. Define stable refs and metadata

Refs are unique within a collection and should be deterministic. Prefer examples such as:

- `page-123`
- `faq-password-reset`
- `product-42`

For long documents, call `addChunked()` with the base ref. Atlas creates `page-123#0`, `page-123#1`, and so on. Delete the document with `deleteRef('collection', 'page-123')`, not by guessing chunk refs.

Store metadata needed for attribution, filtering, and validation:

```php
[
    'page_id'  => $page->id,
    'title'    => $page->title,
    'url'      => $page->url,
    'template' => $page->template->name,
    'source'   => 'website',
]
```

Metadata is not an access-control boundary. Recheck the current page, user, tenant, language, publication status, and permissions before displaying or sending a retrieved passage to another model.

### 4. Index only intentional content

Create a documented text projection instead of embedding arbitrary serialized page data. Include human-readable fields that help retrieval; omit secrets, credentials, unpublished notes, personal data, markup noise, and fields outside the approved feature scope.

Use:

- `add()` for one short entry;
- `addMany()` for a batch of independent entries;
- `addChunked()` for one long document;
- the ProcessAtlas reindex workflow for public site pages and atomic full-collection rebuilding;
- ProcessAtlas hooks when another application must contribute to the same reindex safely.

For continuous synchronization, use an autoload site module and deterministic refs. On save, upsert the approved projection. On trash/delete/unpublish, call `deleteRef()` for the same base ref. Avoid embedding synchronously in a latency-sensitive public request unless the product explicitly requires it.

```php
$text = trim($page->title . "\n\n" . $page->body);
$stored = $atlas->addChunked(
    'articles',
    'page-' . $page->id,
    $text,
    [
        'page_id' => $page->id,
        'title' => $page->title,
        'url' => $page->url,
        'source' => 'website',
    ]
);

if ($stored === 0 && $atlas->lastError() !== '') {
    $log->error($atlas->lastError());
}
```

Adapt field and language handling to the inspected project. Do not paste this example without confirming those fields and APIs exist on the target site.

### 5. Retrieve, validate, then render

Treat search results as candidate evidence, not automatically trusted output.

```php
$hits = $atlas->search('articles', $query, 5, [
    'filter' => ['source' => 'website'],
    'keyword' => $literalTerm,
    'mmr' => true,
    'mmrLambda' => 0.7,
]);

if (!$hits && $atlas->lastError() !== '') {
    // Provider/storage failure: log it and show an unavailable state.
} else {
    foreach ($hits as $hit) {
        // Re-resolve page_id and enforce current view access/publication rules.
        // Escape text and URLs for the final HTML context.
    }
}
```

An empty result with an empty `lastError()` means no match. An empty result with a non-empty `lastError()` means the search or storage operation failed. Never present both states as “nothing found.”

If hits are sent to an LLM, label them as untrusted reference material, retain source attribution, limit context size, and do not let retrieved text override system or project instructions.

### 6. Plan lifecycle and rollback

Every integration must define:

- when entries are created or refreshed;
- how removed/unpublished content is deleted;
- how provider/model changes trigger a rebuild;
- how a failed embedding call is surfaced;
- how the feature behaves while Squad is unavailable;
- how test collections are removed;
- whether a previous live collection must remain available during rebuild.

Prefer the ProcessAtlas atomic reindex flow for full-site replacement. It builds an internal staging collection and promotes it only after all batches succeed. The admin reindex defaults to the first 1,000 selector matches and allows an administrator to raise a reviewed job to at most 10,000 pages. Use a focused selector and design another reviewed storage or batching strategy when a project exceeds that boundary.

## Public application API

Obtain the module through ProcessWire:

```php
$atlas = $modules->get('Atlas');
```

Common calls:

| Call | Purpose | Side effects |
|---|---|---|
| `isReady(): bool` | Check whether Squad embeddings are available | May inspect provider configuration |
| `readinessError(): string` | Diagnose the latest readiness failure | None |
| `lastError(): string` | Diagnose the latest Atlas operation | None |
| `add($collection, $ref, $text, $meta = [], $opts = []): bool` | Embed and upsert one entry | External embedding call and database write |
| `addMany($collection, $items, $opts = []): int` | Atomically embed and upsert a batch | External embedding call and database transaction |
| `addChunked($collection, $ref, $text, $meta = [], $opts = [], $maxChars = 1500): int` | Atomically replace one chunked document | External embedding call and database transaction |
| `chunkText($text, $maxChars = 1500): array` | Split text without storing it | None |
| `search($collection, $query, $topK = 5, $opts = []): array` | Embed a query and retrieve ranked entries | External embedding call; reads collection rows |
| `lexicalSearch($collection, $query, $topK = 5, $opts = []): array` | Retrieve by significant local terms before semantic fallback | Database read only |
| `delete($collection, $ref): bool` | Delete one exact stored ref | Destructive database write |
| `deleteRef($collection, $baseRef): bool` | Delete a base ref and all chunks | Destructive database write |
| `clearCollection($collection): bool` | Remove an entire collection | Highly destructive database write |
| `count($collection): int` | Count collection entries | Database read |
| `collections(): array` | List collection names and sizes | Database read |
| `entries($collection, $limit = 50, $offset = 0): array` | Browse entries without vectors | Database read |
| `stats($collection): array` | Read aggregate collection statistics | Database read |

`search()` options:

- `filter`: metadata equality filters; every key must match;
- `keyword`: add a literal text-match boost;
- `keywordRequire`: exclude entries without the literal keyword;
- `mmr`: diversify near-duplicate results;
- `mmrLambda`: relevance/diversity balance from 0 to 1;
- provider/model options supported by Squad.

See [DOCUMENTATION.md](DOCUMENTATION.md) for exact signatures, result shapes, identifier limits, and failure behavior.

## ProcessAtlas integration hooks

Register class hooks from `init()` in an autoload ProcessWire module. Do not patch `ProcessAtlas.module.php` for project-specific indexing.

Atlas' default adapter indexes labelled values from common ProcessWire semantic fields, including ProFields Table, Combo, Textareas, Multiplier and Repeater Matrix plus core Repeater/PageTable values. Composite fields use indented multi-line blocks so labels and nested values remain unambiguous. Image/File values are projected as absolute HTTP URLs only, associative arrays retain keys, and unknown iterable objects are not flattened. It adds hierarchy context to short pages and stores universal `template`, `name`, `path` and `parent_id` metadata. It intentionally ignores media/file internals, Repeater system fields and presentation-only fields. Keep this baseline domain-neutral and use public field/value APIs so optional ProFields modules remain optional.

### `ProcessAtlas::buildPageFieldText`

Use an after hook to format, add or suppress one field while retaining the standard document adapter. Return an empty string to exclude it. Prefer this hook over replacing the whole document when only field projection differs.

### `ProcessAtlas::buildPageDocument`

Use an after hook to change page text/metadata or set `skip` to `true`. The returned document participates in incremental hashing, so changed hook output is re-embedded.

```php
$this->addHookAfter('ProcessAtlas::buildPageDocument', function(HookEvent $event) {
    $page = $event->arguments(0);
    $document = $event->return;

    if ($page->template->name === 'private') {
        $document['skip'] = true;
    } else {
        $document['text'] .= "\n\n" . (string) $page->summary;
        $document['meta']['application'] = 'website';
    }

    $event->return = $document;
});
```

### `ProcessAtlas::reindexReady`

Use an after hook to add external application documents to the internal staging collection before promotion. Throw on partial failure so the live collection remains unchanged. Do not persist or expose the staging collection name outside the active job.

### Success notifications

- `ProcessAtlas::pageIndexed(Page $page, string $collection, array $state)` runs after page index metadata is saved.
- `ProcessAtlas::collectionReindexed(string $collection, array $summary)` runs after successful promotion.

Notification hook failures are logged but do not roll back an already promoted collection. Full hook contracts and examples are in [DOCUMENTATION.md](DOCUMENTATION.md#processatlas-reindex-hooks).

## Internal and maintenance APIs

Do not use these for normal website features:

- `AtlasStore` methods or direct SQL against `atlas_vectors`;
- `replaceCollection()`;
- `copyRefs()`;
- `baseRefCounts()`;
- `cleanupStaging()`;
- `embeddingIdentity()`;
- the `$page->meta('atlas_index')` structure owned by ProcessAtlas;
- collections beginning with `__atlas_stage_`;
- `ProcessAtlas` POST actions or session job data as an application API.

They support storage, incremental reindexing, and administration. Use them only when maintaining Atlas itself or implementing a reviewed custom storage/reindex adapter with tests.

## Safety and approval rules

Safe without additional approval when already within the user's requested scope:

- read Atlas documentation and module metadata;
- inspect whether Atlas/Squad are installed and ready;
- call `collections()`, `count()`, `entries()`, or `stats()` for diagnostics;
- propose a collection/ref/metadata design;
- run local unit or smoke tests that do not contact an external provider.

Requires explicit user approval or an already approved Action Plan:

- install or uninstall Atlas, ProcessAtlas, or Squad;
- send site content or user queries to an embedding provider;
- create or update production embeddings;
- start a full or incremental reindex;
- change provider/model or collection boundaries;
- index private, personal, regulated, or unpublished content;
- delete refs, clear collections, or remove the module table;
- run tests that incur provider cost or mutate production data.

High risk:

- `clearCollection()` on a non-test collection;
- uninstalling Atlas, because uninstall drops `atlas_vectors`;
- direct manipulation of staging collections;
- changing access assumptions without rebuilding and revalidating retrieval;
- displaying retrieved private content without current ProcessWire permission checks.

For destructive work, state the collection and expected impact, preserve or verify a rollback path, and confirm the result afterward. Use a clearly named disposable collection for integration tests and remove it when finished.

## Common mistakes

- Assuming Atlas is installed because this file exists.
- Treating metadata filters as authorization.
- Indexing rendered HTML, navigation, secrets, or every field indiscriminately.
- Using random refs, which prevents reliable updates and deletion.
- Calling `delete()` on a base ref when the document was written with `addChunked()`; use `deleteRef()`.
- Mixing embedding models or dimensions in one collection.
- Treating `[]` as “no matches” without checking `lastError()`.
- Calling embeddings repeatedly inside loops instead of using `addMany()`.
- Using semantic search for exact database facts.
- Trusting retrieved text as instructions.
- Editing Atlas core files for a site-specific projection instead of using hooks.
- Using the admin UI permission as a substitute for authorization in custom frontend/API code.

## Validation checklist

Before declaring an Atlas-powered website feature complete:

- verify readiness and provider failure behavior;
- test one add/update/delete lifecycle with deterministic refs;
- test a valid query, a no-match query, and a provider/storage failure state;
- verify current ProcessWire access checks on every displayed source;
- confirm language/tenant filters cannot leak content across boundaries;
- verify HTML output is escaped and source links are validated;
- test repeated indexing for stale chunk removal and idempotent results;
- confirm collection size and response time remain within the intended scale;
- document provider cost, data transfer, rebuild, and rollback behavior;
- remove test collections and confirm production collections are unchanged.

Run the module's dependency-free checks with:

```bash
php tests/run.php
```

Database, ProcessWire, Squad, provider, permissions, and frontend rendering still require integration testing on a non-production site or an explicitly approved production test scope.
