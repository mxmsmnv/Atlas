<?php
$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Atlas.module.php');
$trait = (string)file_get_contents($root . '/src/AtlasPocketWireProviderTrait.php');
$read = strstr($trait, 'public function pocketWireRead');
$read = $read === false ? '' : (strstr($read, 'private function pocketWireCanRead', true) ?: '');
$checks = [
	str_contains($module, "'pocketWireProvider' => true"),
	str_contains($trait, 'pocketWireProviderInfo()'),
	str_contains($trait, 'pocketWireManifest(User $actor)'),
	str_contains($trait, 'pocketWireRead(string $resource, string $identifier, array $query, User $actor)'),
	str_contains($trait, "wire('user')") && str_contains($trait, "hasPermission('atlas-admin')"),
	str_contains($trait, "'id' => 'status'") && str_contains($trait, "'actions' => []"),
	str_contains($trait, "'layout' => 'dashboard'") && str_contains($trait, "'layout' => 'metrics'"),
	str_contains($trait, "'external_request_performed' => false") && str_contains($trait, "'write_tools' => false"),
	$read !== '' && !preg_match('/collections\(|entries\(|stats\(|search\(|isReady\(|squad\(|store\(|add\(|delete/i', $read),
];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Atlas PocketWire provider contract failed.\n"); exit(1); }
echo 'Atlas PocketWire provider contract passed: ' . count($checks) . " checks.\n";
