<?php namespace ProcessWire;

/** Local status only; collection identities, entries and provider config stay private. */
trait AtlasPocketWireProviderTrait {
	public function pocketWireProviderInfo(): array {
		return ['id' => 'atlas', 'title' => 'Atlas', 'module' => 'Atlas', 'moduleVersion' => '1.2.3'];
	}

	public function pocketWireManifest(User $actor): array {
		$ready = $this->pocketWireCanRead($actor);
		return [
			'id' => 'atlas', 'title' => 'Atlas', 'module' => 'Atlas', 'moduleVersion' => '1.2.3',
			'status' => $ready ? 'native_read_only' : 'unsupported',
			'permissions' => ['scope' => 'providers.read', 'processWire' => ['atlas-admin']],
			'presentation' => ['layout' => 'dashboard', 'icon' => 'library-outline', 'summary' => 'Local Atlas module status.', 'accentColor' => '#4F46E5'],
			'resources' => $ready ? [['id' => 'status', 'title' => 'Status', 'method' => 'GET', 'endpoint' => '/api/pocketwire/v1/providers/atlas/status', 'presentation' => ['layout' => 'metrics']]] : [],
			'actions' => [],
		];
	}

	public function pocketWireRead(string $resource, string $identifier, array $query, User $actor): array {
		if(!$this->pocketWireCanRead($actor)) throw new WirePermissionException('The atlas-admin permission is required.');
		if($resource !== 'status' || $identifier !== '') throw new Wire404Exception('Atlas resource was not found.');
		return [
			'version' => '1.2.3',
			'module_ready' => true,
			'collection_inventory_exposed' => false,
			'entry_content_exposed' => false,
			'provider_configuration_exposed' => false,
			'external_request_performed' => false,
			'write_tools' => false,
		];
	}

	private function pocketWireCanRead(User $actor): bool {
		$current = $this->wire('user');
		return $current instanceof User
			&& (int)$current->id === (int)$actor->id
			&& ($actor->isSuperuser() || ($actor->isLoggedin() && $actor->hasPermission('atlas-admin')));
	}
}
