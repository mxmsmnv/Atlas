<?php namespace ProcessWire;

/**
 * AtlasStore — vector storage + cosine retrieval behind the Atlas RAG module.
 *
 * Vectors live in a single MySQL table as JSON; similarity is computed in PHP.
 * That keeps the module dependency-free (no pgvector/Qdrant) and is fine up to
 * ~10^4 rows per collection. For larger corpora, swap this store for an external
 * vector DB behind the same put()/nearest() interface.
 */
class AtlasStore extends Wire {

	const TABLE = 'atlas_vectors';
	const STAGING_PREFIX = '__atlas_stage_';

	/** @var string Diagnostic message from the most recent fallible operation. */
	protected $lastError = '';
	/** @var bool Avoid repeated CREATE TABLE checks during batch writes in one request. */
	protected $tableEnsured = false;

	public function lastError(): string { return $this->lastError; }

	public function ensureTable(): void {
		if ($this->tableEnsured) return;
		$this->wire('database')->exec(
			"CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
				`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`collection` VARCHAR(128) NOT NULL,
				`ref`        VARCHAR(190) NOT NULL,
				`text`       MEDIUMTEXT   NOT NULL,
				`meta`       TEXT         NULL,
				`model`      VARCHAR(128) NOT NULL DEFAULT '',
				`dims`       INT          NOT NULL DEFAULT 0,
				`vector`     MEDIUMTEXT   NOT NULL,
				`created`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`modified`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `coll_ref` (`collection`, `ref`),
				KEY `collection` (`collection`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		$this->tableEnsured = true;
	}

	public function dropTable(): void {
		$this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::TABLE . "`");
		$this->tableEnsured = false;
	}

	/** Upsert one entry with a precomputed vector (unique per collection+ref). */
	public function put(string $collection, string $ref, string $text, array $vector, array $meta = [], string $model = ''): bool {
		$this->lastError = '';
		if (!$this->validIdentity($collection, $ref)) { $this->lastError = 'Invalid collection or ref'; return false; }
		if (!$this->validVector($vector)) { $this->lastError = 'Invalid embedding vector'; return false; }
		$db = $this->wire('database');
		try {
			$this->ensureTable();
			$db->beginTransaction();
			if (!$this->collectionAccepts($collection, count($vector), $model, $ref, '', true)) throw new \RuntimeException($this->lastError ?: 'Collection compatibility check failed');
			$stmt = $db->prepare(
				"INSERT INTO `" . self::TABLE . "` (`collection`,`ref`,`text`,`meta`,`model`,`dims`,`vector`)
				 VALUES (:c,:r,:t,:m,:mo,:d,:v)
				 ON DUPLICATE KEY UPDATE `text`=VALUES(`text`), `meta`=VALUES(`meta`),
				   `model`=VALUES(`model`), `dims`=VALUES(`dims`), `vector`=VALUES(`vector`)"
			);
			if (!$stmt->execute([
				':c'  => $collection,
				':r'  => $ref,
				':t'  => $text,
				':m'  => $this->encodeJson($meta),
				':mo' => $model,
				':d'  => count($vector),
				':v'  => $this->encodeJson(array_map('floatval', array_values($vector))),
			])) throw new \RuntimeException('Vector upsert failed');
			$db->commit();
			return true;
		} catch (\Throwable $e) {
			if ($db && $db->inTransaction()) $db->rollBack();
			$this->logError('put', $e);
			return false;
		}
	}

	/** Atomically upsert a validated batch with one compatibility check/statement. */
	public function putMany(string $collection, array $items): int {
		$this->lastError = '';
		$items = array_values($items);
		if ($collection === '' || mb_strlen($collection) > 128 || !$items) { $this->lastError = 'Invalid collection or empty batch'; return 0; }
		if (!is_array($items[0]) || !is_array($items[0]['vector'] ?? null) || !$this->validVector($items[0]['vector'])) { $this->lastError = 'Invalid batch vector'; return 0; }
		$dims = count($items[0]['vector']);
		$model = (string)($items[0]['model'] ?? '');
		$seen = [];
		foreach ($items as $item) {
			if (!is_array($item)) { $this->lastError = 'Invalid batch row'; return 0; }
			$ref = (string)($item['ref'] ?? '');
			$vector = $item['vector'] ?? null;
			if (!$this->validIdentity($collection, $ref)) { $this->lastError = 'Invalid batch ref'; return 0; }
			if (isset($seen[$ref])) { $this->lastError = 'Duplicate batch ref'; return 0; }
			$seen[$ref] = true;
			if (!is_array($vector) || !$this->validVector($vector) || count($vector) !== $dims) { $this->lastError = 'Batch vectors have inconsistent dimensions'; return 0; }
			if ((string)($item['model'] ?? '') !== $model) { $this->lastError = 'Batch rows use different embedding models'; return 0; }
		}

		$db = $this->wire('database');
		try {
			$this->ensureTable();
			$db->beginTransaction();
			if (!$this->collectionAccepts($collection, $dims, $model, '', '', true)) throw new \RuntimeException($this->lastError ?: 'Collection compatibility check failed');
			$stmt = $db->prepare(
				"INSERT INTO `" . self::TABLE . "` (`collection`,`ref`,`text`,`meta`,`model`,`dims`,`vector`)
				 VALUES (:c,:r,:t,:m,:mo,:d,:v)
				 ON DUPLICATE KEY UPDATE `text`=VALUES(`text`), `meta`=VALUES(`meta`),
				   `model`=VALUES(`model`), `dims`=VALUES(`dims`), `vector`=VALUES(`vector`)"
			);
			foreach ($items as $item) {
				if (!$stmt->execute([
					':c' => $collection,
					':r' => (string)$item['ref'],
					':t' => (string)($item['text'] ?? ''),
					':m' => $this->encodeJson((array)($item['meta'] ?? [])),
					':mo' => $model,
					':d' => $dims,
					':v' => $this->encodeJson(array_map('floatval', array_values($item['vector']))),
				])) throw new \RuntimeException('Batch vector upsert failed');
			}
			$db->commit();
			return count($items);
		} catch (\Throwable $e) {
			if ($db && $db->inTransaction()) $db->rollBack();
			$this->logError('putMany', $e);
			return 0;
		}
	}

	/**
	 * Top-$topK entries in a collection by cosine similarity to $query.
	 *
	 * @param array $opts
	 *   - filter         array  metadata equality filter (all keys must match)
	 *   - keyword        string literal match gets a +0.15 score boost (hybrid)
	 *   - keywordRequire bool   keep only entries containing the keyword
	 *   - mmr            bool   diversify results (Maximal Marginal Relevance) so
	 *                           near-duplicate chunks don't fill the top
	 *   - mmrLambda      float  0..1, relevance↔diversity tradeoff (default 0.7)
	 * @return array [['ref','text','meta','model','score'], ...] (relevance/MMR rank order)
	 */
	public function nearest(string $collection, array $query, int $topK = 5, array $opts = []): array {
		$this->lastError = '';
		if (!$this->validVector($query)) { $this->lastError = 'Invalid query vector'; return []; }
		$qNorm = $this->norm($query);
		if (!is_finite($qNorm) || $qNorm == 0.0) { $this->lastError = 'Query vector has invalid magnitude'; return []; }

		$filter         = (array)($opts['filter'] ?? []);
		$keyword        = trim((string)($opts['keyword'] ?? ''));
		$keywordRequire = !empty($opts['keywordRequire']);
		$mmr            = !empty($opts['mmr']);
		$model          = (string)($opts['model'] ?? '');
		$lambda         = isset($opts['mmrLambda']) ? max(0.0, min(1.0, (float)$opts['mmrLambda'])) : 0.7;
		$topK           = max(1, $topK);
		$poolLimit      = $mmr ? max($topK * 6, 30) : $topK;
		$pruneAt        = max($poolLimit * 4, 32);
		$db             = $this->wire('database');
		$bufferedOption = class_exists('\Pdo\Mysql') && defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
			? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
			: (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
				? constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
				: null);
		$unbuffered = false;
		$stmt = null;

		try {
			if($bufferedOption !== null && method_exists($db, 'setAttribute')) {
				try {
					$unbuffered = $db->setAttribute($bufferedOption, false);
				} catch (\Throwable $ignored) {}
			}
			$stmt = $db->prepare(
				"SELECT `ref`,`text`,`meta`,`model`,`vector` FROM `" . self::TABLE . "` WHERE `collection` = ?"
			);
			$stmt->execute([$collection]);
			$cand = [];
			$sawRows = false;
			$sawCompatibleVector = false;
			$sawCompatibleModel = false;
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$sawRows = true;
				$vec = json_decode((string)$row['vector'], true);
				if (!is_array($vec) || count($vec) !== count($query) || !$this->validVector($vec)) continue; // corrupt/dimension mismatch → skip
				$sawCompatibleVector = true;
				if ($model !== '' && (string)$row['model'] !== '' && !hash_equals((string)$row['model'], $model)) continue;
				$sawCompatibleModel = true;
				$meta = json_decode((string)$row['meta'], true) ?: [];
				if ($filter && !$this->matchesFilter($meta, $filter)) continue;

				$score = $this->cosine($query, $vec, $qNorm);
				if (!is_finite($score)) continue;
				$kwHit = $keyword !== '' && mb_stripos((string)$row['text'], $keyword) !== false;
				if ($keyword !== '' && $keywordRequire && !$kwHit) continue;
				if ($kwHit) $score += 0.15; // hybrid boost for a literal keyword match

				$cand[] = [
					'ref'   => (string)$row['ref'],
					'text'  => (string)$row['text'],
					'meta'  => $meta,
					'model' => (string)$row['model'],
					'score' => $score,
					'_vec'  => $vec,
					'_norm' => $this->norm($vec),
				];
				// Discard candidates that can no longer reach the exact Top K/MMR
				// shortlist, keeping vector memory bounded as the collection grows.
				if (count($cand) >= $pruneAt) $cand = $this->topCandidates($cand, $poolLimit);
			}
			if ($sawRows && !$sawCompatibleVector) {
				$this->lastError = 'Collection has no valid vectors with the query dimensions';
				return [];
			}
			if ($sawCompatibleVector && !$sawCompatibleModel) {
				$this->lastError = 'Collection embedding model does not match the query model';
				return [];
			}
			$cand = $this->topCandidates($cand, $poolLimit);

			$picked = $mmr ? $this->mmrSelect($cand, $topK, $lambda) : $cand;
			return array_map(fn($c) => ['ref' => $c['ref'], 'text' => $c['text'], 'meta' => $c['meta'], 'model' => $c['model'], 'score' => $c['score']], $picked);
		} catch (\Throwable $e) {
			$this->logError('nearest', $e);
			return [];
		} finally {
			if($stmt) {
				try { $stmt->closeCursor(); } catch (\Throwable $ignored) {}
			}
			if($unbuffered) {
				try { $db->setAttribute($bufferedOption, true); } catch (\Throwable $ignored) {}
			}
		}
	}

