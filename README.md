# Atlas

Atlas adds a lightweight retrieval-augmented generation (RAG) store to ProcessWire: it embeds text, keeps vectors in MySQL and returns the most relevant entries for a query.

![Atlas](assets/Atlas.png)

It is made for small and medium knowledge bases where semantic search and grounded AI responses should live inside ProcessWire without requiring pgvector, Qdrant or another external vector service.

**Author:** Maxim Semenov<br>
**Website:** [smnv.org](https://smnv.org)<br>
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Atlas Does

- Produces provider-independent embeddings through [Squad](https://github.com/mxmsmnv/Squad).
- Organizes entries into independent collections.
- Splits long documents into paragraph- and sentence-aware chunks.
- Searches by cosine similarity with optional literal keyword boosting.
- Supports metadata filtering and Maximal Marginal Relevance (MMR) diversification.
- Keeps each collection consistent with one embedding model and vector dimension.
- Atomically replaces chunked documents and full-site indexes, preserving the previous data if embedding fails.
- Builds domain-neutral page documents from core fields and optional ProFields, with labelled nested content and absolute media URLs.
- Exposes ProcessWire hooks for custom field projections, external document sources and indexing notifications.
- Stores everything in one MySQL table with no additional service dependency.
- Provides a ProcessWire admin area for browsing, searching, adding, deleting and rebuilding indexed content.

Atlas is intended for collections up to roughly 10,000 entries. Larger corpora should use a dedicated vector database behind a custom storage implementation.

## Admin Area

The optional `ProcessAtlas` module adds a responsive **Setup > Atlas** workspace with dedicated Overview, Collections, Search and Reindex views, where site editors can:

- check embedding-provider readiness and index totals;
- browse collections and their statistics;
- inspect paginated entries, structured content and source metadata;
- add individual entries and create collections;
- search with semantic similarity, keyword boosting and MMR;
- delete entries or clear a collection;
- build an atomic knowledge-base index from public site pages with live batched progress and cancellation.

## PHP API

```php
$atlas = $modules->get('Atlas');

$atlas->add(
    'faq',
    'password-reset',
    'Use the password reset form on the account page.',
    ['url' => '/account/reset']
);

$hits = $atlas->search('faq', 'I forgot my password', 3);
```

Atlas also provides batch insertion, document chunking, metadata filters, hybrid keyword search, MMR diversification, collection statistics and chunk-aware deletion. See the API documentation for the complete method and option reference.

### Reindex hooks

The default adapter is domain-neutral: it indexes labelled values from common ProcessWire fields (including ProFields Table, Combo, Textareas, Multiplier and Repeater Matrix), adds hierarchy context to title-only pages, ignores presentation fields, and records template/path metadata. Optional field modules are detected through public APIs and are not Atlas dependencies. Other modules can refine one field through `ProcessAtlas::buildPageFieldText`, or replace/extend the complete document through `ProcessAtlas::buildPageDocument` without changing Atlas core. For example:

```php
$wire->addHookAfter('ProcessAtlas::buildPageDocument', function(HookEvent $event) {
    $page = $event->arguments(0);
    $document = $event->return;

    $document['text'] .= "\n" . (string) $page->summary;
    $document['meta']['source_app'] = 'catalog';
    $event->return = $document;
});
```

`ProcessAtlas::reindexReady` can add documents from another application directly to the internal staging collection before it is promoted. Success notifications are available through `ProcessAtlas::pageIndexed` and `ProcessAtlas::collectionReindexed`. See [DOCUMENTATION.md](DOCUMENTATION.md#processatlas-reindex-hooks) for the contracts and a complete external-source example.

## Requirements

- ProcessWire 3.0.200 or newer.
- [Squad](https://github.com/mxmsmnv/Squad), installed and configured with an embedding-capable provider.
- MySQL or MariaDB with InnoDB and `utf8mb4` support.

Atlas `1.0.0` is the first public release. ProcessWire represents this module version internally as `100`.

## Installation

1. Copy the `Atlas` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules.
3. Install `Atlas`; this creates the `atlas_vectors` table.
4. Optionally install `ProcessAtlas` to add **Setup > Atlas**.
5. Configure an embedding provider in Squad before indexing or searching.

## Documentation

See [DOCUMENTATION.md](DOCUMENTATION.md) for the complete PHP API, search options, result format, schema and operating limits.

See [CHANGELOG.md](CHANGELOG.md) for the release notes.

Run the dependency-free algorithm and security smoke tests with:

```bash
php tests/run.php
```

Database, ProcessWire and Squad integration should additionally be tested in a non-production ProcessWire installation.

## Author

Maxim Semenov<br>
[smnv.org](https://smnv.org)<br>
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

[MIT](LICENSE)
