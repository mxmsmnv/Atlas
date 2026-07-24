<?php namespace ProcessWire;

/** Dependency-free smoke tests for Atlas' pure algorithms. Run: php tests/run.php */
interface Module {}
class Wire {
	public $services = [];
	public $notices = [];
	public function wire(...$args) { return count($args) === 1 && is_string($args[0]) ? ($this->services[$args[0]] ?? null) : ($args[0] ?? null); }
	public function __call($name, $args) {
		$hookable = '___' . $name;
		if (method_exists($this, $hookable)) return $this->{$hookable}(...$args);
		throw new \BadMethodCallException('Undefined method ' . $name);
	}
	public function _($text) { return $text; }
	public function _n($one, $many, $count) { return $count === 1 ? $one : $many; }
	public function message($text) { $this->notices[] = ['message', $text]; }
	public function error($text) { $this->notices[] = ['error', $text]; }
}
class WireData extends Wire {}
class Process extends Wire {}
class Inputfield { const collapsedNo = 0; const collapsedYes = 1; }
class Page {
	public $id;
	public $title;
	public $url;
	public $name = '';
	public $path = '';
	public $parent_id = 0;
	public $template;
	public $metadata = [];
	public $values = [];
	public $parentPages = [];
	public function __construct(int $id, string $title) { $this->id = $id; $this->title = $title; $this->url = '/page-' . $id . '/'; $this->template = (object)['fieldgroup' => []]; }
	public function get($key) { return $this->values[$key] ?? null; }
	public function parents() { return $this->parentPages; }
	public function meta($key, $value = null) {
		if (func_num_args() > 1) { $this->metadata[$key] = $value; return $this; }
		return $this->metadata[$key] ?? null;
	}
}
class TestFieldType {
	private $name;
	public $textareaFields = [];
	public function __construct(string $name) { $this->name = $name; }
	public function __toString(): string { return $this->name; }
	public function className(): string { return $this->name; }
	public function getTextareaFields($field): array { return $this->textareaFields; }
}
class TestField {
	public $name;
	public $label;
	public $type;
	public $settings = [];
	public function __construct(string $name, string $label, string $type) { $this->name = $name; $this->label = $label; $this->type = new TestFieldType($type); }
	public function get($key) { return $this->settings[$key] ?? null; }
}
class TestRecord {
	public $values;
	public function __construct(array $values) { $this->values = $values; }
	public function get($key) { return $this->values[$key] ?? null; }
}
class TestRows implements \IteratorAggregate {
	private $rows;
	private $columns;
	public function __construct(array $rows, array $columns) { $this->rows = $rows; $this->columns = $columns; }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
	public function getColumns(): array { return $this->columns; }
}
class TestComboSubfield {
	private $label;
	private $options;
	public function __construct(string $label, $options = null) { $this->label = $label; $this->options = $options; }
	public function getLabel(): string { return $this->label; }
	public function get($key) { return $key === 'options' ? $this->options : null; }
}
class TestComboValue extends TestRecord {
	private $subfields;
	public function __construct(array $values, array $subfields) { parent::__construct($values); $this->subfields = $subfields; }
	public function getSubfields(): array { return $this->subfields; }
}
class SelectableOption implements \IteratorAggregate {
	public $title;
	public function __construct(string $title) { $this->title = $title; }
	public function getIterator(): \Traversable { return new \ArrayIterator([1, 0, $this->title, 'internal']); }
}
class SelectableOptionArray implements \IteratorAggregate {
	private $options;
	public function __construct(array $options) { $this->options = $options; }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->options); }
}
class Pageimage {
	public $httpUrl;
	public $filesize = 12345;
	public function __construct(string $httpUrl) { $this->httpUrl = $httpUrl; }
	public function getIterator(): \Traversable { return new \ArrayIterator([$this->filesize, 'internal']); }
}
class Pageimages implements \IteratorAggregate {
	private $images;
	public function __construct(array $images = []) { $this->images = $images ?: [new Pageimage('https://example.test/site/assets/files/30/photo.jpg')]; }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->images); }
}
class TestInternalIterable implements \IteratorAggregate {
	private $values;
	public function __construct(array $values) { $this->values = $values; }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->values); }
}
class TestMatrixPage extends Page {
	public $matrixName = '';
	public $matrixN = 0;
	public function matrix($property) { return $property === 'n' ? $this->matrixN : $this->matrixName; }
}
function __($text) { return $text; }
function wireIconMarkup($icon) { return $icon; }

require_once dirname(__DIR__) . '/Atlas.module.php';
require_once dirname(__DIR__) . '/ProcessAtlas.module.php';

class TestAtlas extends Atlas {
	public function chunks(string $text, int $max): array { return $this->chunk($text, $max); }
}
class StoreBackedAtlas extends Atlas {
	public function useStore(AtlasStore $store): void { $this->store = $store; }
}
class ThrowingAtlas extends Atlas {
	protected function squad() {
		return new class {
			public function embed($input, $opts) { throw new \RuntimeException('provider offline'); }
		};
	}
}
class EmbedSpyAtlas extends Atlas {
	public $embedCalls = 0;
	protected function embed($input, array $opts, string $operation): ?array {
		$this->embedCalls++;
		return ['success' => true, 'embedding' => [1.0], 'embeddings' => [[1.0]], 'model' => 'test'];
	}
}
class MalformedBatchAtlas extends Atlas {
	protected function embed($input, array $opts, string $operation): ?array {
		return ['success' => true, 'embeddings' => [[1.0, 0.0], [1.0]], 'model' => 'test'];
	}
}
class PutManySpyStore extends AtlasStore {
	public $calls = 0;
	public function putMany(string $collection, array $items): int { $this->calls++; return count($items); }
}
class LexicalTestStore extends AtlasStore {
	public function tokens(string $query): array { return $this->lexicalTokens($query); }
	public function score(string $text, string $title, array $tokens): float {
		return $this->lexicalScore($text, $title, $tokens);
	}
}
class BatchFacadeAtlas extends Atlas {
	public function useStore(AtlasStore $store): void { $this->store = $store; }
	protected function embed($input, array $opts, string $operation): ?array {
		return ['success' => true, 'embeddings' => array_map(fn($text) => [1.0, 0.0], $input), 'model' => 'test'];
	}
}
class TestProcessAtlas extends ProcessAtlas {
	public function url(string $url): string { return $this->safeUrl($url); }
	public function batch($atlas): array { $job = $this->wire('session')->get('AtlasReindexJob'); return $this->processReindexBatch($atlas, (string)($job['token'] ?? '')); }
	public function batchWithToken($atlas, string $token): array { return $this->processReindexBatch($atlas, $token); }
	public function cancelJob($atlas, string $token): bool { return $this->cancelReindex($atlas, $token); }
	public function results($atlas): string { return $this->renderResults($atlas, 'docs', 'query', '', false, true, 5, new TestSanitizer()); }
	public function addEntry($atlas, $input): bool { return $this->doAddEntry($atlas, $input, new TestSanitizer()); }
	public function overview($atlas): string { return $this->renderOverview($atlas); }
	public function start($atlas, $input): void { $this->startReindex($atlas, $input, new TestSanitizer()); }
	public function reindex($atlas): string { return $this->renderReindex($atlas); }
	public function document(Page $page, string $collection = 'site'): array { return $this->buildPageDocument($page, $collection); }
}
class FieldHookProcessAtlas extends TestProcessAtlas {
	public function ___buildPageFieldText(Page $page, $field): string {
		if (($field->name ?? '') === 'custom') return 'Custom projection';
		return parent::___buildPageFieldText($page, $field);
	}
}
class HookLifecycleProcessAtlas extends TestProcessAtlas {
	public $readyCalls = 0;
	public $pageIndexedCalls = 0;
	public $collectionSummary = [];
	public function ___buildPageDocument(Page $page, string $collection): array {
		return ['text' => 'Hooked ' . $page->title, 'meta' => ['source_app' => 'test-app'], 'skip' => false];
	}
	public function ___reindexReady($atlas, string $collection, string $staging, array $summary): array {
		$this->readyCalls++;
		$summary['chunks'] += 2;
		return $summary;
	}
	public function ___pageIndexed(Page $page, string $collection, array $state): void { $this->pageIndexedCalls++; }
	public function ___collectionReindexed(string $collection, array $summary): void { $this->collectionSummary = $summary; }
}
class FailingHookProcessAtlas extends TestProcessAtlas {
	public function ___reindexReady($atlas, string $collection, string $staging, array $summary): array {
		throw new \RuntimeException('external application offline');
	}
}

