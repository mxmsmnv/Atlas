<?php namespace ProcessWire;

require_once __DIR__ . '/src/Admin/AdminSupport.php';
require_once __DIR__ . '/src/Admin/Overview.php';
require_once __DIR__ . '/src/Admin/Collections.php';
require_once __DIR__ . '/src/Admin/Search.php';
require_once __DIR__ . '/src/Admin/Reindex.php';

/**
 * ProcessAtlas — admin UI for the Atlas RAG store.
 *
 * Setup → Atlas: a status header, a collections table (entry counts + clear),
 * a hybrid similarity search with per-result delete, and a reindex tool that
 * embeds existing site pages into a collection. Built on ProcessWire's native
 * Inputfield / MarkupAdminDataTable components; all mutating actions are
 * POST + CSRF-validated.
 */
class ProcessAtlas extends Process {

	use ProcessAtlasAdminSupport;
	use ProcessAtlasOverview;
	use ProcessAtlasCollections;
	use ProcessAtlasSearch;
	use ProcessAtlasReindex;

	/** Default "public pages" selector (excludes the admin tree). */
	const SITE_SELECTOR = 'has_parent!=2, id!=2, template!=admin';
	const ADD_DRAFT_KEY = 'AtlasAddEntryDraft';
	const PAGE_META_KEY = 'atlas_index';
	const MAX_MANUAL_TEXT = 100000;
	const DEFAULT_REINDEX_PAGES = 1000;
	const MAX_REINDEX_PAGES = 10000;

	public static function getModuleInfo() {
		return [
			'title'    => 'Atlas',
			'version'  => 122,
			'summary'  => 'Browse, search and maintain the Atlas RAG store.',
			'author'   => 'Maxim Semenov',
			'icon'     => 'database',
			'requires' => ['Atlas'],
			'permission' => 'atlas-admin',
			'permissions' => ['atlas-admin' => 'Administer Atlas knowledge collections'],
			'page'     => ['name' => 'atlas', 'parent' => 'setup', 'title' => 'Atlas'],
		];
	}

	/** Install the new permission when upgrading an already-installed module. */
	public function ___upgrade($fromVersion, $toVersion): void {
		$this->ensureAdminPermission();
	}

	protected function ensureAdminPermission(): void {
		$permissions = $this->wire('permissions');
		$permission = $permissions->get('atlas-admin');
		if ($permission->id) return;
		$permission = $permissions->add('atlas-admin');
		$permission->title = 'Administer Atlas knowledge collections';
		$permissions->save($permission);
	}

	public function init(): void {
		parent::init();
		$this->ensureAdminPermission();
		$assetsPath = __DIR__ . '/assets/';
		$assetsUrl = $this->wire('config')->urls->siteModules . 'Atlas/assets/';
		$cssVersion = filemtime($assetsPath . 'ProcessAtlas.css') ?: 0;
		$jsVersion = filemtime($assetsPath . 'ProcessAtlas.js') ?: 0;
		$this->wire('config')->styles->add($assetsUrl . 'ProcessAtlas.css?v=' . $cssVersion);
		$this->wire('config')->scripts->add($assetsUrl . 'ProcessAtlas.js?v=' . $jsVersion);
	}

