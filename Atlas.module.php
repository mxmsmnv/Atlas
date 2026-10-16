<?php namespace ProcessWire;

require_once(__DIR__ . '/AtlasStore.php');
require_once(__DIR__ . '/src/AtlasPocketWireProviderTrait.php');

/**
 * Atlas — a lightweight RAG store for ProcessWire.
 *
 * Stores text together with its embedding (produced by the Squad gateway) and
 * retrieves the most similar entries by cosine similarity. Vectors live in one
 * MySQL table and similarity is computed in PHP — built for small/medium
 * collections, not millions of rows. Depends on Squad for embeddings.
 *
 * Usage:
 *   $r = $modules->get('Atlas');
 *   $r->add('faq', 'q1', 'How do I reset my password?');
 *   $hits = $r->search('faq', 'forgot password', 3); // [['ref','text','score',...]]
 */
class Atlas extends WireData implements Module {
	use AtlasPocketWireProviderTrait;

	public static function getModuleInfo() {
		return [
			'title'    => 'Atlas',
			'version'  => 123,
			'summary'  => __('Lightweight RAG store: embed text via Squad and retrieve by similarity.'),
			'author'   => 'Maxim Semenov',
			'icon'     => 'search',
			'singular' => true,
			'autoload' => false,
			'pocketWireProvider' => true,
			'requires' => ['ProcessWire>=3.0.200', 'Squad'],
		];
	}

	/** @var AtlasStore|null */
	protected $store = null;
	/** @var string Diagnostic message from the most recent add/search operation. */
	protected $lastError = '';
	/** @var string Diagnostic message from the most recent readiness check. */
	protected $readinessError = '';

	protected function store(): AtlasStore {
		if ($this->store === null) $this->store = $this->wire(new AtlasStore());
		return $this->store;
	}

	public function ___install()   { $this->store()->ensureTable(); }
	public function ___uninstall() { $this->store()->dropTable(); }

	/** True when Squad is installed and has an embedding-capable provider with a key. */
	public function isReady(): bool {
		$this->readinessError = '';
		try {
			$modules = $this->wire('modules');
			if (!$modules || !$modules->isInstalled('Squad')) return false;
			$squad = $modules->get('Squad');
			return is_object($squad) && method_exists($squad, 'embed') && method_exists($squad, 'getDefaultEmbedProvider') && (bool)$squad->getDefaultEmbedProvider();
		} catch (\Throwable $e) {
			$this->readinessError = 'Atlas readiness: ' . $e->getMessage();
			try { $this->wire('log')->error('atlas readiness: ' . $e->getMessage()); } catch (\Throwable $ignored) {}
			return false;
		}
	}

	public function readinessError(): string { return $this->readinessError; }

	/** Configured embedding model, used to invalidate incremental page indexes. */
	public function embeddingIdentity(): string {
		try {
			$squad = $this->squad();
			$provider = is_object($squad) && method_exists($squad, 'getDefaultEmbedProvider') ? (string)$squad->getDefaultEmbedProvider() : '';
			$definitions = is_object($squad) && method_exists($squad, 'getProviderDefinitions') ? $squad->getProviderDefinitions() : [];
			$model = trim((string)($definitions[$provider]['defaultEmbedModel'] ?? ''));
			return $provider === '' ? '' : $provider . ':' . $model;
		} catch (\Throwable $e) {
			return '';
		}
	}

	protected function squad() { return $this->wire('modules')->get('Squad'); }

	/** Diagnostic message for callers that need to distinguish failure from no results. */
	public function lastError(): string { return $this->lastError; }

	protected function fail(string $message): void {
		$this->lastError = $message;
		$this->error($message);
	}

	/** Preserve read diagnostics without creating ProcessWire notices from library calls. */
	protected function captureStoreError(string $operation): void {
		$error = $this->store()->lastError();
		if ($error !== '') $this->lastError = 'Atlas ' . $operation . ': ' . $error;
	}

	protected function validCollection(string $collection): bool { return $collection !== '' && mb_strlen($collection) <= 128; }
	protected function validRef(string $ref, int $maxLength = 190): bool { return $ref !== '' && mb_strlen($ref) <= $maxLength; }