class TestSession {
	public $data = [];
	public function get($key) { return $this->data[$key] ?? null; }
	public function set($key, $value) { $this->data[$key] = $value; }
	public function remove($key) { unset($this->data[$key]); }
}
class TestPages {
	public $pages = [];
	public $findResult = [];
	public $lastSelector = '';
	public function get($id) { return $this->pages[$id] ?? new Page(0, ''); }
	public function find($selector) { $this->lastSelector = $selector; return $this->findResult; }
}
class TestSanitizer {
	public function entities($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
	public function text($value) { return trim(strip_tags((string)$value)); }
	public function textarea($value) { return trim(strip_tags((string)$value)); }
}
class TestLog { public $errors = []; public function error($message) { $this->errors[] = $message; } }
class ThrowingDatabase {
	public function prepare($sql) { throw new \RuntimeException('database offline'); }
	public function query($sql) { throw new \RuntimeException('database offline'); }
}
class ThrowingModules { public function isInstalled($name) { throw new \RuntimeException('modules unavailable'); } }
class DdlDatabase {
	public $creates = 0;
	public $drops = 0;
	public function exec($sql) {
		if (strpos($sql, 'CREATE TABLE') !== false) $this->creates++;
		if (strpos($sql, 'DROP TABLE') !== false) $this->drops++;
		return 0;
	}
}
class BatchWriteStatement {
	private $db;
	private $insert;
	public function __construct($db, bool $insert) { $this->db = $db; $this->insert = $insert; }
	public function execute($args = []) {
		if (!$this->insert) { $this->db->compatibilityQueries++; return true; }
		$this->db->writes++;
		if ($this->db->failAt > 0 && $this->db->writes === $this->db->failAt) throw new \RuntimeException('batch write failed');
		return true;
	}
	public function fetch($mode = null) { return false; }
}
class BatchWriteDatabase {
	public $compatibilityQueries = 0;
	public $insertPrepares = 0;
	public $writes = 0;
	public $lockQueries = 0;
	public $failAt = 0;
	public $committed = false;
	public $rolledBack = false;
	private $transaction = false;
	public function exec($sql) { return 0; }
	public function prepare($sql) {
		if (strpos($sql, 'FOR UPDATE') !== false) $this->lockQueries++;
		$insert = strpos($sql, 'INSERT INTO') !== false;
		if ($insert) $this->insertPrepares++;
		return new BatchWriteStatement($this, $insert);
	}
	public function beginTransaction() { $this->transaction = true; return true; }
	public function inTransaction() { return $this->transaction; }
	public function commit() { $this->committed = true; $this->transaction = false; return true; }
	public function rollBack() { $this->rolledBack = true; $this->transaction = false; return true; }
}
class ArrayStatement {
	private $rows;
	private $index = 0;
	public function __construct(array $rows) { $this->rows = $rows; }
	public function execute($args = []) { $this->index = 0; return true; }
	public function fetch($mode = null) { return $this->rows[$this->index++] ?? false; }
}
class ArrayDatabase {
	private $rows;
	public function __construct(array $rows) { $this->rows = $rows; }
	public function prepare($sql) { return new ArrayStatement($this->rows); }
}
class PromotionStatement {
	private $db;
	private $sql;
	public function __construct($db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($args = []) {
		if (strpos($this->sql, 'DELETE FROM') !== false) $this->db->targetDeleted = true;
		if (strpos($this->sql, 'UPDATE') !== false) $this->db->promoted = $this->db->hasStaging ? 1 : 0;
		return true;
	}
	public function fetchColumn() { return $this->db->hasStaging ? 1 : false; }
	public function rowCount() { return $this->db->promoted; }
}
class PromotionDatabase {
	public $hasStaging;
	public $targetDeleted = false;
	public $promoted = 0;
	public $committed = false;
	public $rolledBack = false;
	private $transaction = false;
	public function __construct(bool $hasStaging) { $this->hasStaging = $hasStaging; }
	public function prepare($sql) { return new PromotionStatement($this, $sql); }
	public function beginTransaction() { $this->transaction = true; return true; }
	public function inTransaction() { return $this->transaction; }
	public function commit() { $this->committed = true; $this->transaction = false; return true; }
	public function rollBack() { $this->rolledBack = true; $this->transaction = false; return true; }
}
class RefStatement {
	private $db;
	private $sql;
	private $rows = [];
	private $index = 0;
	public function __construct($db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($args = []) {
		$this->db->lastArgs = $args;
		if (strpos($this->sql, 'GROUP BY base_ref') !== false) $this->rows = $this->db->countRows;
		if (strpos($this->sql, 'INSERT INTO') !== false && strpos($this->sql, 'SUBSTRING_INDEX') !== false) $this->db->copied = $this->db->copyCount;
		return true;
	}
	public function fetch($mode = null) { return $this->rows[$this->index++] ?? false; }
	public function rowCount() { return $this->db->copied; }
}
class RefDatabase {
	public $countRows = [];
	public $copyCount = 0;
	public $copied = 0;
	public $lastArgs = [];
	public function exec($sql) { return 0; }
	public function prepare($sql) { return new RefStatement($this, $sql); }
}
class TestInput {
	public $values = [];
	public function post($key) { return $this->values[$key] ?? null; }
}
class TestAddAtlas {
	public $ready = true;
	public $succeed = false;
	public $cleanupError = '';
	public $lastMeta = [];
	public function isReady() { return $this->ready; }
	public function add($collection, $ref, $text, $meta) { $this->lastMeta = $meta; return $this->succeed; }
	public function addChunked($collection, $ref, $text, $meta) { $this->lastMeta = $meta; return $this->succeed ? 2 : 0; }
	public function lastError() { return $this->cleanupError !== '' ? $this->cleanupError : ($this->succeed ? '' : 'provider offline'); }
	public function cleanupStaging() { return 0; }
	public function clearCollection($collection) { return true; }
	public function baseRefCounts($collection) { return []; }
	public function embeddingIdentity() { return 'test:test-model'; }
	public function chunkText($text) { return [$text]; }
	public function copyRefs($source, $staging, $refs) { return 0; }
}
class EmptySearchAtlas {
	public $error = '';
	public function search($collection, $query, $topK, $opts) { return []; }
	public function lastError() { return $this->error; }
}
class TestBatchAtlas {
	public $fail = false;
	public $promoteFail = false;
	public $error = '';
	public $promoted = 0;
	public $cleared = [];
	public $addedItems = [];
	public function chunkText($text) { return [$text]; }
	public function addMany($collection, $items) { if ($this->fail) { $this->error = 'provider offline'; return 0; } $this->addedItems = array_merge($this->addedItems, $items); return count($items); }
	public function replaceCollection($target, $staging) { $this->promoted++; if ($this->promoteFail) { $this->error = 'promotion offline'; return false; } return true; }
	public function clearCollection($collection) { $this->cleared[] = $collection; return true; }
	public function lastError() { return $this->error; }
	public function stats($collection) { return ['models' => 'test-model']; }
}

class IncrementalAtlas extends TestAddAtlas {
	public $counts = [];
	public $copiedRefs = [];
	public function baseRefCounts($collection) { return $this->counts; }
	public function copyRefs($source, $staging, $refs) { $this->copiedRefs = $refs; return array_sum(array_intersect_key($this->counts, array_fill_keys($refs, true))); }
}

function check(bool $condition, string $message): void {
	if (!$condition) throw new \RuntimeException($message);
}

check(is_string(json_encode(['detail' => "\xB1"], JSON_INVALID_UTF8_SUBSTITUTE)), 'invalid UTF-8 diagnostics remain JSON-encodable');
$atlasInfo = Atlas::getModuleInfo();
$processInfo = ProcessAtlas::getModuleInfo();
check(($atlasInfo['version'] ?? null) === 120 && ($processInfo['version'] ?? null) === 120, 'public module versions are synchronized at ProcessWire version 120 / SemVer 1.2.0');
check(($processInfo['permission'] ?? '') === 'atlas-admin' && isset($processInfo['permissions']['atlas-admin']), 'admin module declares a dedicated permission');

$store = new AtlasStore();
check(abs($store->cosine([1, 0], [1, 0]) - 1.0) < 1e-12, 'identical cosine');
check(abs($store->cosine([1, 0], [0, 1])) < 1e-12, 'orthogonal cosine');
check(abs($store->cosine([1, 0], [-1, 0]) + 1.0) < 1e-12, 'opposite cosine');
check($store->nearest('docs', [], 5) === [] && $store->lastError() !== '', 'invalid query reports store error');
$lexical = new LexicalTestStore();
$lexicalTokens = $lexical->tokens('What is an Aperol Spritz, please?');
check($lexicalTokens === ['aperol', 'spritz'], 'lexical search removes question stop words');
check($lexical->score('Classic Aperol Spritz recipe', '', $lexicalTokens) === 0.75, 'lexical phrase receives a strong score');
check($lexical->score('Aperol cocktails', 'Aperol', $lexicalTokens) === 0.45, 'partial title match clears the fallback threshold');
check($lexical->score('Negroni recipe', 'Negroni', $lexicalTokens) === 0.0, 'unrelated lexical result scores zero');
$ddl = new DdlDatabase();
$ddlStore = new AtlasStore();
$ddlStore->services = ['database' => $ddl];
$ddlStore->ensureTable();
$ddlStore->ensureTable();
check($ddl->creates === 1, 'ensureTable runs DDL once per Store instance');
$ddlStore->dropTable();
$ddlStore->ensureTable();
check($ddl->drops === 1 && $ddl->creates === 2, 'dropTable resets ensure cache');

$batchRows = [
	['ref' => 'a', 'text' => 'A', 'meta' => [], 'vector' => [1.0, 0.0], 'model' => 'test'],
	['ref' => 'b', 'text' => 'B', 'meta' => [], 'vector' => [0.0, 1.0], 'model' => 'test'],
];
$batchDb = new BatchWriteDatabase();
$batchStore = new AtlasStore();
$batchStore->services = ['database' => $batchDb, 'log' => new TestLog()];
check($batchStore->putMany('docs', $batchRows) === 2, 'putMany stores complete batch');
check($batchDb->compatibilityQueries === 1 && $batchDb->lockQueries === 1 && $batchDb->insertPrepares === 1 && $batchDb->writes === 2 && $batchDb->committed, 'putMany locks compatibility and reuses prepared statement');
$failedBatchDb = new BatchWriteDatabase(); $failedBatchDb->failAt = 2;
$failedBatchStore = new AtlasStore();
$failedBatchStore->services = ['database' => $failedBatchDb, 'log' => new TestLog()];
check($failedBatchStore->putMany('docs', $batchRows) === 0 && $failedBatchDb->rolledBack, 'putMany rolls back partial database failure');

$singleDb = new BatchWriteDatabase();
$singleStore = new AtlasStore();
$singleStore->services = ['database' => $singleDb, 'log' => new TestLog()];
check($singleStore->put('docs', 'one', 'One', [1.0, 0.0], [], 'test') === true, 'single put commits');
check($singleDb->lockQueries === 1 && $singleDb->writes === 1 && $singleDb->committed, 'single put locks compatibility inside transaction');
$failedSingleDb = new BatchWriteDatabase(); $failedSingleDb->failAt = 1;
$failedSingleStore = new AtlasStore();
$failedSingleStore->services = ['database' => $failedSingleDb, 'log' => new TestLog()];
check($failedSingleStore->put('docs', 'one', 'One', [1.0, 0.0], [], 'test') === false && $failedSingleDb->rolledBack, 'single put rolls back failed upsert');

$replaceDb = new BatchWriteDatabase();
$replaceStore = new AtlasStore();
$replaceStore->services = ['database' => $replaceDb, 'log' => new TestLog()];
check($replaceStore->replaceRef('docs', 'doc', [['ref' => 'doc#0', 'text' => 'Chunk', 'meta' => [], 'vector' => [1.0, 0.0], 'model' => 'test']]) === true, 'replaceRef commits valid replacement');
check($replaceDb->lockQueries === 1 && $replaceDb->committed, 'replaceRef locks compatibility inside transaction');
check($store->replaceCollection('docs', 'ordinary') === false, 'promotion requires internal staging source');
check($store->replaceCollection(AtlasStore::STAGING_PREFIX . 'target', AtlasStore::STAGING_PREFIX . 'source') === false, 'promotion rejects internal target');
check($store->replaceRef('docs', 'doc', [
	['ref' => 'doc#0', 'vector' => [1.0, 0.0], 'model' => 'a'],
	['ref' => 'doc#1', 'vector' => [1.0], 'model' => 'a'],
]) === false && strpos($store->lastError(), 'dimensions') !== false, 'replacement rejects mixed dimensions before database access');
check($store->replaceRef('docs', 'doc', [
	['ref' => 'other#0', 'vector' => [1.0], 'model' => 'a'],
]) === false && strpos($store->lastError(), 'base document') !== false, 'replacement rejects unrelated ref');
check($store->replaceRef('docs', 'doc', [
	['ref' => 'doc#0', 'vector' => [1.0], 'model' => 'a'],
	['ref' => 'doc#1', 'vector' => [1.0], 'model' => 'b'],
]) === false && strpos($store->lastError(), 'different embedding models') !== false, 'replacement rejects mixed models');
check($store->replaceRef('docs', 'doc', [
	['ref' => 'doc#0', 'vector' => [1.0], 'model' => 'a'],
	['ref' => 'doc#0', 'vector' => [1.0], 'model' => 'a'],
]) === false && strpos($store->lastError(), 'Duplicate') !== false, 'replacement rejects duplicate refs');

$missingPromotionDb = new PromotionDatabase(false);
$promotionStore = new AtlasStore();
$promotionStore->services = ['database' => $missingPromotionDb, 'log' => new TestLog()];
check($promotionStore->replaceCollection('docs', AtlasStore::STAGING_PREFIX . 'missing') === false, 'missing staging fails promotion');
check(!$missingPromotionDb->targetDeleted && $missingPromotionDb->rolledBack, 'missing staging preserves target and rolls back');
check(strpos($promotionStore->lastError(), 'empty or missing') !== false, 'missing staging is diagnosable');

$validPromotionDb = new PromotionDatabase(true);
$promotionStore->services = ['database' => $validPromotionDb, 'log' => new TestLog()];
check($promotionStore->replaceCollection('docs', AtlasStore::STAGING_PREFIX . 'ready') === true, 'non-empty staging promotes');
check($validPromotionDb->targetDeleted && $validPromotionDb->promoted === 1 && $validPromotionDb->committed, 'successful promotion replaces target atomically');

$refDb = new RefDatabase();
$refDb->countRows = [['base_ref' => 'page-1', 'chunks' => 2], ['base_ref' => 'manual', 'chunks' => 1]];
$refStore = new AtlasStore();
$refStore->services = ['database' => $refDb, 'log' => new TestLog()];
check($refStore->baseRefCounts('site') === ['page-1' => 2, 'manual' => 1], 'base refs expose exact stored chunk counts');
$refDb->copyCount = 2;
check($refStore->copyRefs('site', AtlasStore::STAGING_PREFIX . 'copy', ['page-1']) === 2, 'complete refs copy into internal staging');
check($refDb->lastArgs === [AtlasStore::STAGING_PREFIX . 'copy', 'site', 'page-1'], 'copy binds staging, source, and refs without interpolation');
check($refStore->copyRefs('site', 'public-target', ['page-1']) === 0, 'copy rejects a non-staging target');

$failingStore = new AtlasStore();
$failingStore->services = ['database' => new ThrowingDatabase(), 'log' => new TestLog()];
check($failingStore->nearest('docs', [1.0], 5) === [], 'database exception returns empty result');
check(strpos($failingStore->lastError(), 'database offline') !== false, 'database exception is diagnosable');
$storeBackedAtlas = new StoreBackedAtlas();
$storeBackedAtlas->useStore($failingStore);
check($storeBackedAtlas->collections() === [], 'facade returns empty collections on read failure');
check(strpos($storeBackedAtlas->lastError(), 'database offline') !== false, 'facade preserves read failure');

$rows = [];
for ($i = 199; $i >= 0; $i--) {
	$rows[] = ['ref' => 'ref-' . $i, 'text' => 'Item ' . $i, 'meta' => '{}', 'model' => 'test', 'vector' => json_encode([1.0, $i / 100.0])];
}
$boundedStore = new AtlasStore();
$boundedStore->services = ['database' => new ArrayDatabase($rows), 'log' => new TestLog()];
$top = $boundedStore->nearest('docs', [1.0, 0.0], 3, ['model' => 'test']);
check(array_column($top, 'ref') === ['ref-0', 'ref-1', 'ref-2'], 'bounded pool preserves exact top K');
$diverse = $boundedStore->nearest('docs', [1.0, 0.0], 5, ['model' => 'test', 'mmr' => true]);
check(count($diverse) === 5 && $diverse[0]['ref'] === 'ref-0', 'bounded pool preserves MMR shortlist');

$edgeRows = [
	['ref' => 'z-tie', 'text' => 'Z', 'meta' => '{}', 'model' => 'test', 'vector' => '[1,0]'],
	['ref' => 'corrupt', 'text' => 'Bad', 'meta' => '{}', 'model' => 'test', 'vector' => '[1,"bad"]'],
	['ref' => 'a-tie', 'text' => 'A', 'meta' => '{}', 'model' => 'test', 'vector' => '[1,0]'],
];
$edgeStore = new AtlasStore();
$edgeStore->services = ['database' => new ArrayDatabase($edgeRows), 'log' => new TestLog()];
$edgeHits = $edgeStore->nearest('docs', [1.0, 0.0], 5, ['model' => 'test']);
check(array_column($edgeHits, 'ref') === ['a-tie', 'z-tie'], 'corrupt vectors are skipped and score ties are deterministic');
check($edgeStore->nearest('docs', [1.0e308, 1.0e308], 5) === [] && strpos($edgeStore->lastError(), 'magnitude') !== false, 'overflowing query magnitude is rejected');

$dimensionStore = new AtlasStore();
$dimensionStore->services = ['database' => new ArrayDatabase([
	['ref' => 'old', 'text' => 'Old', 'meta' => '{}', 'model' => 'test', 'vector' => '[1,0,0]'],
]), 'log' => new TestLog()];
check($dimensionStore->nearest('docs', [1.0, 0.0], 5, ['model' => 'test']) === [] && strpos($dimensionStore->lastError(), 'dimensions') !== false, 'dimension mismatch is not reported as no matches');
$modelStore = new AtlasStore();
$modelStore->services = ['database' => new ArrayDatabase([
	['ref' => 'old', 'text' => 'Old', 'meta' => '{}', 'model' => 'old-model', 'vector' => '[1,0]'],
]), 'log' => new TestLog()];
check($modelStore->nearest('docs', [1.0, 0.0], 5, ['model' => 'new-model']) === [] && strpos($modelStore->lastError(), 'model') !== false, 'model mismatch is not reported as no matches');

$throwingAtlas = new ThrowingAtlas();
check($throwingAtlas->search('docs', 'query') === [], 'provider exception returns empty result');
check(strpos($throwingAtlas->lastError(), 'provider offline') !== false, 'provider exception is diagnosable');

$readinessAtlas = new TestAtlas();
$readinessAtlas->services = ['modules' => new ThrowingModules(), 'log' => new TestLog()];
check($readinessAtlas->isReady() === false, 'readiness exception returns false');
check(strpos($readinessAtlas->readinessError(), 'modules unavailable') !== false, 'readiness exception is diagnosable');

$embedSpy = new EmbedSpyAtlas();
check($embedSpy->add('', 'ref', 'text') === false, 'add rejects empty collection');
check($embedSpy->add('docs', str_repeat('r', 191), 'text') === false, 'add rejects oversized ref');
check($embedSpy->addMany('docs', [['ref' => str_repeat('r', 191), 'text' => 'text']]) === 0, 'addMany rejects invalid batch ref');
check($embedSpy->addChunked('docs', '', 'text') === 0, 'addChunked rejects empty ref');
check($embedSpy->search('', 'query') === [], 'search rejects empty collection');
check($embedSpy->embedCalls === 0, 'identifier preflight avoids embedding API calls');
$malformedBatch = new MalformedBatchAtlas();
check($malformedBatch->addMany('docs', [['ref' => 'a', 'text' => 'A'], ['ref' => 'b', 'text' => 'B']]) === 0, 'mixed batch dimensions fail before storage');
check(strpos($malformedBatch->lastError(), 'inconsistent dimensions') !== false, 'mixed batch dimensions are diagnosable');
$putManySpy = new PutManySpyStore();
$batchFacade = new BatchFacadeAtlas(); $batchFacade->useStore($putManySpy);
check($batchFacade->addMany('docs', [['ref' => 'a', 'text' => 'A'], ['ref' => 'b', 'text' => 'B']]) === 2 && $putManySpy->calls === 1, 'Atlas addMany delegates one atomic Store batch');

$atlas = new TestAtlas();
$chunks = $atlas->chunks(str_repeat('a', 250) . "\n\n" . str_repeat('b', 250), 200);
check(count($chunks) === 4, 'hard chunk count');
foreach ($chunks as $chunk) check(mb_strlen($chunk) <= 200, 'chunk size bound');
check($atlas->addChunked('docs', str_repeat('r', 181), 'text') === 0, 'chunked ref length guard');

$process = new TestProcessAtlas();
$process->services = ['sanitizer' => new TestSanitizer()];
check(strpos($process->overview($storeBackedAtlas), 'Knowledge data is temporarily unavailable') !== false, 'overview distinguishes storage failure from empty data');
$storeBackedAtlas->collections();
check(strpos($process->reindex($storeBackedAtlas), 'Knowledge data is temporarily unavailable') !== false, 'reindex is gated by storage health');
check($process->url('/docs/page') === '/docs/page', 'relative URL');
check($process->url('https://example.com/a') === 'https://example.com/a', 'HTTPS URL');
check($process->url('javascript:alert(1)') === '', 'javascript URL blocked');
check($process->url('//evil.example') === '', 'protocol-relative URL blocked');
check($process->url('/\\evil.example') === '', 'backslash authority URL blocked');
check($process->url("https://example.com/\nattack") === '', 'control character blocked');
check($process->url('https:evil.example') === '', 'hostless HTTPS URL blocked');
check($process->url('https://user:secret@example.com/path') === '', 'credential-bearing URL blocked');
$emptySearch = new EmptySearchAtlas();
check(strpos($process->results($emptySearch), 'No matching knowledge') !== false, 'valid empty search renders empty state');
$emptySearch->error = 'provider offline';
check(strpos($process->results($emptySearch), 'Search is temporarily unavailable') !== false, 'failed search renders error state');

$addInput = new TestInput();
$addInput->values = ['collection' => 'docs', 'ref' => 'manual', 'title' => 'Draft title', 'text' => 'Draft body'];
$addAtlas = new TestAddAtlas();
$process->services = ['session' => $addSession = new TestSession()];
check($process->addEntry($addAtlas, $addInput) === false, 'failed add reports failure');
check($addSession->get(ProcessAtlas::ADD_DRAFT_KEY)['text'] === 'Draft body', 'failed add preserves draft');
$addAtlas->succeed = true;
check($process->addEntry($addAtlas, $addInput) === true, 'successful add reports success');
check($addSession->get(ProcessAtlas::ADD_DRAFT_KEY) === null, 'successful add clears draft');
$addInput->values['title'] = str_repeat('t', 300);
check($process->addEntry($addAtlas, $addInput) === true && mb_strlen($addAtlas->lastMeta['title']) === 255, 'manual title is capped server-side');
$addInput->values['text'] = str_repeat('x', ProcessAtlas::MAX_MANUAL_TEXT + 1);
check($process->addEntry($addAtlas, $addInput) === false, 'oversized manual text is rejected');
$addInput->values = ['collection' => AtlasStore::STAGING_PREFIX . 'manual', 'ref' => 'ref', 'title' => '', 'text' => 'Body'];
check($process->addEntry($addAtlas, $addInput) === false, 'manual add rejects reserved collection');

$reindexInput = new TestInput();
$reindexInput->values = ['collection' => 'site', 'selector' => 'template=missing'];
$reindexSession = new TestSession();
$reindexPages = new TestPages();
$process->services = ['session' => $reindexSession, 'pages' => $reindexPages];
$process->start($addAtlas, $reindexInput);
check($reindexSession->get('AtlasReindexJob') === null, 'empty selector does not create reindex job');
check(strpos($process->notices[count($process->notices) - 1][1], 'current collection was not changed') !== false, 'empty selector reports safe failure');
$reindexPages->findResult = [new Page(7, 'Indexed')];
$reindexInput->values['max_pages'] = 7500;
$process->start($addAtlas, $reindexInput);
check($reindexSession->get('AtlasReindexJob')['page_ids'] === [7], 'matching selector creates reindex job');
check(strpos($reindexPages->lastSelector, 'limit=7500') !== false, 'reviewed reindex page bound is applied');
$activeJob = $reindexSession->get('AtlasReindexJob');
check(!empty($activeJob['token']), 'reindex job receives an identity token');
$staleAtlas = new TestBatchAtlas();
$staleBatch = $process->batchWithToken($staleAtlas, 'stale-token');
check(!empty($staleBatch['error']) && $reindexSession->get('AtlasReindexJob')['token'] === $activeJob['token'], 'stale batch preserves active job');
check($process->cancelJob($addAtlas, 'stale-token') === false && $reindexSession->get('AtlasReindexJob')['token'] === $activeJob['token'], 'stale cancel preserves active job');
$reindexInput->values['selector'] = str_repeat('x', 1001);
$process->start($addAtlas, $reindexInput);
check($reindexSession->get('AtlasReindexJob')['collection'] === 'site', 'oversized selector preserves active reindex job');
$reindexInput->values['selector'] = 'template=basic-page';
$reindexInput->values['collection'] = AtlasStore::STAGING_PREFIX . 'target';
$process->start($addAtlas, $reindexInput);
check($reindexSession->get('AtlasReindexJob')['collection'] === 'site', 'reserved target does not replace active reindex job');
$reindexInput->values['collection'] = 'replacement';
$addAtlas->cleanupError = 'cleanup database offline';
$process->start($addAtlas, $reindexInput);
check($reindexSession->get('AtlasReindexJob')['collection'] === 'site', 'cleanup failure preserves active reindex job');

$documentPage = new Page(21, 'Example page');
$documentPage->name = 'example-page';
$documentPage->path = '/catalog/example-page/';
$documentPage->parent_id = 20;
$documentPage->template = (object)[
	'name' => 'catalog-item',
	'fieldgroup' => [
		new TestField('body', 'Description', 'FieldtypeTextarea'),
		new TestField('quantity', 'Quantity', 'FieldtypeInteger'),
		new TestField('localized_summary', 'Summary', 'FieldtypeTextLanguage'),
		new TestField('featured', 'Featured', 'FieldtypeCheckbox'),
		new TestField('available', 'Available', 'FieldtypeDatetime'),
		new TestField('status', 'Status', 'FieldtypeOptions'),
		new TestField('gallery', 'Gallery', 'FieldtypeImage'),
		new TestField('related_page', 'Related page', 'FieldtypePage'),
		new TestField('card_icon', 'Icon', 'FieldtypeText'),
	],
];
$documentPage->values = [
	'body' => '<p>Hello &amp; welcome</p><p>Second paragraph</p>',
	'quantity' => 3,
	'localized_summary' => 'Localized text',
	'featured' => true,
	'available' => 1780293600,
	'status' => new SelectableOptionArray([new SelectableOption('Active')]),
	'gallery' => new Pageimages([new Pageimage('/site/assets/files/21/front.jpg'), new Pageimage('https://example.test/site/assets/files/21/inside.jpg')]),
	'related_page' => new Page(22, 'Related title'),
	'card_icon' => 'tabler-outline-home',
];
$process->services['config'] = (object)['urls' => (object)['httpRoot' => 'https://example.test/'], 'https' => true, 'httpHost' => 'example.test'];
$document = $process->document($documentPage);
check(strpos($document['text'], "Description: Hello & welcome Second paragraph") !== false, 'default page adapter labels and cleans rich text fields');
check(strpos($document['text'], 'Quantity: 3') !== false && strpos($document['text'], 'Related page: Related title') !== false, 'default page adapter extracts common scalar and page-reference fields');
check(strpos($document['text'], 'Summary: Localized text') !== false && strpos($document['text'], 'Featured: true') !== false, 'default page adapter supports multilingual text and boolean fields');
check(strpos($document['text'], 'Available: ' . date('c', 1780293600)) !== false && strpos($document['text'], 'Status: Active') !== false, 'default page adapter normalizes dates and option labels');
check(strpos($document['text'], 'Status: 1, 0') === false, 'option internals are excluded from semantic text');
check(strpos($document['text'], "Gallery:\n  https://example.test/site/assets/files/21/front.jpg\n  https://example.test/site/assets/files/21/inside.jpg") !== false, 'image fields retain absolute media URLs as a readable block');
check(strpos($document['text'], 'tabler-outline-home') === false, 'default page adapter excludes presentation-only fields');
check($document['meta'] === ['template' => 'catalog-item', 'name' => 'example-page', 'path' => '/catalog/example-page/', 'parent_id' => 20], 'default page adapter exposes universal ProcessWire metadata');

$titleOnlyPage = new Page(25, 'Child');
$titleOnlyPage->parentPages = [new Page(1, 'Home'), new Page(24, 'Section')];
check($process->document($titleOnlyPage)['text'] === "Child\nSection / Child", 'title-only pages receive semantic hierarchy context');

$shortPage = new Page(27, 'District');
$shortPage->parentPages = [new Page(1, 'Home'), new Page(24, 'Country'), new Page(25, 'City')];
$shortPage->template = (object)['fieldgroup' => [new TestField('city', 'City', 'FieldtypePage')]];
$shortPage->values = ['city' => new Page(25, 'City')];
check($process->document($shortPage)['text'] === "District\nCity: City\nCountry / City / District", 'short structured pages receive hierarchy context');

$hookFieldPage = new Page(26, 'Hookable');
$hookFieldPage->template = (object)['fieldgroup' => [new TestField('custom', 'Custom', 'FieldtypeText')]];
$fieldHookProcess = new FieldHookProcessAtlas();
check($fieldHookProcess->document($hookFieldPage)['text'] === "Hookable\nCustom projection", 'buildPageFieldText customizes one field without replacing the full document adapter');

$tableField = new TestField('specifications', 'Specifications', 'FieldtypeTable');
$comboField = new TestField('details', 'Details', 'FieldtypeCombo');
$textareasField = new TestField('content_blocks', 'Content blocks', 'FieldtypeTextareas');
$textareasField->type->textareaFields = [['name' => 'intro', 'label' => 'Introduction'], ['name' => 'notes', 'label' => 'Notes']];
$multiplierField = new TestField('aliases', 'Aliases', 'FieldtypeMultiplier');
$measurementsField = new TestField('measurements', 'Measurements', 'FieldtypeMultiplier');
$repeaterField = new TestField('features', 'Features', 'FieldtypeRepeater');
$matrixField = new TestField('sections', 'Sections', 'FieldtypeRepeaterMatrix');
$matrixField->settings = ['matrix1_label' => 'Hero'];

$repeaterItem = new Page(31, 'Repeater item');
$repeaterItem->template = (object)['fieldgroup' => [new TestField('body', 'Feature', 'FieldtypeText')]];
$repeaterItem->values = ['body' => 'Fast delivery'];
$matrixItem = new TestMatrixPage(32, 'Matrix item');
$matrixItem->matrixName = 'hero'; $matrixItem->matrixN = 1;
$matrixItem->template = (object)['fieldgroup' => [new TestField('headline', 'Headline', 'FieldtypeText'), new TestField('repeater_matrix_type', 'Repeater matrix type', 'FieldtypeInteger')]];
$matrixItem->values = ['headline' => 'Welcome', 'repeater_matrix_type' => 1];

$proFieldsPage = new Page(30, 'Composite fields');
$proFieldsPage->template = (object)['fieldgroup' => [$tableField, $comboField, $textareasField, $multiplierField, $measurementsField, $repeaterField, $matrixField]];
$proFieldsPage->values = [
	'specifications' => new TestRows(
		[new TestRecord(['material' => 'Steel', 'weight' => 12])],
		[['name' => 'material', 'label' => 'Material'], ['name' => 'weight', 'label' => 'Weight']]
	),
	'details' => new TestComboValue(
		['condition' => 'new', 'manufacturer' => new Page(33, 'Acme'), 'photo' => new Pageimages(), 'opaque' => new TestInternalIterable([false, 987654321, 41])],
		['condition' => new TestComboSubfield('Condition', "new=New\nused=Used"), 'manufacturer' => new TestComboSubfield('Manufacturer'), 'photo' => new TestComboSubfield('Photo'), 'opaque' => new TestComboSubfield('Opaque')]
	),
	'content_blocks' => new TestRecord(['intro' => '<p>First</p><p>Second</p>', 'notes' => '']),
	'aliases' => ['Primary', 'Secondary'],
	'measurements' => ['width' => 41, 'height' => 5521],
	'features' => [$repeaterItem],
	'sections' => [$matrixItem],
];
$proFieldsText = $process->document($proFieldsPage)['text'];
check(strpos($proFieldsText, "Specifications:\n  Material: Steel\n  Weight: 12") !== false, 'ProFields Table rows retain column labels and values');
check(strpos($proFieldsText, "Details:\n  Condition: New\n  Manufacturer: Acme") !== false, 'ProFields Combo uses subfield labels and resolves configured options');
check(strpos($proFieldsText, 'Photo: https://example.test/site/assets/files/30/photo.jpg') !== false, 'composite media fields retain absolute URLs');
check(strpos($proFieldsText, '12345') === false && strpos($proFieldsText, 'internal') === false, 'media object internals are excluded');
check(strpos($proFieldsText, '987654321') === false, 'unknown iterable object internals are excluded');
check(strpos($proFieldsText, "Content blocks:\n  Introduction: First Second") !== false && strpos($proFieldsText, 'Aliases: Primary, Secondary') !== false, 'ProFields Textareas and Multiplier values are extracted');
check(strpos($proFieldsText, "Measurements:\n  width: 41\n  height: 5521") !== false, 'associative values retain their keys');
check(strpos($proFieldsText, "Features:\n  Feature: Fast delivery") !== false && strpos($proFieldsText, "Sections:\n  Hero\n  Headline: Welcome") !== false, 'Repeater and Repeater Matrix nested semantic fields are extracted');
check(strpos($proFieldsText, 'Repeater matrix type') === false, 'Repeater Matrix system fields are excluded');

$session = new TestSession();
$pages = new TestPages();
$pages->pages = [1 => new Page(1, 'One'), 2 => new Page(2, 'Two')];
$log = new TestLog();
$process->services = ['session' => $session, 'pages' => $pages, 'sanitizer' => new TestSanitizer(), 'log' => $log];
$session->set('AtlasReindexJob', ['status' => 'running', 'token' => 'success-token', 'collection' => 'site', 'staging' => 'stage', 'page_ids' => [1, 2], 'current' => 0, 'pages' => 0, 'chunks' => 0]);
$batchAtlas = new TestBatchAtlas();
$result = $process->batch($batchAtlas);
check($result['done'] === true && $result['percent'] === 100, 'reindex batch completes');
check($batchAtlas->promoted === 1, 'reindex promotes exactly once');
check($session->get('AtlasReindexJob') === null, 'completed reindex clears job');
check(($pages->pages[1]->metadata[ProcessAtlas::PAGE_META_KEY]['site']['content_hash'] ?? '') === hash('sha256', 'One'), 'successful promotion stores the page content hash');
check(($pages->pages[1]->metadata[ProcessAtlas::PAGE_META_KEY]['site']['chunks'] ?? 0) === 1, 'successful promotion stores the page chunk count');

$session->set('AtlasReindexJob', ['status' => 'running', 'token' => 'failure-token', 'collection' => 'site', 'staging' => 'failed-stage', 'page_ids' => [1], 'current' => 0, 'pages' => 0, 'chunks' => 0]);
$failedAtlas = new TestBatchAtlas(); $failedAtlas->fail = true;
$pages->pages[1]->metadata = [];
$failed = $process->batch($failedAtlas);
check(!empty($failed['error']), 'failed reindex reports error');
check($failed['detail'] === 'provider offline', 'failed reindex preserves provider detail');
check($failedAtlas->promoted === 0, 'failed reindex does not promote');
check($failedAtlas->cleared === ['failed-stage'], 'failed reindex clears staging');
check($session->get('AtlasReindexJob') === null, 'failed reindex clears job');
check($pages->pages[1]->metadata === [], 'failed reindex does not claim the page was indexed');

$incrementalPage = new Page(9, 'Stable');
$incrementalPage->meta(ProcessAtlas::PAGE_META_KEY, ['site' => ['content_hash' => hash('sha256', 'Stable'), 'document_hash' => hash('sha256', "Stable\0[]"), 'identity' => 'test:test-model', 'model' => 'test-model', 'chunks' => 1, 'indexed_at' => 1]]);
$incrementalPages = new TestPages();
$incrementalPages->findResult = [$incrementalPage];
$incrementalPages->pages = [9 => $incrementalPage];
$incrementalSession = new TestSession();
$process->services = ['session' => $incrementalSession, 'pages' => $incrementalPages, 'sanitizer' => new TestSanitizer(), 'log' => new TestLog()];
$incrementalAtlas = new IncrementalAtlas(); $incrementalAtlas->succeed = true; $incrementalAtlas->counts = ['page-9' => 1];
$reindexInput->values = ['collection' => 'site', 'selector' => 'template=basic-page'];
$process->start($incrementalAtlas, $reindexInput);
$incrementalJob = $incrementalSession->get('AtlasReindexJob');
check($incrementalJob['page_ids'] === [] && $incrementalJob['unchanged'] === 1, 'matching page metadata skips re-embedding');
check($incrementalAtlas->copiedRefs === ['page-9'] && $incrementalJob['chunks'] === 1, 'unchanged vectors are copied into staging');

$hookProcess = new HookLifecycleProcessAtlas();
$hookSession = new TestSession();
$hookPages = new TestPages();
$hookPage = new Page(12, 'Extensible');
$hookPages->findResult = [$hookPage]; $hookPages->pages = [12 => $hookPage];
$hookProcess->services = ['session' => $hookSession, 'pages' => $hookPages, 'sanitizer' => new TestSanitizer(), 'log' => new TestLog()];
$hookAtlas = new IncrementalAtlas(); $hookAtlas->succeed = true;
$hookProcess->start($hookAtlas, $reindexInput);
$hookBatchAtlas = new TestBatchAtlas();
$hookResult = $hookProcess->batch($hookBatchAtlas);
check($hookResult['done'] === true && $hookProcess->readyCalls === 1, 'reindexReady runs once before promotion');
check(($hookBatchAtlas->addedItems[0]['text'] ?? '') === 'Hooked Extensible' && ($hookBatchAtlas->addedItems[0]['meta']['source_app'] ?? '') === 'test-app', 'buildPageDocument controls indexed text and metadata');
check($hookProcess->pageIndexedCalls === 1, 'pageIndexed runs after page metadata is saved');
check(($hookProcess->collectionSummary['chunks'] ?? 0) === 3 && ($hookProcess->collectionSummary['model'] ?? '') === 'test-model', 'collectionReindexed receives the final integration summary');

$failingHookProcess = new FailingHookProcessAtlas();
$failingHookSession = new TestSession();
$failingHookPage = new Page(13, 'External failure');
$failingHookPages = new TestPages(); $failingHookPages->pages = [13 => $failingHookPage];
$failingHookProcess->services = ['session' => $failingHookSession, 'pages' => $failingHookPages, 'sanitizer' => new TestSanitizer(), 'log' => new TestLog()];
$failingHookSession->set('AtlasReindexJob', ['status' => 'running', 'token' => 'hook-failure', 'collection' => 'site', 'staging' => 'hook-stage', 'page_ids' => [13], 'current' => 0, 'pages' => 0, 'chunks' => 0]);
$failingHookAtlas = new TestBatchAtlas();
$failingHookResult = $failingHookProcess->batch($failingHookAtlas);
check(($failingHookResult['detail'] ?? '') === 'external application offline', 'integration failure is reported by reindex');
check($failingHookAtlas->promoted === 0 && $failingHookAtlas->cleared === ['hook-stage'], 'integration failure preserves live collection and clears staging');
check($failingHookPage->metadata === [], 'integration failure does not write page index metadata');

$process->services = ['session' => $session, 'pages' => $pages, 'sanitizer' => new TestSanitizer(), 'log' => $log];
$session->set('AtlasReindexJob', ['status' => 'running', 'token' => 'promotion-token', 'collection' => 'site', 'staging' => 'promotion-stage', 'page_ids' => [], 'current' => 0, 'pages' => 0, 'chunks' => 0]);
$promotionAtlas = new TestBatchAtlas(); $promotionAtlas->promoteFail = true;
$promotion = $process->batch($promotionAtlas);
check($promotion['detail'] === 'promotion offline', 'promotion failure preserves storage detail');
check($promotionAtlas->cleared === ['promotion-stage'], 'promotion failure clears staging');

$adminScript = file_get_contents(__DIR__ . '/../assets/ProcessAtlas.js');
check(strpos($adminScript, "batchForm.getAttribute('action')") !== false, 'reindex batch URL is not shadowed by the hidden action field');
check(strpos($adminScript, 'fetch(batchForm.action') === false, 'reindex does not use the shadowable form action property');
$adminModule = file_get_contents(__DIR__ . '/../ProcessAtlas.module.php');
check(strpos($adminModule, 'filemtime($assetsPath') !== false, 'admin assets are cache-busted after updates');
check(ProcessAtlas::DEFAULT_REINDEX_PAGES === 1000 && ProcessAtlas::MAX_REINDEX_PAGES === 10000, 'reindex page bounds remain explicit and safe');
check(strpos($adminModule, 'class="uk-tab AtlasNav"') !== false, 'admin primary navigation uses the native UIkit tab pattern');
check(strpos($adminModule, 'ProcessAtlas AtlasWorkspace pw-module-workspace') !== false, 'admin output uses the canonical scoped module workspace');
$adminTraits = class_uses(ProcessAtlas::class);
foreach (['ProcessAtlasAdminSupport', 'ProcessAtlasOverview', 'ProcessAtlasCollections', 'ProcessAtlasSearch', 'ProcessAtlasReindex'] as $trait) {
	check(isset($adminTraits[__NAMESPACE__ . '\\' . $trait]), 'admin entry point composes ' . $trait);
}
$adminCss = file_get_contents(__DIR__ . '/../assets/ProcessAtlas.css');
check(strpos($adminCss, '.ProcessAtlas ') !== false, 'admin custom CSS is scoped to ProcessAtlas');
check(strpos($adminCss, '--pw-primary-color') === false && strpos($adminCss, '--pw-content-background') === false, 'admin CSS uses current ProcessWire design tokens only');
check(strpos($adminCss, 'gradient') === false && strpos($adminCss, 'box-shadow') === false, 'admin CSS avoids decorative gradients and shadows');
check(strpos($adminCss, '.AtlasEntry-quality') !== false && strpos($adminCss, 'white-space: pre-wrap') !== false, 'collection entries expose source quality and preserve field structure');
$storeSource = file_get_contents(__DIR__ . '/../AtlasStore.php');
check(strpos($storeSource, 'MYSQL_ATTR_USE_BUFFERED_QUERY') !== false && strpos($storeSource, 'closeCursor()') !== false, 'large collection search disables PDO buffering and closes the cursor');

echo "Atlas smoke tests passed\n";
