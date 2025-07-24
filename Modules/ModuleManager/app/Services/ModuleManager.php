<?php

namespace Modules\ModuleManager\Services;

use App\Jobs\UpdateComposerJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;
use Nwidart\Modules\Facades\Module;
use Modules\ModuleManager\Models\Module as ModuleModel;
use Exception;
use Modules\ModuleManager\Models\ModuleMetadata;

class ModuleManager
{
    protected $moduleRepository;
    protected $modulesPath;

    public $output = '';
    public $command = '';
    public $error = '';
    public $success = false;
    
    public function __construct()
    {
        $this->modulesPath = config('modules.paths.modules');
        $this->moduleRepository = Module::getFacadeRoot();
    }
    
    /**
     * Install a module from a ZIP file
     *
     * @param string $zipPath Path to the module zip file
     * @return bool
     */
    public function installFromZip($zipPath)
    {
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new Exception("Unable to open zip file: $zipPath");
            }
            
            // Extract module to temp location first
            $tempPath = storage_path('app/module_temp/'.time());
            if (!File::exists($tempPath)) {
                File::makeDirectory($tempPath, 0755, true);
            }
            
            $zip->extractTo($tempPath);
            $zip->close();
            
            // Look for module.json to identify the module
            $moduleJson = $this->findModuleJson($tempPath);
            
            if (!$moduleJson) {
                throw new Exception("Invalid module: module.json not found");
            }
            
            $moduleData = json_decode(File::get($moduleJson), true);
            $moduleName = $moduleData['name'];
            
            // Check if module already exists
            if (File::exists($this->modulesPath . '/' . $moduleName)) {
                throw new Exception("Module '$moduleName' is already installed");
            }
            
            // Check module dependencies
            if (isset($moduleData['requires']) && !empty($moduleData['requires'])) {
                $this->checkDependencies($moduleData['requires']);
            }
            
            // Move module to modules directory
            $moduleDir = dirname($moduleJson);
            $targetDir = $this->modulesPath . '/' . $moduleName;
            
            // Ensure modules directory exists
            if (!File::exists($this->modulesPath)) {
                File::makeDirectory($this->modulesPath, 0755, true);
            }
            
            File::copyDirectory($moduleDir, $targetDir);

            // Store module data.
            $this->store($moduleName, $moduleData);

            // Update composer
            $this->updateComposer();
            
            // Register the module in modules_statuses.json
            $this->registerModuleInStatusFile($moduleName, false);
            
            // Reload modules to recognize the new one
            $this->reloadModules();
            
            // Clean up temp files
            File::deleteDirectory($tempPath);
            