	public function execute() {
		$user = $this->wire('user');
		if (!$user || (!$user->isSuperuser() && !$user->hasPermission('atlas-admin'))) throw new WirePermissionException($this->_('You do not have permission to administer Atlas.'));
		$atlas   = $this->wire('modules')->get('Atlas');
		$input   = $this->wire('input');
		$session = $this->wire('session');
		$san     = $this->wire('sanitizer');

		// Mutating actions: POST + CSRF + contextual redirect to avoid resubmit.
		if ($input->post('action')) {
			$action = (string) $input->post('action');
			try { $session->CSRF->validate(); } catch (\Throwable $e) {
				if ($action === 'reindex_batch') $this->sendJson(['done' => true, 'error' => $this->_('The session expired. Reloading…')], 403);
				$this->error($this->_('Invalid CSRF token.')); $session->redirect('./');
			}
			if ($action === 'reindex_batch') $this->sendReindexBatch($atlas);
			if ($action === 'reindex_cancel') {
				$jobToken = (string)$input->post('job_token');
				if ($this->cancelReindex($atlas, $jobToken)) $this->message($this->_('Reindex cancelled. The previous collection was not changed.'));
				else $this->error($this->_('This reindex job changed in another tab and was not cancelled.'));
				$session->redirect('./?section=reindex');
			}
			$coll   = $san->text((string) $input->post('collection'));
			if ($action === 'clear' && $coll !== '') {
				if ($this->isReservedCollection($coll)) { $this->error($this->_('Internal Atlas collections cannot be modified here.')); $session->redirect('./?section=collections'); }
				if ($atlas->clearCollection($coll)) $this->message(sprintf($this->_('Cleared collection “%s”.'), $coll));
				elseif (!$this->hasAtlasError($atlas)) $this->error($this->_('Could not clear the collection.'));
				$session->redirect('./?section=collections');
			}
			if ($action === 'delete' && $coll !== '') {
				if ($this->isReservedCollection($coll)) { $this->error($this->_('Internal Atlas collections cannot be modified here.')); $session->redirect('./?section=collections'); }
				$ref = $san->text((string) $input->post('ref'));
				if ($ref !== '') {
					if ($atlas->deleteRef($coll, $ref)) $this->message(sprintf($this->_('Deleted “%s”.'), $ref));
					elseif (!$this->hasAtlasError($atlas)) $this->error($this->_('Could not delete the entry.'));
				}
				$session->redirect($this->actionReturnUrl($input, $san));
			}
			if ($action === 'reindex') {
				$this->startReindex($atlas, $input, $san);
				$session->redirect('./?section=reindex');
			}
			if ($action === 'add') {
				$added = $this->doAddEntry($atlas, $input, $san);
				$coll = $san->text((string) $input->post('collection'));
				if ($added) $session->redirect('./?section=collections&view=' . urlencode($coll));
				$url = './?section=collections&add=1' . ($coll !== '' ? '&collection=' . urlencode($coll) : '') . '#AtlasAddEntry';
				$session->redirect($url);
			}
		}

		$section = $san->pageName((string) $input->get('section')) ?: 'overview';
		if (!in_array($section, ['overview', 'collections', 'search', 'reindex'], true)) $section = 'overview';
		$view = $section === 'collections' ? $san->text((string) $input->get('view')) : '';
		$this->configurePageHeader($section, $view);
		$out = $this->renderHeader($atlas, $section);
		if ($section === 'overview') $content = $this->renderOverview($atlas);
		elseif ($section === 'search') $content = $this->renderSearch($atlas, $input, $san);
		elseif ($section === 'reindex') $content = $this->renderReindex($atlas);
		elseif ($view !== '') $content = $this->renderCollectionView($atlas, $view, $input, $san);
		else {
			$content = $this->renderCollections($atlas);
			if (!$this->hasAtlasError($atlas)) $content .= $this->renderAddEntry($atlas, $san->text((string)$input->get('collection')), (bool)$input->get('add'));
		}
		return '<div class="ProcessAtlas AtlasWorkspace pw-module-workspace">' . $out . $content . '</div>';
	}

	/** Use ProcessWire's native headline and breadcrumb trail for every Atlas view. */
	protected function configurePageHeader(string $section, string $collection = ''): void {
		$labels = [
			'overview' => $this->_('Atlas Overview'),
			'collections' => $this->_('Collections'),
			'search' => $this->_('Search'),
			'reindex' => $this->_('Reindex'),
		];
		$this->breadcrumb('./', $this->_('Atlas'));
		if ($section === 'collections' && $collection !== '') {
			$this->breadcrumb('./?section=collections', $this->_('Collections'));
			$this->headline($collection);
			$this->browserTitle($collection . ' — ' . $this->_('Atlas'));
			return;
		}
		$headline = $labels[$section] ?? $this->_('Atlas');
		$this->headline($headline);
		$this->browserTitle($headline . ' — ' . $this->_('Atlas'));
	}

	protected function renderHeader($atlas, string $active): string {
		$items = [
			'overview'    => ['dashboard', $this->_('Overview')],
			'collections' => ['folder-open', $this->_('Collections')],
			'search'      => ['search', $this->_('Search')],
			'reindex'     => ['refresh', $this->_('Reindex')],
		];
		$nav = '<ul class="uk-tab AtlasNav" aria-label="' . $this->_('Atlas sections') . '">';
		foreach ($items as $key => [$icon, $label]) {
			$cls = $key === $active ? ' class="uk-active"' : '';
			$current = $key === $active ? " aria-current='page'" : '';
			$nav .= "<li{$cls}><a href='./?section={$key}'{$current}>" . wireIconMarkup($icon) . " <span>{$label}</span></a></li>";
		}
		return '<div class="AtlasNavRow">' . $nav . '</ul>' . $this->renderStatus($atlas) . '</div>';
	}

}
