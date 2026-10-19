<?php declare(strict_types=1);

namespace Base3Moodle;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Api\IAuthorizationAdministrationService;
use Base3\Api\IAssetResolver;
use Base3\Api\IBase;
use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IModuleRegistry;
use Base3\Api\IMvcView;
use Base3\Api\IPlugin;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\ConfigValue\Resolver\ConfigValueResolver;
use Base3\Database\Api\IDatabase;
use Base3\Event\Api\IEventManager;
use Base3\Event\EventManager;
use Base3\Language\Api\ILanguage;
use Base3\Logger\Api\ILogger;
use Base3\Logger\ScopedDatabaseLogger\ScopedDatabaseLogger;
use Base3\Session\Api\ISession;
use Base3\Settings\Api\ISettingsStore;
use Base3\Translation\Api\ITranslation;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\UserCriterionEvaluator;
use Base3\Worker\DelegateWorker;
use Base3Moodle\Adapter\MoodleAccesscontrol;
use Base3Moodle\Adapter\MoodleAssetResolver;
use Base3Moodle\Adapter\MoodleConfiguration;
use Base3Moodle\Adapter\MoodleDatabase;
use Base3Moodle\Adapter\MoodleFileManagerHttpService;
use Base3Moodle\Adapter\MoodleFileUploadService;
use Base3Moodle\Adapter\MoodleFileStorageFactory;
use Base3Moodle\Adapter\MoodleLanguage;
use Base3Moodle\Adapter\MoodleManagedFileStorageService;
use Base3Moodle\Adapter\MoodleMvcView;
use Base3Moodle\Adapter\MoodleSession;
use Base3Moodle\Adapter\MoodleTranslation;
use Base3Moodle\Adapter\MoodleUsermanager;
use Base3Moodle\Authorization\MoodleAuthorizationService;
use Base3Moodle\Authorization\MoodleAuthorizationAdministrationService;
use Base3Moodle\Service\MoodleFeatureConfiguration;
use Base3Moodle\Service\MoodleGroupConfiguration;
use Base3Moodle\Service\MoodleAudienceEditor;
use ResourceFoundation\Api\IFileStorageFactory;
use ResourceFoundation\Api\IManagedFileStorageService;

/** Moodle host bindings; project-specific choices belong to Base3MoodleLab. */
final class Base3MoodlePlugin implements IPlugin, IBase {

	public function __construct(private readonly IContainer $container) {}
	public static function getName(): string { return 'base3moodleplugin'; }

	public function init(): void {
		$c = $this->container;
		$c->set(self::getName(), $this, IContainer::SHARED)
			->set(IConfiguration::class, fn() => new MoodleConfiguration(), IContainer::SHARED)
			->set('configuration', IConfiguration::class, IContainer::ALIAS)
			->set(IDatabase::class, fn() => new MoodleDatabase(), IContainer::SHARED)
			->set('database', IDatabase::class, IContainer::ALIAS)
			->set(ILogger::class, fn($c) => new ScopedDatabaseLogger($c->get(IDatabase::class)), IContainer::SHARED)
			->set('logger', ILogger::class, IContainer::ALIAS)
			->set(ISession::class, fn() => new MoodleSession(), IContainer::SHARED)
			->set('session', ISession::class, IContainer::ALIAS)
			->set(IAccesscontrol::class, fn() => new MoodleAccesscontrol(), IContainer::SHARED)
			->set('accesscontrol', IAccesscontrol::class, IContainer::ALIAS)
			->set(UserCriterionEvaluator::class, fn($c) => new UserCriterionEvaluator($c->get(IClassMap::class)), IContainer::SHARED)
			->set(MoodleFeatureConfiguration::class, fn($c) => new MoodleFeatureConfiguration(
				$c->get(ISettingsStore::class), $c->get(UserCriterionEvaluator::class), $c->get(IClassMap::class)
			), IContainer::SHARED)
			->set(MoodleGroupConfiguration::class, fn($c) => new MoodleGroupConfiguration(
				$c->get(ISettingsStore::class), $c->get(IClassMap::class), $c->get(UserCriterionEvaluator::class),
				$c->get(MoodleFeatureConfiguration::class)
			), IContainer::SHARED)
			->set(IUsermanager::class, fn($c) => new MoodleUsermanager(
				$c->get(MoodleGroupConfiguration::class), $c->get(UserCriterionEvaluator::class)
			), IContainer::SHARED)
			->set('usermanager', IUsermanager::class, IContainer::ALIAS)
			->set(IAuthorizationService::class, fn($c) => new MoodleAuthorizationService(
				$c->get(IUsermanager::class), $c->get(IClassMap::class),
				$c->get(UserCriterionEvaluator::class), $c->get(MoodleFeatureConfiguration::class)
			), IContainer::SHARED)
			->set(IAuthorizationAdministrationService::class, fn() => new MoodleAuthorizationAdministrationService(), IContainer::SHARED)
			->set(MoodleAudienceEditor::class, fn($c) => new MoodleAudienceEditor(
				$c->get(IClassMap::class), $c->get(UserCriterionEvaluator::class)
			), IContainer::SHARED)
			->set(ILanguage::class, fn() => new MoodleLanguage(), IContainer::SHARED)
			->set(ITranslation::class, fn($c) => new MoodleTranslation($c->get(ILanguage::class), $c->get(IModuleRegistry::class)), IContainer::SHARED)
			->set(IMvcView::class, fn($c) => new MoodleMvcView($c->get(ILanguage::class), $c->get(IModuleRegistry::class)))
			->set('view', IMvcView::class, IContainer::ALIAS)
			->set(IAssetResolver::class, fn($c) => new MoodleAssetResolver($c->get(IModuleRegistry::class)), IContainer::SHARED)
			->set(IEventManager::class, fn() => new EventManager(), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IConfigValueResolver::class, fn($c) => new ConfigValueResolver($c->get(IClassMap::class)), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IFileStorageFactory::class, fn() => new MoodleFileStorageFactory(), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(IManagedFileStorageService::class, fn($c) => new MoodleManagedFileStorageService($c->get(IFileStorageFactory::class)), IContainer::SHARED | IContainer::NOOVERWRITE)
			->set(MoodleFileUploadService::class, fn($c) => new MoodleFileUploadService($c->get(IManagedFileStorageService::class)), IContainer::SHARED)
			->set(MoodleFileManagerHttpService::class, fn($c) => new MoodleFileManagerHttpService($c->get(IManagedFileStorageService::class), $c->get(MoodleFileUploadService::class)), IContainer::SHARED)
			->set('workers', fn($c) => ['Base3Moodle' => fn() => new DelegateWorker($c->get(IClassMap::class), $c->get(IConfiguration::class))], IContainer::SHARED | IContainer::NOOVERWRITE);
	}
}