            return true;
        } catch (Exception $e) {
            Log::error('Module installation failed: ' . $e->getMessage());
            // Clean up temp files if they exist
            if (isset($tempPath) && File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            throw $e;
        }
    }

    public function store($name, $data)
    {
        try {

            $moduleModel = ModuleModel::updateOrCreate(
                ['name' => $name],
                [
                    'name' => $name,
                    'alias' => $data['alias'] ?? strtolower($name),
                    'slug' => $data['slug'] ?? Str::slug($name),
                    'description' => $data['description'] ?? null,
                    'version' => $data['version'] ?? '1.0.0',
                    'providers' => $data['providers'] ?? [],
                    'files' => $data['files'] ?? [],
                    'requires' => $data['requires'] ?? [],
                    'type' => $data['type'] ?? 'feature',
                    'category' => $data['category'] ?? 'general',
                    'installed_at' => now(),
                    'last_updated_at' => now(),
                ]
            );
            
            // Metadata (all fields are nullable except module_id)
            
            $author = $data['author'] ?? [];

            $moduleModel->metadata()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'author' => $author['name'] ?? 'Guest',
                    'author_url' => $author['url'] ?? null,
                    'website' => $data['website'] ?? null,
                    'priority' => $data['priority'] ?? 0,
                    'license' => $data['license'] ?? null,
                    'license_type' => $data['license_type'] ?? 'Commercial',
                    'icon' => $data['icon'] ?? null,
                    'changelog' => $data['changelog'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'metadata' => $data['metadata'] ?? [],
                ]
            );

            $author = $data['author'] ?? [];

            // Paths (all fields are nullable except module_id)
            $moduleModel->paths()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'namespace' => $data['namespace'] ?? null,
                    'path' => $data['path'] ?? null,
                    'composer_json_path' => $data['composer_json_path'] ?? null,
                    'config_path' => $data['config_path'] ?? null,
                    'migration_path' => $data['migration_path'] ?? null,
                    'route_path' => $data['route_path'] ?? null,
                    'view_path' => $data['view_path'] ?? null,
                    'translation_path' => $data['translation_path'] ?? null,
                    'service_provider' => $data['service_provider'] ?? null,
                ]
            );

            // Customization Paths (all fields are nullable except module_id)
            $moduleModel->customizationPaths()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'customization_path' => $data['customization_path'] ?? null,
                    'customization_namespace' => $data['customization_namespace'] ?? null,
                    'customization_config_path' => $data['customization_config_path'] ?? null,
                    'customization_view_path' => $data['customization_view_path'] ?? null,
                    'customization_route_path' => $data['customization_route_path'] ?? null,
                    'customization_translation_path' => $data['customization_translation_path'] ?? null,
                ]
            );

            // Requirements (all fields are nullable except module_id)
            $moduleModel->requirements()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'min_php_version' => $data['min_php_version'] ?? null,
                    'max_php_version' => $data['max_php_version'] ?? null,
                    'min_laravel_version' => $data['min_laravel_version'] ?? null,
                    'max_laravel_version' => $data['max_laravel_version'] ?? null,
                    'min_core_version' => $data['min_core_version'] ?? null,
                    'max_core_version' => $data['max_core_version'] ?? null,
                ]
            );

            // Dependencies (dependency_name is required)
            $dependencies = is_string($data['dependencies'] ?? null) 
                ? json_decode($data['dependencies'], true) 
                : ($data['dependencies'] ?? []);
            
            if (!empty($dependencies)) {
                foreach ($dependencies as $name => $constraint) {
                    $moduleModel->dependencies()->updateOrCreate(
                        ['module_id' => $moduleModel->id, 'dependency_name' => $name],
                        [
                            'dependency_name' => $name,
                            'version_constraint' => $constraint ?? null,
                            'type' => 'required',
                        ]
                    );
                }
            }

            // Assets (all fields are nullable except module_id)
            $moduleModel->assets()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'screenshot' => $data['screenshot'] ?? null,
                    'banner_image' => $data['banner_image'] ?? null,
                ]
            );

            // Support (all fields are nullable except module_id)
            $moduleModel->support()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'homepage_url' => $data['homepage_url'] ?? null,
                    'repository_url' => $data['repository_url'] ?? null,
                    'issue_tracker_url' => $data['issue_tracker_url'] ?? null,
                    'documentation_url' => $data['documentation_url'] ?? null,
                    'support_email' => $data['support_email'] ?? null,
                    'support_phone' => $data['support_phone'] ?? null,
                    'support_url' => $data['support_url'] ?? null,
                    'update_url' => $data['update_url'] ?? null,
                ]
            );

            // Providers (all fields are nullable except module_id)
            $moduleModel->providers()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'provider_class' => $data['provider_class'] ?? null,
                    'additional_providers' => $data['additional_providers'] ?? null,
                    'aliases' => $data['aliases'] ?? null,
                    'middleware' => $data['middleware'] ?? null,
                    'route_middleware' => $data['route_middleware'] ?? null,
                    'migration_files' => $data['migration_files'] ?? null,
                    'menu_items' => $data['menu_items'] ?? null,
                ]
            );

            // Settings (all fields are nullable except module_id)
            $moduleModel->settings()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'settings' => $data['settings'] ?? null,
                ]
            );

            // Features (boolean fields have schema defaults)
            $moduleModel->features()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'requires_activation' => $data['requires_activation'] ?? true,
                    'supports_tenancy' => $data['supports_tenancy'] ?? false,
                    'has_migrations' => $data['has_migrations'] ?? false,
                    'has_seeds' => $data['has_seeds'] ?? false,
                    'has_assets' => $data['has_assets'] ?? false,
                    'has_settings' => $data['has_settings'] ?? false,
                    'has_admin_settings' => $data['has_admin_settings'] ?? false,
                    'has_tenant_settings' => $data['has_tenant_settings'] ?? false,
                    'is_multitenant' => $data['is_multitenant'] ?? false,
                    'is_translatable' => $data['is_translatable'] ?? false,
                ]
            );

            // Status Flags (boolean fields have schema defaults)
            $moduleModel->statusFlags()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'is_core' => false,
                    'is_enabled' => false,
                    'is_active' => false,
                    'is_installed' => true,
                    'is_deprecated' => $data['is_deprecated'] ?? false,
                    'is_featured' => $data['is_featured'] ?? false,
                    'is_beta' => $data['is_beta'] ?? false,
                    'is_stable' => $data['is_stable'] ?? true,
                    'is_experimental' => $data['is_experimental'] ?? false,
                ]
            );

            // Capabilities (boolean fields have schema defaults)
            $moduleModel->capabilities()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'is_installable' => $data['is_installable'] ?? true,
                    'is_upgradable' => $data['is_upgradable'] ?? true,
                    'is_removable' => $data['is_removable'] ?? true,
                    'is_configurable' => $data['is_configurable'] ?? true,
                    'is_cacheable' => $data['is_cacheable'] ?? true,
                    'is_loggable' => $data['is_loggable'] ?? true,
                    'is_monitorable' => $data['is_monitorable'] ?? true,
                    'is_auditable' => $data['is_auditable'] ?? true,
                    'is_customizable' => $data['is_customizable'] ?? false,
                    'is_legacy' => $data['is_legacy'] ?? false,
                    'is_protected' => $data['is_protected'] ?? false,
                ]
            );

            // Visibility (boolean fields have schema defaults)
            $moduleModel->visibility()->updateOrCreate(
                ['module_id' => $moduleModel->id],
                [
                    'is_hidden' => $data['is_hidden'] ?? false,
                    'is_hidden_from_list' => $data['is_hidden_from_list'] ?? false,
                    'is_hidden_from_search' => $data['is_hidden_from_search'] ?? false,
                    'is_hidden_from_admin' => $data['is_hidden_from_admin'] ?? false,
                    'is_hidden_from_user' => $data['is_hidden_from_user'] ?? false,
                    'is_hidden_from_api' => $data['is_hidden_from_api'] ?? false,
                    'is_hidden_from_cli' => $data['is_hidden_from_cli'] ?? false,
                    'is_hidden_from_web' => $data['is_hidden_from_web'] ?? false,
                    'is_hidden_from_mobile' => $data['is_hidden_from_mobile'] ?? false,
                    'is_hidden_from_desktop' => $data['is_hidden_from_desktop'] ?? false,
                    'is_hidden_from_widget' => $data['is_hidden_from_widget'] ?? false,
                    'is_hidden_from_dashboard' => $data['is_hidden_from_dashboard'] ?? false,
                    'is_hidden_from_menu' => $data['is_hidden_from_menu'] ?? false,
                    'is_hidden_from_toolbar' => $data['is_hidden_from_toolbar'] ?? false,
                ]
            );
            
        } catch (Exception $e) {
            // dd($e->getMessage(), $e->getTraceAsString(), $e->getFile(), $e->getLine());
        }
    }

    /**
     * Check if all required dependencies are met
     *
     * @param array $dependencies List of required modules and their versions
     * @throws Exception if dependencies are not satisfied
     */
    protected function checkDependencies($dependencies)
    {
        $missing = [];
        
        foreach ($dependencies as $module => $version) {
            $installedModule = $this->moduleRepository->find($module);
            
            if (!$installedModule) {
                $missing[] = "$module (version $version)";
                continue;
            }
            
            // If specific version is required, check version compatibility
            if ($version !== '*') {
                $installedVersion = $installedModule->get('version');
                
                if (!$this->isVersionCompatible($installedVersion, $version)) {
                    $missing[] = "$module (requires $version, installed $installedVersion)";
                }
            }
        }
        
        if (!empty($missing)) {
            throw new Exception("Missing dependencies: " . implode(', ', $missing));
        }
    }
    
    /**
     * Check if installed version is compatible with required version
     *
     * @param string $installed Installed version
     * @param string $required Required version
     * @return bool
     */
    protected function isVersionCompatible($installed, $required)
    {
        // Simple version comparison, can be extended with semver
        return version_compare($installed, $required, '>=');
    }
    
    /**
     * Find module.json file in the extracted zip
     *
     * @param string $path The extracted path to search in
     * @return string|null Path to the module.json file
     */
    protected function findModuleJson($path)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'module.json') {
                return $file->getPathname();
            }
        }
        
        return null;
    }
    
    /**
     * Run post-install tasks for a module
     *
     * @param string $name The module name
     */
    protected function runModulePostInstall($name)
    {
        // Scan for modules
        Module::scan();
        
        // Enable the module
        // Artisan::call("module:enable", ['module' => $name, '--force' => true, '--no-interaction' => true]);
        
        // Run migrations if they exist
        if (File::exists($this->modulesPath . '/' . $name . '/database/migrations')) {
            Artisan::call('module:migrate', ['module' => $name, '--force' => true, '--no-interaction' => true]);
        }
        
        // Run seeders if they exist and configuration allows it
        if (config('module_manager.run_seeders', false) && 
            File::exists($this->modulesPath . '/' . $name . '/database/seeders')) {
            Artisan::call("module:seed", ['module' => $name, '--force' => true, '--no-interaction' => true]);
        }
        
        // Publish assets if they exist
        if (File::exists($this->modulesPath . '/' . $name . '/resources/assets')) {
            Artisan::call("module:publish", ['module' => $name, '--force' => true, '--no-interaction' => true]);
        }
        
        // Run any custom installation commands if defined in module.json
        $module = $this->moduleRepository->find($name);
        if ($module) {
            $postInstallCommands = $module->get('post-install-commands', []);
            
            foreach ($postInstallCommands as $command) {
                Artisan::call($command);
            }
        }
        
        // Clear cache
        Artisan::call('cache:clear');
    }

    /**
     * Enable a module
     *
     * @param string $moduleName The name of the module to enable
     * @return bool
     */
    public function enable($name)
    {
        try {
            // Check if module exists
            $module = $this->moduleRepository->find($name);
            
            if (!$module) {
                // Try reloading modules first before giving up
                $this->reloadModules();
                $module = $this->moduleRepository->find($name);
                
                if (!$module) {
                    throw new Exception("Module $name not found");
                }
            }
            
            // Update the status file
            $this->updateModuleStatus($name, true);
            
            // Enable the module
            $module->enable();

            $moduleModel = ModuleModel::where('name', $name)->first();
            $statusFlag = $moduleModel->statusFlags()->first();

            if ($statusFlag) {
                $statusFlag->is_enabled = true;
                $statusFlag->save();
            }
            
            // Run post-install if needed
            $this->runModulePostInstall($name);
            
            // Clear cache
            Artisan::call('cache:clear');
            
            return true;
        } catch (Exception $e) {
            Log::error('Module enable failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Disable a module
     *
     * @param string $moduleName The name of the module to disable
     * @return bool
     */
    public function disable($name)
    {
        try {
            // Check if module exists
            $module = $this->moduleRepository->find($name);
            
            if (!$module) {
                // Try reloading modules first before giving up
                $this->reloadModules();
                $module = $this->moduleRepository->find($name);
                
                if (!$module) {
                    throw new Exception("Module $name not found");
                }
            }
            
            // Update the status file
            $this->updateModuleStatus($name, false);
            
            // Disable the module
            $module->disable();

            // Update composer
            $this->updateComposer();

            $moduleModel = ModuleModel::where('name', $name)->first();
            $statusFlag = $moduleModel->statusFlags()->first();
            if ($statusFlag) {
                $statusFlag->is_enabled = false;
                $statusFlag->save();
            }
            
            // Clear cache
            Artisan::call('cache:clear');
            
            return true;
        } catch (Exception $e) {
            Log::error('Module disable failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Uninstall a module
     *
     * @param string $name The module name
     * @return bool
     */
    public function uninstall($name)
    {
        try {
            // First check if the module directory exists
            $modulePath = $this->modulesPath . '/' . $name;
            if (!File::exists($modulePath)) {
                throw new Exception("Module directory does not exist: $modulePath");
            }
            
            // Try to get the module from the repository
            $module = $this->moduleRepository->find($name);
            
            if ($module) {
                // Check if other modules depend on this
                $this->checkReverseDependencies($name);
                
                // Run pre-uninstall commands if defined in module.json
                $preUninstallCommands = $module->get('pre-uninstall-commands', []);
                
                foreach ($preUninstallCommands as $command) {
                    Artisan::call($command);
                }
                
                // Disable the module first
                if ($module->isEnabled()) {
                    $module->disable();
                }
                
                // Run migrations rollback if needed
                Artisan::call("module:migrate-rollback", ['module' => $name, '--force' => true, '--no-interaction' => true]);
            }
            
            // Remove module purchase records if any - WITH SCHEMA CHECK
            if (Schema::hasTable('module_licenses')) {
                DB::table('module_licenses')->where('module', $name)->delete();
            }
            
            // Delete the module
            $modulePath = $this->modulesPath . '/' . $name;
            $deleted = File::deleteDirectory($modulePath);
            
            // Clean up published assets if they exist
            $assetsPath = public_path('modules/' . Str::kebab($name));
            if (File::exists($assetsPath)) {
                File::deleteDirectory($assetsPath);
            }
            
            // Remove from modules_statuses.json
            $this->removeModuleFromStatusFile($name);
            
            // Rescan modules
            Module::scan();

            // Update composer
            $this->updateComposer();

            $moduleModel = ModuleModel::where('name', $name)->first();

            if ($moduleModel) {
                $moduleModel->forceDelete();
            }
            
            // Clear cache
            Artisan::call('cache:clear');
            
            return $deleted;
        } catch (Exception $e) {
            Log::error('Module uninstallation failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if any module depends on the module being uninstalled
     *
     * @param string $name Name of the module to be uninstalled
     * @throws Exception if other modules depend on this one
     */
    protected function checkReverseDependencies($name)
    {
        $dependentModules = [];
        
        foreach ($this->moduleRepository->all() as $module) {
            if ($module->getName() === $name) {
                continue;
            }
            
            $dependencies = $module->get('requires', []);
            
            if (isset($dependencies[$name])) {
                $dependentModules[] = $module->getName();
            }
        }
        
        if (!empty($dependentModules)) {
            throw new Exception("Cannot uninstall module $name because the following modules depend on it: " . implode(', ', $dependentModules));
        }
    }
    
    /**
     * Get available modules from a repository
     *
     * @param string $repositoryUrl URL of the repository API
     * @return array
     */
    public function getAvailableModules($repositoryUrl)
    {
        $cacheKey = 'module_repository_' . md5($repositoryUrl);
        
        return Cache::remember($cacheKey, 60 * 24, function () use ($repositoryUrl) {
            try {
                $response = Http::get($repositoryUrl);
                if ($response->successful()) {
                    return $response->json();
                }
                return [];
            } catch (Exception $e) {
                Log::error('Failed to fetch modules from repository: ' . $e->getMessage());
                return [];
            }
        });
    }
    
    /**
     * Download a module from URL
     *
     * @param string $url The download URL
     * @param string $apiKey API key for authentication if required
     * @return string|bool Path to the downloaded file or false on failure
     */
    public function downloadModule($url, $apiKey = null)
    {
        try {
            $tempFile = storage_path('app/module_downloads/' . Str::random(40) . '.zip');
            
            // Create directory if it doesn't exist
            if (!File::exists(dirname($tempFile))) {
                File::makeDirectory(dirname($tempFile), 0755, true);
            }
            
            $headers = [];
            if ($apiKey) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }
            
            $response = Http::withHeaders($headers)->withOptions([
                'sink' => $tempFile,
            ])->get($url);
            
            if ($response->successful()) {
                return $tempFile;
            }
            
            return false;
        } catch (Exception $e) {
            Log::error('Module download failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save purchase information for license verification
     *
     * @param string $purchaseCode
     * @param array $verificationData
     * @return bool
     */
    public function savePurchaseInfo($purchaseCode, $verificationData)
    {
        try {
            // Check if the license table exists, if not create it
            if (!Schema::hasTable('module_licenses')) {
                Schema::create('module_licenses', function ($table) {
                    $table->id();
                    $table->string('module');
                    $table->string('purchase_code');
                    $table->text('verification_data');
                    $table->timestamp('valid_until')->nullable();
                    $table->timestamps();
                });
            }
            
            // Extract module name from verification data
            $itemName = $verificationData['item']['name'] ?? null;
            if (!$itemName) {
                return false;
            }
            
            // Convert item name to probable module name (simple conversion)
            $moduleName = Str::studly(Str::slug($itemName, '_'));
            
            // Store the license information
            DB::table('module_licenses')->updateOrInsert(
                ['module' => $moduleName, 'purchase_code' => $purchaseCode],
                [
                    'verification_data' => json_encode($verificationData),
                    'valid_until' => now()->addYear(), // Typically, Envato items have 1 year support
                    'updated_at' => now(),
                ]
            );
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to save purchase info: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check for updates for installed modules
     * 
     * @return array Array of modules with updates available
     */
    public function checkForUpdates()
    {
        $updatesAvailable = [];
        $marketplaceUrl = config('module_manager.marketplace_api_url');
        
        if (!$marketplaceUrl) {
            return [];
        }
        
        $availableModules = $this->getAvailableModules($marketplaceUrl);
        
        foreach ($this->moduleRepository->all() as $installedModule) {
            $moduleName = $installedModule->getName();
            $currentVersion = $installedModule->get('version');
            
            // Find this module in available modules
            foreach ($availableModules as $availableModule) {
                if ($availableModule['name'] === $moduleName && 
                    version_compare($availableModule['version'], $currentVersion, '>')) {
                    $updatesAvailable[$moduleName] = [
                        'current_version' => $currentVersion,
                        'new_version' => $availableModule['version'],
                        'description' => $availableModule['description'] ?? '',
                        'download_url' => $availableModule['download_url'] ?? null,
                    ];
                    break;
                }
            }
            
            // Check for purchased modules if Envato integration is enabled
            if (config('module_manager.envato_api_token')) {
                // Check if this module has a license record
                $license = DB::table('module_licenses')->where('module', $moduleName)->first();
                
                if ($license) {
                    $envatoService = app(EnvatoService::class);
                    $verification = $envatoService->verifyPurchase($license->purchase_code);
                    
                    if ($verification && isset($verification['item']['version'])) {
                        $availableVersion = $verification['item']['version'];
                        
                        if (version_compare($availableVersion, $currentVersion, '>')) {
                            $updatesAvailable[$moduleName] = [
                                'current_version' => $currentVersion,
                                'new_version' => $availableVersion,
                                'description' => $verification['item']['description'] ?? '',
                                'purchase_code' => $license->purchase_code,
                                'from_envato' => true,
                            ];
                        }
                    }
                }
            }
        }
        
        return $updatesAvailable;
    }
    
    /**
     * Update a specific module
     *
     * @param string $module Module name to update
     * @return bool
     */
    public function updateModule($module)
    {
        try {
            $updates = $this->checkForUpdates();
            
            if (!isset($updates[$module])) {
                throw new Exception("No updates available for module $module");
            }
            
            $updateInfo = $updates[$module];
            
            // Backup the current module
            $backupPath = $this->backupModule($module);
            
            if (!$backupPath) {
                throw new Exception("Failed to create backup for module $module");
            }
            
            try {
                // Get current module data for future reference
                $currentModule = $this->moduleRepository->find($module);
                
                if (!$currentModule) {
                    throw new Exception("Module $module not found");
                }
                
                $isEnabled = $currentModule->isEnabled();
                
                // First uninstall the module without running migrations rollback
                $this->uninstallWithoutMigrationRollback($module);
                
                // Download and install the new version
                if (isset($updateInfo['from_envato']) && $updateInfo['from_envato']) {
                    $envatoService = app(EnvatoService::class);
                    $downloadUrl = $envatoService->getDownloadUrl($updateInfo['purchase_code']);
                    
                    if (!$downloadUrl) {
                        throw new Exception("Failed to get download URL from Envato");
                    }
                    
                    $downloadPath = $this->downloadModule($downloadUrl);
                } else {
                    $downloadPath = $this->downloadModule($updateInfo['download_url']);
                }
                
                if (!$downloadPath) {
                    throw new Exception("Failed to download module update");
                }
                
                // Install the new version
                $installed = $this->installFromZip($downloadPath);
                
                if (!$installed) {
                    throw new Exception("Failed to install module update");
                }
                
                // Set the module status (enabled/disabled) to what it was before
                $updatedModule = $this->moduleRepository->find($module);
                if ($updatedModule) {
                    if ($isEnabled && !$updatedModule->isEnabled()) {
                        $updatedModule->enable();
                    } elseif (!$isEnabled && $updatedModule->isEnabled()) {
                        $updatedModule->disable();
                    }
                }
                
                // Clean up the backup
                if (File::exists($backupPath)) {
                    File::deleteDirectory($backupPath);
                }
                
                return true;
            } catch (Exception $e) {
                // If update fails, restore from backup
                $this->restoreModuleFromBackup($module, $backupPath);
                throw $e;
            }
        } catch (Exception $e) {
            Log::error('Module update failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create a backup of a module before updating
     *
     * @param string $module Module name to backup
     * @return string|bool Path to backup or false on failure
     */
    protected function backupModule($module)
    {
        try {
            $modulePath = $this->modulesPath . '/' . $module;
            $backupPath = storage_path('app/module_backups/' . $module . '_' . time());
            
            if (!File::exists(dirname($backupPath))) {
                File::makeDirectory(dirname($backupPath), 0755, true);
            }
            
            File::copyDirectory($modulePath, $backupPath);
            
            return $backupPath;
        } catch (Exception $e) {
            Log::error('Module backup failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Restore a module from backup
     *
     * @param string $module Module name
     * @param string $backupPath Path to backup
     * @return bool
     */
    protected function restoreModuleFromBackup($module, $backupPath)
    {
        try {
            $modulePath = $this->modulesPath . '/' . $module;
            
            // Remove failed installation if exists
            if (File::exists($modulePath)) {
                File::deleteDirectory($modulePath);
            }
            
            // Restore from backup
            if (File::exists($backupPath)) {
                File::copyDirectory($backupPath, $modulePath);
                
                // Re-register the module
                Module::scan();
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            Log::error('Module restore failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Uninstall a module without running migrations rollback
     * This is used during updates to preserve database data
     *
     * @param string $name The module name
     * @return bool
     */
    protected function uninstallWithoutMigrationRollback($name)
    {
        try {
            // Check if module exists
            $module = $this->moduleRepository->find($name);
            
            if (!$module) {
                throw new Exception("Module $name not found");
            }
            
            // Disable the module first
            if ($module->isEnabled()) {
                $module->disable();
            }
            
            // Delete the module files
            $modulePath = $this->modulesPath . '/' . $name;
            File::deleteDirectory($modulePath);
            
            // Clean up published assets if they exist
            $assetsPath = public_path('modules/' . Str::kebab($name));
            if (File::exists($assetsPath)) {
                File::deleteDirectory($assetsPath);
            }
            
            // Update modules registry
            Module::scan();
            
            return true;
        } catch (Exception $e) {
            Log::error('Module uninstallation without migration rollback failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Save Module Manager settings
     *
     * @param array $settings Settings to save
     * @return bool
     */
    public function saveSettings(array $settings)
    {
        try {
            $configFile = config_path('module_manager.php');
            
            $contents = '<?php' . PHP_EOL . PHP_EOL;
            $contents .= 'return [' . PHP_EOL;
            
            foreach ($settings as $key => $value) {
                $exportedValue = var_export($value, true);
                $contents .= "    '{$key}' => {$exportedValue}," . PHP_EOL;
            }
            
            $contents .= '];' . PHP_EOL;
            
            File::put($configFile, $contents);
            
            // Clear config cache
            Artisan::call('config:clear');
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to save settings: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify all module licenses
     *
     * @return array Results of verification
     */
    public function verifyLicenses()
    {
        $results = [];
        $envatoToken = config('module_manager.envato_api_token');
        
        if (!$envatoToken) {
            return ['error' => 'Envato API token is not configured'];
        }
        
        $envatoService = app(EnvatoService::class);
        
        // Get all license records
        $licenses = DB::table('module_licenses')->get();
        
        foreach ($licenses as $license) {
            $verification = $envatoService->verifyPurchase($license->purchase_code);
            
            if ($verification) {
                $validUntil = now()->addYear();
                
                // Update license validity
                DB::table('module_licenses')
                    ->where('id', $license->id)
                    ->update([
                        'verification_data' => json_encode($verification),
                        'valid_until' => $validUntil,
                        'updated_at' => now(),
                    ]);
                
                $results[$license->module] = [
                    'status' => 'valid',
                    'valid_until' => $validUntil->format('Y-m-d'),
                ];
            } else {
                $results[$license->module] = [
                    'status' => 'invalid',
                    'message' => 'License could not be verified',
                ];
            }
        }
        
        return $results;
    }

    /**
     * Register a new module in the modules_statuses.json file
     *
     * @param string $moduleName The name of the module to register
     * @param bool $enabled Whether the module should be enabled by default
     * @return bool Success status
     */
    public function registerModuleInStatusFile($moduleName, $enabled = false)
    {
        try {
            $statusesPath = base_path('modules_statuses.json');
            
            // Read current statuses
            $statuses = [];
            if (File::exists($statusesPath)) {
                $statuses = json_decode(File::get($statusesPath), true) ?: [];
            }
            
            // Add the new module
            $statuses[$moduleName] = $enabled;
            
            // Write back to file
            File::put($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT));
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to register module in status file: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a module from the modules_statuses.json file
     *
     * @param string $moduleName The name of the module to remove
     * @return bool Success status
     */
    protected function removeModuleFromStatusFile($moduleName)
    {
        try {
            $statusesPath = base_path('modules_statuses.json');
            
            // Read current statuses
            $statuses = [];
            if (File::exists($statusesPath)) {
                $statuses = json_decode(File::get($statusesPath), true) ?: [];
            }
            
            // Remove the module
            if (isset($statuses[$moduleName])) {
                unset($statuses[$moduleName]);
            }
            
            // Write back to file
            File::put($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT));
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to remove module from status file: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Completely reload modules in the application
     * This ensures newly installed modules are recognized immediately
     */
    protected function reloadModules()
    {
        // Clear module cache
        if (method_exists($this->moduleRepository, 'resetModules')) {
            $this->moduleRepository->resetModules();
        }
        
        // Clear Laravel's cache
        Artisan::call('optimize:clear');
        
        // Scan for modules
        Module::scan();
        
        // Refresh the repository instance
        $this->moduleRepository = app('modules');
    }

    /**
     * Update module status in the modules_statuses.json file
     *
     * @param string $moduleName The name of the module to update
     * @param bool $enabled True to enable, false to disable
     * @return bool Success status
     */
    public function updateModuleStatus($moduleName, $enabled)
    {
        try {
            $statusesPath = base_path('modules_statuses.json');
            
            // Read current statuses
            $statuses = [];
            if (File::exists($statusesPath)) {
                $statuses = json_decode(File::get($statusesPath), true) ?: [];
            }
            
            // Update the module status
            $statuses[$moduleName] = $enabled;
            
            // Write back to file
            File::put($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT));
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to update module status: ' . $e->getMessage());
            return false;
        }
    }
    
    private function updateComposer() {
        dispatch(new UpdateComposerJob());
    }
}
