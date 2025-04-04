<?php declare(strict_types = 1);

namespace Contributte\Redis\DI;

use Contributte\Redis\Caching\RedisJournal;
use Contributte\Redis\Caching\RedisStorage;
use Contributte\Redis\Exception\Logic\InvalidStateException;
use Contributte\Redis\Tracy\RedisPanel;
use Nette\Caching\Storage;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Http\Session;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Session\Handler;
use RuntimeException;
use stdClass;

/**
 * @property-read stdClass $config
 */
final class RedisExtension extends CompilerExtension
{

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'debug' => Expect::bool(false),
			'serializer' => Expect::anyOf(Expect::string()),
			'connection' => Expect::arrayOf(Expect::structure([
				'autowired' => Expect::bool(null),
				'uri' => Expect::anyOf(Expect::string()->dynamic(), Expect::listOf(Expect::string()->dynamic()))->default('tcp://127.0.0.1:6379'),
				'options' => Expect::array(),
				'storageAutowired' => Expect::bool(null),
				'storageClass' => Expect::string()->default(RedisStorage::class),
				'storage' => Expect::bool(false),
				'sessions' => Expect::anyOf(
					Expect::bool(),
					Expect::array()
				)->default(false),
			])),
			'clientFactory' => Expect::string(Client::class),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$connections = [];

		foreach ($config->connection as $name => $connection) {
			$autowired = $connection->autowired ?? ($name === 'default');

			$client = $builder->addDefinition($this->prefix('connection.' . $name . '.client'))
				->setType(ClientInterface::class)
				->setFactory($config->clientFactory, [$connection->uri, $connection->options])
				->setAutowired($autowired);

			$connections[] = [
				'name' => $name,
				'client' => $client,
				'uri' => $connection->uri,
				'options' => $connection->options,
			];
		}

		if ($config->debug && $config->connection !== []) {
			$builder->addDefinition($this->prefix('panel'))
				->setFactory(RedisPanel::class, [$connections]);
		}
	}

	public function beforeCompile(): void
	{
		$this->beforeCompileStorage();
		$this->beforeCompileSession();
	}

	public function beforeCompileStorage(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;
		$storages = 0;

		foreach ($config->connection as $name => $connection) {
			$autowired = $connection->autowired ?? ($name === 'default');

			// Skip if replacing storage is disabled
			if (!$connection->storage) {
				continue;
			}

			// Validate needed services
			if ($builder->getByType(Storage::class) === null) {
				throw new RuntimeException(sprintf('Please install nette/caching package. %s is required', Storage::class));
			}

			if ($storages === 0) {
				$builder->getDefinitionByType(Storage::class)
					->setAutowired(false);
			}

			$builder->addDefinition($this->prefix('connection.' . $name . '.journal'))
				->setFactory(RedisJournal::class)
				->setArguments([
					'client' => $builder->getDefinition($this->prefix('connection.' . $name . '.client')),
				])
				->setAutowired(false);

			$builder->addDefinition($this->prefix('connection.' . $name . '.storage'))
				->setFactory($connection->storageClass)
				->setArguments([
					'client' => $builder->getDefinition($this->prefix('connection.' . $name . '.client')),
					'journal' => $builder->getDefinition($this->prefix('connection.' . $name . '.journal')),
					'serializer' => $config->serializer,
				])
				->setAutowired($connection->storageAutowired ?? $autowired);

			$storages++;
		}
	}

	public function beforeCompileSession(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$sessionHandlingConnection = null;

		foreach ($config->connection as $name => $connection) {
			// Skip if replacing session is disabled
			if ($connection->sessions === false) {
				continue;
			}

			if ($sessionHandlingConnection === null) {
				$sessionHandlingConnection = $name;
			} else {
				throw new InvalidStateException(sprintf(
					'Connections "%s" and "%s" both try to register session handler. Only one of them could have session handler enabled.',
					$sessionHandlingConnection,
					$name
				));
			}

			// Validate needed services
			if ($builder->getByType(Session::class) === null) {
				throw new RuntimeException(sprintf('Please install nette/http package. %s is required', Session::class));
			}

			// Validate session config
			$sessionConfig = $connection->sessions === true ? [
					'ttl' => null,
				] : (array) $connection->sessions;

			$sessionHandler = $builder->addDefinition($this->prefix('connection.' . $name . 'sessionHandler'))
				->setType(Handler::class)
				->setArguments([$this->prefix('@connection.' . $name . '.client'), ['gc_maxlifetime' => $sessionConfig['ttl'] ?? null]]);

			$session = $builder->getDefinitionByType(Session::class);
			assert($session instanceof ServiceDefinition);
			$session->addSetup('setHandler', [$sessionHandler]);
		}
	}

	public function afterCompile(ClassType $class): void
	{
		$config = $this->config;

		if ($config->debug && $config->connection !== []) {
			$initialize = $class->getMethod('initialize');
			$initialize->addBody('$this->getService(?)->addPanel($this->getService(?));', ['tracy.bar', $this->prefix('panel')]);
		}
	}

}