	/**
	 * Retrieve entries using local lexical relevance only.
	 *
	 * This avoids an external embedding request and is useful as a low-latency
	 * first pass before semantic search. Scores are normalized to 0..1.
	 */
	public function lexical(string $collection, string $query, int $topK = 5, array $opts = []): array {
		$this->lastError = '';
		$tokens = $this->lexicalTokens($query);
		if (!$tokens) return [];

		$filter = (array)($opts['filter'] ?? []);
		$minimumScore = max(0.0, min(1.0, (float)($opts['minScore'] ?? 0.42)));
		$topK = max(1, $topK);
		$db = $this->wire('database');
		$bufferedOption = class_exists('\Pdo\Mysql') && defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
			? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
			: (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
				? constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
				: null);
		$unbuffered = false;
		$stmt = null;

		try {
			if($bufferedOption !== null && method_exists($db, 'setAttribute')) {
				try {
					$unbuffered = $db->setAttribute($bufferedOption, false);
				} catch (\Throwable $ignored) {}
			}
			$stmt = $db->prepare(
				"SELECT `ref`,`text`,`meta`,`model` FROM `" . self::TABLE . "` WHERE `collection` = ?"
			);
			$stmt->execute([$collection]);
			$candidates = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$meta = json_decode((string)$row['meta'], true) ?: [];
				if ($filter && !$this->matchesFilter($meta, $filter)) continue;
				$title = trim((string)($meta['title'] ?? $meta['name'] ?? ''));
				$score = $this->lexicalScore((string)$row['text'], $title, $tokens);
				if ($score < $minimumScore) continue;
				$candidates[] = [
					'ref' => (string)$row['ref'],
					'text' => (string)$row['text'],
					'meta' => $meta,
					'model' => (string)$row['model'],
					'score' => $score,
				];
				if (count($candidates) >= max($topK * 8, 40)) {
					$candidates = $this->topCandidates($candidates, max($topK * 3, 15));
				}
			}
			return $this->topCandidates($candidates, $topK);
		} catch (\Throwable $e) {
			$this->logError('lexical', $e);
			return [];
		} finally {
			if($stmt) {
				try { $stmt->closeCursor(); } catch (\Throwable $ignored) {}
			}
			if($unbuffered) {
				try { $db->setAttribute($bufferedOption, true); } catch (\Throwable $ignored) {}
			}
		}
	}

	/** Significant, language-tolerant tokens used by local lexical retrieval. */
	protected function lexicalTokens(string $query): array {
		$query = mb_strtolower(mb_substr(trim($query), 0, 2000));
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$stop = array_flip([
			'the', 'and', 'for', 'with', 'from', 'about', 'what', 'which', 'who', 'where',
			'when', 'why', 'how', 'tell', 'show', 'please', 'can', 'could', 'would', 'should',
			'are', 'was', 'were', 'does', 'did', 'you', 'your', 'me', 'my', 'this', 'that',
			'что', 'какой', 'какая', 'какие', 'кто', 'где', 'когда', 'почему', 'как', 'это',
			'расскажи', 'покажи', 'пожалуйста', 'можно', 'мне', 'мой', 'моя', 'про', 'для',
			'или', 'есть',
		]);
		$tokens = [];
		foreach($parts as $part) {
			if(mb_strlen($part) < 3 || isset($stop[$part])) continue;
			$tokens[$part] = true;
			if(count($tokens) >= 16) break;
		}
		return array_keys($tokens);
	}

	/** Score token coverage, title matches and an exact significant phrase. */
	protected function lexicalScore(string $text, string $title, array $tokens): float {
		if(!$tokens) return 0.0;
		$text = mb_strtolower($text);
		$title = mb_strtolower($title);
		$matched = 0;
		$titleMatched = 0;
		foreach($tokens as $token) {
			if(mb_stripos($text, $token) !== false || mb_stripos($title, $token) !== false) $matched++;
			if($title !== '' && mb_stripos($title, $token) !== false) $titleMatched++;
		}
		if($matched === 0) return 0.0;
		$count = count($tokens);
		$phrase = implode(' ', $tokens);
		$phraseMatch = count($tokens) > 1
			&& (mb_stripos($text, $phrase) !== false || mb_stripos($title, $phrase) !== false);
		$score = ($matched / $count) * 0.65
			+ ($titleMatched / $count) * 0.25
			+ ($phraseMatch ? 0.10 : 0.0);
		return round(min(1.0, $score), 6);
	}

	/** Sort by relevance and retain only the candidates that can still be selected. */
	protected function topCandidates(array $candidates, int $limit): array {
		usort($candidates, function($a, $b) {
			$byScore = $b['score'] <=> $a['score'];
			return $byScore !== 0 ? $byScore : strcmp($a['ref'], $b['ref']);
		});
		return array_slice($candidates, 0, max(1, $limit));
	}

	/**
	 * Maximal Marginal Relevance: greedily pick items that are relevant to the
	 * query yet dissimilar to those already picked (λ·relevance − (1−λ)·maxSim).
	 * Candidates must carry `_vec` + `_norm`; input should be sorted by score desc.
	 */
	protected function mmrSelect(array $cand, int $topK, float $lambda): array {
		$pool = array_slice($cand, 0, max($topK * 6, 30)); // defensive bound for direct/internal callers
		$selected = [];
		while (count($selected) < $topK && $pool) {
			$bestIdx = -1; $bestVal = -INF;
			foreach ($pool as $i => $c) {
				$maxSim = 0.0;
				foreach ($selected as $s) {
					$sim = $this->cosine($c['_vec'], $s['_vec'], $c['_norm']);
					if ($sim > $maxSim) $maxSim = $sim;
				}
				$val = $lambda * $c['score'] - (1 - $lambda) * $maxSim;
				if ($val > $bestVal) { $bestVal = $val; $bestIdx = $i; }
			}
			if ($bestIdx < 0) break;
			$selected[] = $pool[$bestIdx];
			unset($pool[$bestIdx]);
			$pool = array_values($pool);
		}
		return $selected;
	}

	/**
	 * Aggregate stats for a collection (no JSON parsing): total entries, distinct
	 * source docs (ref before '#'), embedding model(s), dims, avg text length,
	 * last modified.
	 */
	public function collectionStats(string $collection): array {
		$this->lastError = '';
		$zero = ['total' => 0, 'docs' => 0, 'dims' => 0, 'avg_chars' => 0, 'last_modified' => '', 'models' => ''];
		try {
			$stmt = $this->wire('database')->prepare(
				"SELECT COUNT(*) AS total,
				        COUNT(DISTINCT SUBSTRING_INDEX(`ref`, '#', 1)) AS docs,
				        MAX(`dims`) AS dims,
				        ROUND(AVG(CHAR_LENGTH(`text`))) AS avg_chars,
				        MAX(`modified`) AS last_modified,
				        GROUP_CONCAT(DISTINCT `model` ORDER BY `model` SEPARATOR ', ') AS models
				 FROM `" . self::TABLE . "` WHERE `collection` = ?"
			);
			$stmt->execute([$collection]);
			$r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
			return [
				'total'         => (int)($r['total'] ?? 0),
				'docs'          => (int)($r['docs'] ?? 0),
				'dims'          => (int)($r['dims'] ?? 0),
				'avg_chars'     => (int)($r['avg_chars'] ?? 0),
				'last_modified' => (string)($r['last_modified'] ?? ''),
				'models'        => (string)($r['models'] ?? ''),
			];
		} catch (\Throwable $e) {
			$this->logError('collectionStats', $e);
			return $zero;
		}
	}

	/** Browse raw entries of a collection (no vectors), ordered by ref. */
	public function entries(string $collection, int $limit = 50, int $offset = 0): array {
		$this->lastError = '';
		try {
			$stmt = $this->wire('database')->prepare(
				"SELECT `ref`,`text`,`meta`,`model`,`dims`,`modified` FROM `" . self::TABLE . "` WHERE `collection` = :c ORDER BY `ref` LIMIT :off, :lim"
			);
			$stmt->bindValue(':c', $collection, \PDO::PARAM_STR);
			$stmt->bindValue(':off', max(0, $offset), \PDO::PARAM_INT);
			$stmt->bindValue(':lim', max(1, $limit), \PDO::PARAM_INT);
			$stmt->execute();
			return array_map(fn($r) => [
				'ref'      => (string)$r['ref'],
				'text'     => (string)$r['text'],
				'meta'     => json_decode((string)$r['meta'], true) ?: [],
				'model'    => (string)$r['model'],
				'dims'     => (int)$r['dims'],
				'modified' => (string)$r['modified'],
			], $stmt->fetchAll(\PDO::FETCH_ASSOC));
		} catch (\Throwable $e) {
			$this->logError('entries', $e);
			return [];
		}
	}

	/** [['collection'=>name, 'count'=>n], ...] for every collection present. */
	public function collections(): array {
		$this->lastError = '';
		try {
			$prefix = self::STAGING_PREFIX;
			$prefixLength = strlen($prefix);
			$rows = $this->wire('database')->query("SELECT `collection`, COUNT(*) AS c FROM `" . self::TABLE . "` WHERE LEFT(`collection`, {$prefixLength}) <> '{$prefix}' GROUP BY `collection` ORDER BY `collection`")->fetchAll(\PDO::FETCH_ASSOC);
			return array_map(fn($r) => ['collection' => (string)$r['collection'], 'count' => (int)$r['c']], $rows);
		} catch (\Throwable $e) {
			$this->logError('collections', $e);
			return [];
		}
	}

	/** Delete abandoned internal staging rows older than the given age. */
	public function cleanupStaging(int $maxAge = 86400): int {
		$this->lastError = '';
		try {
			$age = max(300, $maxAge);
			$prefix = self::STAGING_PREFIX;
			$prefixLength = strlen($prefix);
			$stmt = $this->wire('database')->prepare("DELETE FROM `" . self::TABLE . "` WHERE LEFT(`collection`, {$prefixLength}) = '{$prefix}' AND `modified` < DATE_SUB(NOW(), INTERVAL {$age} SECOND)");
			$stmt->execute();
			return $stmt->rowCount();
		} catch (\Throwable $e) {
			$this->logError('cleanupStaging', $e);
			return 0;
		}
	}

	public function delete(string $collection, string $ref): bool {
		$this->lastError = '';
		try { return $this->wire('database')->prepare("DELETE FROM `" . self::TABLE . "` WHERE `collection`=? AND `ref`=?")->execute([$collection, $ref]); }
		catch (\Throwable $e) { $this->logError('delete', $e); return false; }
	}

	/** Delete a base ref AND its chunk children (`{ref}#0`, `{ref}#1`, …). */
	public function deleteRef(string $collection, string $ref): bool {
		$this->lastError = '';
		try {
			return $this->wire('database')
				->prepare("DELETE FROM `" . self::TABLE . "` WHERE `collection`=? AND (`ref`=? OR `ref` LIKE ?)")
				->execute([$collection, $ref, $this->likeEscape($ref) . '#%']);
		} catch (\Throwable $e) { $this->logError('deleteRef', $e); return false; }
	}

	/** Escape LIKE wildcards in a literal so it matches verbatim before the `#%`. */
	protected function likeEscape(string $s): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
	}

	public function clearCollection(string $collection): bool {
		$this->lastError = '';
		try { return $this->wire('database')->prepare("DELETE FROM `" . self::TABLE . "` WHERE `collection`=?")->execute([$collection]); }
		catch (\Throwable $e) { $this->logError('clearCollection', $e); return false; }
	}

	public function count(string $collection): int {
		$this->lastError = '';
		try {
			$s = $this->wire('database')->prepare("SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE `collection`=?");
			$s->execute([$collection]);
			return (int)$s->fetchColumn();
		} catch (\Throwable $e) { $this->logError('count', $e); return 0; }
	}

	/** Number of stored chunks grouped by their base ref (the part before `#`). */
	public function baseRefCounts(string $collection): array {
		$this->lastError = '';
		try {
			$stmt = $this->wire('database')->prepare(
				"SELECT SUBSTRING_INDEX(`ref`, '#', 1) AS base_ref, COUNT(*) AS chunks
				 FROM `" . self::TABLE . "` WHERE `collection`=? GROUP BY base_ref"
			);
			$stmt->execute([$collection]);
			$counts = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$ref = (string)($row['base_ref'] ?? '');
				if ($ref !== '') $counts[$ref] = (int)($row['chunks'] ?? 0);
			}
			return $counts;
		} catch (\Throwable $e) {
			$this->logError('baseRefCounts', $e);
			return [];
		}
	}

	/** Copy complete documents from a live collection into an internal staging collection. */
	public function copyRefs(string $source, string $staging, array $baseRefs): int {
		$this->lastError = '';
		$baseRefs = array_values(array_unique(array_filter(array_map('strval', $baseRefs), fn($ref) => $ref !== '' && mb_strlen($ref) <= 190)));
		if ($source === '' || $staging === '' || $source === $staging || $this->isStagingCollection($source)
			|| !$this->isStagingCollection($staging) || !$baseRefs) {
			$this->lastError = 'Invalid source, staging collection, or refs';
			return 0;
		}
		$db = $this->wire('database');
		try {
			$this->ensureTable();
			$placeholders = implode(',', array_fill(0, count($baseRefs), '?'));
			$stmt = $db->prepare(
				"INSERT INTO `" . self::TABLE . "` (`collection`,`ref`,`text`,`meta`,`model`,`dims`,`vector`)
				 SELECT ?,`ref`,`text`,`meta`,`model`,`dims`,`vector` FROM `" . self::TABLE . "`
				 WHERE `collection`=? AND SUBSTRING_INDEX(`ref`, '#', 1) IN ({$placeholders})"
			);
			$stmt->execute(array_merge([$staging, $source], $baseRefs));
			return $stmt->rowCount();
		} catch (\Throwable $e) {
			$this->logError('copyRefs', $e);
			return 0;
		}
	}

	/** Replace one base ref and all of its chunks in a single DB transaction. */
	public function replaceRef(string $collection, string $baseRef, array $items): bool {
		$this->lastError = '';
		if (!$this->validIdentity($collection, $baseRef) || !$items) { $this->lastError = 'Invalid collection, ref, or chunk set'; return false; }
		$items = array_values($items);
		if (!$this->validReplacementItems($collection, $baseRef, $items)) return false;
		$db = $this->wire('database');
		try {
			$first = $items[0];
			$db->beginTransaction();
			if (!$this->collectionAccepts($collection, count($first['vector']), (string)($first['model'] ?? ''), '', $baseRef, true)) throw new \RuntimeException($this->lastError ?: 'Collection compatibility check failed');
			$db->prepare("DELETE FROM `" . self::TABLE . "` WHERE `collection`=? AND (`ref`=? OR `ref` LIKE ?)")
				->execute([$collection, $baseRef, $this->likeEscape($baseRef) . '#%']);
			foreach ($items as $item) {
				if (!$this->insertRow($collection, $item)) throw new \RuntimeException('Invalid chunk row');
			}
			$db->commit();
			return true;
		} catch (\Throwable $e) {
			if ($db && $db->inTransaction()) $db->rollBack();
			$this->logError('replaceRef', $e);
			return false;
		}
	}

	/** Validate an entire document replacement before any existing rows are deleted. */
	protected function validReplacementItems(string $collection, string $baseRef, array $items): bool {
		if (!isset($items[0]) || !is_array($items[0])) { $this->lastError = 'Invalid replacement row'; return false; }
		$firstVector = $items[0]['vector'] ?? null;
		if (!is_array($firstVector) || !$this->validVector($firstVector)) { $this->lastError = 'Invalid replacement vector'; return false; }
		$dims = count($firstVector);
		$model = (string)($items[0]['model'] ?? '');
		$seen = [];
		foreach ($items as $item) {
			if (!is_array($item)) { $this->lastError = 'Invalid replacement row'; return false; }
			$ref = (string)($item['ref'] ?? '');
			$vector = $item['vector'] ?? null;
			if (!$this->validIdentity($collection, $ref) || ($ref !== $baseRef && strpos($ref, $baseRef . '#') !== 0)) {
				$this->lastError = 'Replacement ref does not belong to the base document';
				return false;
			}
			if (isset($seen[$ref])) { $this->lastError = 'Duplicate replacement ref'; return false; }
			$seen[$ref] = true;
			if (!is_array($vector) || !$this->validVector($vector) || count($vector) !== $dims) {
				$this->lastError = 'Replacement vectors have inconsistent dimensions';
				return false;
			}
			if ((string)($item['model'] ?? '') !== $model) {
				$this->lastError = 'Replacement rows use different embedding models';
				return false;
			}
		}
		return true;
	}

	/** Atomically promote a staging collection and remove all stale target rows. */
	public function replaceCollection(string $target, string $staging): bool {
		$this->lastError = '';
		if ($target === '' || $staging === '' || mb_strlen($target) > 128 || mb_strlen($staging) > 128 || $target === $staging
			|| $this->isStagingCollection($target) || !$this->isStagingCollection($staging)) {
			$this->lastError = 'Invalid target or staging collection';
			return false;
		}
		$db = $this->wire('database');
		try {
			$db->beginTransaction();
			$check = $db->prepare("SELECT 1 FROM `" . self::TABLE . "` WHERE `collection`=? LIMIT 1" . $this->selectForUpdate($db));
			$check->execute([$staging]);
			if (!$check->fetchColumn()) throw new \RuntimeException('Staging collection is empty or missing');
			$db->prepare("DELETE FROM `" . self::TABLE . "` WHERE `collection`=?")->execute([$target]);
			$promote = $db->prepare("UPDATE `" . self::TABLE . "` SET `collection`=? WHERE `collection`=?");
			$promote->execute([$target, $staging]);
			if ($promote->rowCount() < 1) throw new \RuntimeException('Staging collection promotion affected no rows');
			$db->commit();
			return true;
		} catch (\Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			$this->logError('replaceCollection', $e);
			return false;
		}
	}

	protected function insertRow(string $collection, array $item): bool {
		$ref = (string)($item['ref'] ?? ''); $vector = $item['vector'] ?? [];
		if (!$this->validIdentity($collection, $ref) || !is_array($vector) || !$this->validVector($vector)) return false;
		$stmt = $this->wire('database')->prepare("INSERT INTO `" . self::TABLE . "` (`collection`,`ref`,`text`,`meta`,`model`,`dims`,`vector`) VALUES (?,?,?,?,?,?,?)");
		return $stmt->execute([$collection, $ref, (string)($item['text'] ?? ''), $this->encodeJson((array)($item['meta'] ?? [])), (string)($item['model'] ?? ''), count($vector), $this->encodeJson(array_map('floatval', array_values($vector)))]);
	}

	protected function collectionAccepts(string $collection, int $dims, string $model, string $excludeRef = '', string $excludeBase = '', bool $forUpdate = false): bool {
		$sql = "SELECT `dims`,`model` FROM `" . self::TABLE . "` WHERE `collection`=?";
		$args = [$collection];
		if ($excludeRef !== '') { $sql .= " AND `ref`<>?"; $args[] = $excludeRef; }
		if ($excludeBase !== '') {
			$sql .= " AND NOT (`ref`=? OR `ref` LIKE ?)";
			$args[] = $excludeBase; $args[] = $this->likeEscape($excludeBase) . '#%';
		}
		$sql .= " LIMIT 1" . ($forUpdate ? $this->selectForUpdate($this->wire('database')) : '');
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute($args); $row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$row) return true;
		$ok = (int)$row['dims'] === $dims && ((string)$row['model'] === '' || $model === '' || hash_equals((string)$row['model'], $model));
		if (!$ok) {
			$this->lastError = 'Collection model or embedding dimensions do not match';
			$this->wire('log')->error('atlas: collection model/dimensions mismatch for ' . $collection);
		}
		return $ok;
	}

	/** SQLite transactions begin IMMEDIATE, so the database write lock replaces row locking. */
	protected function selectForUpdate($database): string {
		if (!method_exists($database, 'dialect')) return ' FOR UPDATE';
		$dialect = $database->dialect();
		return method_exists($dialect, 'name') && $dialect->name() === 'sqlite' ? '' : ' FOR UPDATE';
	}

	protected function validIdentity(string $collection, string $ref): bool { return $collection !== '' && $ref !== '' && mb_strlen($collection) <= 128 && mb_strlen($ref) <= 190; }
	public function isStagingCollection(string $collection): bool { return strpos($collection, self::STAGING_PREFIX) === 0; }
	protected function validVector(array $vector): bool { if (!$vector) return false; foreach ($vector as $v) if (!is_numeric($v) || !is_finite((float)$v)) return false; return true; }
	protected function encodeJson(array $value): string { $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); return $json; }
	protected function logError(string $operation, \Throwable $e): void {
		$this->lastError = $operation . ': ' . $e->getMessage();
		$this->wire('log')->error('atlas ' . $this->lastError);
	}

	/** All filter keys must equal the entry's meta (scalar ==, or `in` via array value). */
	protected function matchesFilter(array $meta, array $filter): bool {
		foreach ($filter as $k => $v) {
			$mv = $meta[$k] ?? null;
			if (is_array($v)) { if (!in_array($mv, $v)) return false; }
			elseif ($mv != $v) return false;
		}
		return true;
	}

	protected function norm(array $v): float {
		$s = 0.0; foreach ($v as $x) { $x = (float)$x; $s += $x * $x; }
		return sqrt($s);
	}

	/** Cosine similarity; pass $aNorm to reuse a precomputed ‖a‖ across many calls. */
	public function cosine(array $a, array $b, ?float $aNorm = null): float {
		$dot = 0.0; $bSq = 0.0; $n = count($a);
		for ($i = 0; $i < $n; $i++) {
			$x = (float)$a[$i]; $y = (float)($b[$i] ?? 0);
			$dot += $x * $y; $bSq += $y * $y;
		}
		$an = $aNorm ?? $this->norm($a); $bn = sqrt($bSq);
		if ($an == 0.0 || $bn == 0.0) return 0.0;
		return $dot / ($an * $bn);
	}
}
