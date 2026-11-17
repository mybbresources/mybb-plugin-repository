<?php
/**
 * Plugin Name: MyBB Resources Repository
 * Description: Safe catalog browser; requires separately installed MyBB Tooling Core.
 * Version: 0.1.6
 * License: MIT
 */
if (!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

function mybbresources_repository_core_requirement(): string { return '>=0.1.2 <1.0.0'; }
function mybbresources_repository_info(): array { return ['name'=>'MyBB Resources Repository','description'=>'Browse and manage plugins from plugins.mybbresources.com.','website'=>'https://plugins.mybbresources.com','author'=>'MyBB Resources','authorsite'=>'https://plugins.mybbresources.com','version'=>'0.1.6','compatibility'=>'18*']; }
function mybbresources_repository_is_installed(): bool { return file_exists(MYBB_ROOT.'inc/plugins/data/mybbresources-repository-installed'); }
function mybbresources_repository_install(): void {
    mybbresources_repository_require_core();
    $dir=MYBB_ROOT.'inc/plugins/data'; if(!is_dir($dir))@mkdir($dir,0750,true);
    $deny=$dir.'/.htaccess'; if(!file_exists($deny))file_put_contents($deny,"Require all denied\n");
    $index=$dir.'/index.html';if(!file_exists($index))file_put_contents($index,'');
    file_put_contents($dir.'/mybbresources-repository-installed','0.1.0');
}
function mybbresources_repository_uninstall(): void { /* Keep the package ledger; removing the management UI must not orphan its files. */ @unlink(MYBB_ROOT.'inc/plugins/data/mybbresources-repository-installed'); }
function mybbresources_repository_require_core(): void {
    $autoload=MYBB_ROOT.'inc/plugins/mybb-tooling/autoload.php';
    if(!is_file($autoload))throw new RuntimeException('Install the separate MyBB Tooling Core package first.');
    require_once $autoload;
    if(!class_exists('MyBB\\Tooling\\CoreRuntime'))throw new RuntimeException('Update MyBB Tooling Core first.');
    \MyBB\Tooling\CoreRuntime::assertInstalled(MYBB_ROOT,mybbresources_repository_core_requirement());
}
function mybbresources_repository_activate(): void { mybbresources_repository_require_core(); }
function mybbresources_repository_deactivate(): void { }
/** Apply MyBB's activate/deactivate callback and cache lifecycle to a verified plugin file. */
function mybbresources_repository_set_plugin_state(string $siteRoot, string $codename, bool $activate, object $cache, object $plugins): array {
    if (!class_exists('MyBB\\Tooling\\PluginLifecycle')) {
        $autoload = MYBB_ROOT . 'inc/plugins/mybb-tooling/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Shared plugin lifecycle library is unavailable.');
        require_once $autoload;
    }
    return \MyBB\Tooling\PluginLifecycle::setState($siteRoot, $codename, $activate, $cache, $plugins);
}
function mybbresources_repository_admin_menu(array &$sub_menu): void {
    $sub_menu[]=['id'=>'mybbresources_repository','title'=>'Plugin Repository','link'=>'index.php?module=config-mybbresources_repository'];
}
function mybbresources_repository_admin_action_handler(array &$actions): void {
    $actions['mybbresources_repository']=['active'=>'mybbresources_repository','file'=>'mybbresources_repository.php'];
}
function mybbresources_repository_admin_permissions(array &$admin_permissions): void {
    $admin_permissions['mybbresources_repository']='Can manage the MyBB Resources Plugin Repository';
}
global $plugins;
if (isset($plugins) && is_object($plugins) && method_exists($plugins, 'add_hook')) {
    $plugins->add_hook('admin_config_menu', 'mybbresources_repository_admin_menu');
    $plugins->add_hook('admin_config_action_handler', 'mybbresources_repository_admin_action_handler');
    $plugins->add_hook('admin_config_permissions', 'mybbresources_repository_admin_permissions');
}


/** Catalog download credentials belong to the forum, and are never rendered back into the ACP. */
function mybbresources_repository_token_key(): string
{
    global $mybb;
    $secret = $mybb->settings['internal']['encryption_key'] ?? '';
    if (!is_string($secret) || $secret === '' || !extension_loaded('sodium')) {
        throw new RuntimeException('MyBB encryption and Sodium are required to store a catalog token.');
    }
    return hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'mybbresources/catalog-download');
}

function mybbresources_repository_get_token(): ?string
{
    global $cache;
    $record = $cache->read('mybbresources_repository_credentials');
    if (!$record) {
        return null;
    }
    $encoded = base64_decode((string)($record['token'] ?? ''), true);
    if (!is_string($encoded) || strlen($encoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('The saved catalog token is unreadable. Replace it in Catalog access.');
    }
    $nonce = substr($encoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $token = sodium_crypto_secretbox_open(substr($encoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, mybbresources_repository_token_key());
    if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)) {
        throw new RuntimeException('The saved catalog token is unreadable. Replace it in Catalog access.');
    }
    return $token;
}

function mybbresources_repository_set_token(#[\SensitiveParameter] ?string $token): void
{
    global $cache;
    $record = [];
    if ($token !== null) {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            throw new InvalidArgumentException('Enter a valid MyBB Resources catalog token.');
        }
        $key = mybbresources_repository_token_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $record['token'] = base64_encode($nonce.sodium_crypto_secretbox($token, $nonce, $key));
    }
    $cache->update('mybbresources_repository_credentials', $record);
}
