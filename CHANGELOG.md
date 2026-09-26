# Changelog

All notable changes to Atlas are documented here. Versions follow [Semantic Versioning](https://semver.org/).

## [1.2.2] — 2026-09-26

### Fixed

- Explicitly refresh vector modification timestamps during upserts and staging
  promotion so SQLite retains freshness, statistics, and purge semantics even
  though it ignores MySQL's automatic `ON UPDATE` clause.

## [1.2.1] — 2026-09-26

### Fixed

- Made transactional collection replacement compatible with SQLite by relying on ProcessWire's `BEGIN IMMEDIATE` write lock there while retaining `FOR UPDATE` row locks on MySQL and PostgreSQL.
- Documented MySQL, SQLite, and PostgreSQL storage support through ProcessWire's database layer.

## [1.2.0] — 2026-07-24

### Added

- Added `lexicalSearch()` as a low-latency local retrieval pass that avoids an external embedding request and returns normalized relevance scores.
- Added filtering and a configurable minimum score to lexical retrieval so integrations can safely fall back to semantic `search()` when local matches are weak.

## [1.1.1] — 2026-07-23

### Fixed

- Streamed vector rows from MySQL without PDO buffering the full collection in memory, allowing reviewed multi-thousand-entry collections to remain within normal PHP memory limits.
- Restored the connection's buffered-query mode after every search, including failure paths.

## [1.1.0] — 2026-07-23

### Added

- A per-reindex maximum-pages control, bounded between 1 and 10,000 pages.

### Changed

- Kept the safe default at 1,000 pages while allowing reviewed, focused collections to grow beyond that boundary.
- Clarified that large sites should exclude presentation/media records and keep each lightweight Atlas collection intentionally scoped.

## [1.0.0] — 2026-07-13 · first public release

### Added

- Lightweight ProcessWire RAG store backed by one MySQL/MariaDB table, with embeddings supplied through Squad.
- Public APIs for adding, batching, chunking, searching, deleting and inspecting entries and collections.
- Cosine similarity retrieval, metadata filters, literal keyword boosting and optional Maximal Marginal Relevance diversification.
- Paragraph- and sentence-aware document chunking with chunk metadata and chunk-aware deletion.
- Transactional batch writes and atomic document or full-collection replacement that preserve previous data when embedding fails.
- Collection model and vector-dimension consistency checks, bounded streaming retrieval and deterministic ranking.
- Setup → Atlas admin workspace with Overview, Collections, Search and Reindex screens.
- Resumable batched reindexing with progress, cancellation, capped retries, staging promotion and incremental reuse of unchanged vectors through `$page->meta()`.
- Domain-neutral ProcessWire page extraction for common core fields, Page references, Images/Files and ProFields Table, Combo, Textareas, Multiplier, Repeater and Repeater Matrix.
- Structured labelled Content, absolute media URLs, source metadata, hierarchy context for short pages and filtering of presentation/system internals.
- Hookable field and document projections, external staging contributions, and successful-index notifications for integrations that extend Atlas without patching it.
- Dedicated `atlas-admin` permission, CSRF-protected mutations, identifier validation and protected internal staging namespaces.
- Installation, API, integration and AI-agent documentation with dependency-free smoke tests.

### Requirements

- ProcessWire 3.0.200 or newer.
- Squad with an embedding-capable provider configured.
- MySQL or MariaDB with InnoDB and `utf8mb4` support.