	/** Call Squad without allowing provider/network exceptions to break the request. */
	protected function embed($input, array $opts, string $operation): ?array {
		try {
			$result = $this->squad()->embed($input, $opts);
		} catch (\Throwable $e) {
			$this->fail('Atlas ' . $operation . ': ' . $e->getMessage());
			return null;
		}
		if (!is_array($result)) {
			$this->fail('Atlas ' . $operation . ': invalid response from embedding provider');
			return null;
		}
		return $result;
	}

	/** Embed $text and upsert it under (collection, ref). Returns success. */
	public function add(string $collection, string $ref, string $text, array $meta = [], array $opts = []): bool {
		$this->lastError = '';
		if (!$this->validCollection($collection)) { $this->fail('Atlas add: collection is required and limited to 128 characters'); return false; }
		if (!$this->validRef($ref)) { $this->fail('Atlas add: ref is required and limited to 190 characters'); return false; }
		$text = trim($text);
		if ($text === '') { $this->fail('Atlas add: text is required'); return false; }
		$res = $this->embed($text, $opts, 'add');
		if ($res === null) return false;
		if (empty($res['success'])) { $this->fail('Atlas add: ' . ($res['message'] ?? 'embed failed')); return false; }
		$vec = $res['embedding'] ?? ($res['embeddings'][0] ?? null);
		if (!is_array($vec) || !$this->validVector($vec)) { $this->fail('Atlas add: invalid embedding'); return false; }
		$ok = $this->store()->put($collection, $ref, $text, $vec, $meta, (string)($res['model'] ?? ''));
		if (!$ok) $this->fail('Atlas add: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $ok;
	}

	/**
	 * Batch upsert with a single embed() call for all texts.
	 * @param array $items [['ref'=>, 'text'=>, 'meta'=>[]], ...]
	 * @return int number of entries stored
	 */
	public function addMany(string $collection, array $items, array $opts = []): int {
		$this->lastError = '';
		if (!$this->validCollection($collection)) { $this->fail('Atlas addMany: collection is required and limited to 128 characters'); return 0; }
		$items = array_values(array_filter($items, fn($i) => is_array($i) && trim((string)($i['text'] ?? '')) !== '' && (string)($i['ref'] ?? '') !== ''));
		if (!$items) return 0;
		foreach ($items as $idx => $item) {
			if (!$this->validRef((string)$item['ref'])) { $this->fail('Atlas addMany: ref at index ' . $idx . ' is limited to 190 characters'); return 0; }
		}
		$texts = array_map(fn($i) => (string)$i['text'], $items);
		$res = $this->embed($texts, $opts, 'addMany');
		if ($res === null) return 0;
		if (empty($res['success'])) { $this->fail('Atlas addMany: ' . ($res['message'] ?? 'embed failed')); return 0; }
		$vecs = $res['embeddings'] ?? [];
		if (!is_array($vecs) || count($vecs) !== count($items)) { $this->fail('Atlas addMany: embedding/text count mismatch'); return 0; }
		$model = (string)($res['model'] ?? '');
		$dims = null;
		foreach ($vecs as $idx => $vector) {
			if (!is_array($vector) || !$this->validVector($vector)) { $this->fail('Atlas addMany: invalid embedding at index ' . $idx); return 0; }
			if ($dims === null) $dims = count($vector);
			elseif (count($vector) !== $dims) { $this->fail('Atlas addMany: embeddings have inconsistent dimensions'); return 0; }
		}
		$rows = [];
		foreach ($items as $idx => $it) {
			$rows[] = ['ref' => (string)$it['ref'], 'text' => (string)$it['text'], 'meta' => (array)($it['meta'] ?? []), 'vector' => $vecs[$idx], 'model' => $model];
		}
		$n = $this->store()->putMany($collection, $rows);
		if ($n !== count($rows)) $this->fail('Atlas addMany: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $n;
	}

	/**
	 * Retrieve the top-$topK entries most similar to $query.
	 * @param array $opts passed to Squad::embed(); also `filter` => [metaKey => value|[values]]
	 *              restricts results to matching metadata.
	 * @return array [['ref','text','meta','model','score'], ...]
	 */
	public function search(string $collection, string $query, int $topK = 5, array $opts = []): array {
		$this->lastError = '';
		if (!$this->validCollection($collection)) { $this->fail('Atlas search: collection is required and limited to 128 characters'); return []; }
		$query = trim($query);
		if ($query === '') return [];
		$rerank = [
			'filter'         => (array)($opts['filter'] ?? []),
			'keyword'        => (string)($opts['keyword'] ?? ''),
			'keywordRequire' => !empty($opts['keywordRequire']),
			'mmr'            => !empty($opts['mmr']),
			'mmrLambda'      => $opts['mmrLambda'] ?? 0.7,
		];
		unset($opts['filter'], $opts['keyword'], $opts['keywordRequire'], $opts['mmr'], $opts['mmrLambda']); // not embed options
		$res = $this->embed($query, $opts, 'search');
		if ($res === null) return [];
		if (empty($res['success'])) { $this->fail('Atlas search: ' . ($res['message'] ?? 'embed failed')); return []; }
		$vec = $res['embedding'] ?? ($res['embeddings'][0] ?? null);
		if (!is_array($vec) || !$this->validVector($vec)) { $this->fail('Atlas search: invalid embedding'); return []; }
		$rerank['model'] = (string)($res['model'] ?? '');
		$hits = $this->store()->nearest($collection, $vec, $topK, $rerank);
		if (!$hits && $this->store()->lastError() !== '') $this->fail('Atlas search: ' . $this->store()->lastError());
		return $hits;
	}

	/**
	 * Retrieve locally by significant query terms without an embedding request.
	 *
	 * Use as a fast first pass and fall back to search() when no result clears
	 * the requested minimum score.
	 */
	public function lexicalSearch(string $collection, string $query, int $topK = 5, array $opts = []): array {
		$this->lastError = '';
		if (!$this->validCollection($collection)) {
			$this->fail('Atlas lexical search: collection is required and limited to 128 characters');
			return [];
		}
		$query = trim($query);
		if ($query === '') return [];
		$hits = $this->store()->lexical($collection, $query, $topK, [
			'filter' => (array)($opts['filter'] ?? []),
			'minScore' => $opts['minScore'] ?? 0.42,
		]);
		if (!$hits && $this->store()->lastError() !== '') {
			$this->fail('Atlas lexical search: ' . $this->store()->lastError());
		}
		return $hits;
	}

	/**
	 * Add a long document by splitting it into chunks (better recall + within
	 * embedding input limits). Chunks are stored as `{ref}#0`, `{ref}#1`, … and
	 * tagged with meta `parent`=$ref, `chunk`=index. One embed() call for all
	 * chunks. Returns the number of chunks stored.
	 */
	public function addChunked(string $collection, string $ref, string $text, array $meta = [], array $opts = [], int $maxChars = 1500): int {
		$this->lastError = '';
		if (!$this->validCollection($collection)) { $this->fail('Atlas addChunked: collection is required and limited to 128 characters'); return 0; }
		if (!$this->validRef($ref, 180)) { $this->fail('Atlas addChunked: ref is required and limited to 180 characters'); return 0; }
		$chunks = $this->chunk(trim($text), max(200, $maxChars));
		if (!$chunks) return 0;
		$res = $this->embed($chunks, $opts, 'addChunked');
		if ($res === null) return 0;
		if (empty($res['success'])) { $this->fail('Atlas addChunked: ' . ($res['message'] ?? 'embed failed')); return 0; }
		$vecs = $res['embeddings'] ?? [];
		if (!is_array($vecs) || count($vecs) !== count($chunks)) { $this->fail('Atlas addChunked: embedding/text count mismatch'); return 0; }
		$items = []; $model = (string)($res['model'] ?? '');
		foreach ($chunks as $i => $c) {
			if (!is_array($vecs[$i]) || !$this->validVector($vecs[$i])) { $this->fail('Atlas addChunked: invalid embedding at index ' . $i); return 0; }
			$items[] = ['ref' => $ref . '#' . $i, 'text' => $c, 'meta' => array_merge($meta, ['parent' => $ref, 'chunk' => $i]), 'vector' => $vecs[$i], 'model' => $model];
		}
		if ($this->store()->replaceRef($collection, $ref, $items)) return count($items);
		$this->fail('Atlas addChunked: ' . ($this->store()->lastError() ?: 'storage failed'));
		return 0;
	}

	/** Atomically replace a collection with a fully prepared staging collection. */
	public function replaceCollection(string $target, string $staging): bool {
		$this->lastError = '';
		$ok = $this->store()->replaceCollection($target, $staging);
		if (!$ok) $this->fail('Atlas replaceCollection: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $ok;
	}

	protected function validVector(array $vector): bool {
		if (!$vector) return false;
		foreach ($vector as $value) if (!is_numeric($value) || !is_finite((float)$value)) return false;
		return true;
	}

	/** Split text into ≤$maxChars chunks on paragraph/sentence boundaries. */
	protected function chunk(string $text, int $maxChars): array {
		if ($text === '') return [];
		if (mb_strlen($text) <= $maxChars) return [$text];

		// atomic units, each ≤ maxChars: paragraphs → sentences → hard slices
		$units = [];
		foreach (preg_split('/\n{2,}/', $text) as $para) {
			$para = trim($para);
			if ($para === '') continue;
			if (mb_strlen($para) <= $maxChars) { $units[] = $para; continue; }
			foreach (preg_split('/(?<=[.!?。！？])\s+/u', $para) as $sent) {
				$sent = trim($sent);
				if ($sent === '') continue;
				if (mb_strlen($sent) <= $maxChars) { $units[] = $sent; continue; }
				for ($i = 0, $len = mb_strlen($sent); $i < $len; $i += $maxChars) $units[] = mb_substr($sent, $i, $maxChars);
			}
		}

		// greedily pack units into ≤ maxChars chunks
		$chunks = []; $buf = '';
		foreach ($units as $u) {
			if ($buf !== '' && mb_strlen($buf) + mb_strlen($u) + 1 > $maxChars) { $chunks[] = $buf; $buf = ''; }
			$buf = $buf === '' ? $u : $buf . "\n" . $u;
		}
		if ($buf !== '') $chunks[] = $buf;
		return $chunks;
	}

	public function delete(string $collection, string $ref): bool {
		$this->lastError = '';
		$ok = $this->store()->delete($collection, $ref);
		if (!$ok) $this->fail('Atlas delete: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $ok;
	}
	/** Delete a base ref and all its chunk children (`{ref}#0`, `{ref}#1`, …). */
	public function deleteRef(string $collection, string $ref): bool {
		$this->lastError = '';
		$ok = $this->store()->deleteRef($collection, $ref);
		if (!$ok) $this->fail('Atlas deleteRef: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $ok;
	}
	/** Public access to the chunker (split text into ≤maxChars chunks). */
	public function chunkText(string $text, int $maxChars = 1500): array { return $this->chunk(trim($text), max(200, $maxChars)); }
	public function clearCollection(string $collection): bool {
		$this->lastError = '';
		$ok = $this->store()->clearCollection($collection);
		if (!$ok) $this->fail('Atlas clearCollection: ' . ($this->store()->lastError() ?: 'storage failed'));
		return $ok;
	}
	public function count(string $collection): int {
		$this->lastError = '';
		$count = $this->store()->count($collection);
		$this->captureStoreError('count');
		return $count;
	}
	/** Stored chunk counts grouped by base ref. */
	public function baseRefCounts(string $collection): array {
		$this->lastError = '';
		$counts = $this->store()->baseRefCounts($collection);
		$this->captureStoreError('baseRefCounts');
		return $counts;
	}
	/** Reuse complete documents in a fresh internal staging collection. */
	public function copyRefs(string $source, string $staging, array $baseRefs): int {
		$this->lastError = '';
		$count = $this->store()->copyRefs($source, $staging, $baseRefs);
		if ($count === 0 && $this->store()->lastError() !== '') $this->fail('Atlas copyRefs: ' . $this->store()->lastError());
		return $count;
	}
	/** [['collection'=>name,'count'=>n], ...] across all collections. */
	public function collections(): array {
		$this->lastError = '';
		$collections = $this->store()->collections();
		$this->captureStoreError('collections');
		return $collections;
	}
	/** Remove abandoned internal reindex staging rows; returns rows deleted. */
	public function cleanupStaging(int $maxAge = 86400): int {
		$this->lastError = '';
		$count = $this->store()->cleanupStaging($maxAge);
		$this->captureStoreError('cleanupStaging');
		return $count;
	}
	/** Browse entries of a collection: [['ref','text','meta','model','dims','modified'], ...]. */
	public function entries(string $collection, int $limit = 50, int $offset = 0): array {
		$this->lastError = '';
		$entries = $this->store()->entries($collection, $limit, $offset);
		$this->captureStoreError('entries');
		return $entries;
	}
	/** Aggregate stats: ['total','docs','dims','avg_chars','last_modified','models']. */
	public function stats(string $collection): array {
		$this->lastError = '';
		$stats = $this->store()->collectionStats($collection);
		$this->captureStoreError('stats');
		return $stats;
	}
}
