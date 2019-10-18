<?php declare(strict_types=1);

namespace PmgDev\Migrations;

use h4kuna\Memoize\MemoryStorage;
use Nette\Neon\Neon;
use PmgDev\Migrations\Exceptions\InvalidConfigFormatException;
use PmgDev\Migrations\Exceptions\ReadWriteException;

final class Config
{
	use MemoryStorage;

	/** @var string|NULL */
	private $filename;


	public function __construct(?string $filename)
	{
		$this->filename = $filename;
	}


	public function parameters(): array
	{
		return $this->memoize([__FUNCTION__, $this->filename], function (): array {
			['parameters' => $parameters] = self::merge($this->filename);
			return self::validate($parameters);
		});
	}


	private static function merge(?string $filename): array
	{
		$main = self::load($filename);
		$configs = [$main];
		foreach ($main['includes'] ?? [] as $include) {
			if (is_file($include)) {
				$configs[] = self::load($include);
			} else if ($filename !== NULL && is_file(dirname($filename) . DIRECTORY_SEPARATOR . $include)) {
				$configs[] = self::load(dirname($filename) . DIRECTORY_SEPARATOR . $include);
			} else {
				$configs[] = self::load($include);
			}
		}
		return array_replace_recursive([], ...$configs) ?? [];
	}


	private static function load(?string $filename): array
	{
		if ($filename === NULL || $filename === '') {
			throw new ReadWriteException('Config must be specified');
		}
		$content = @file_get_contents($filename);
		if ($content === FALSE) {
			throw new ReadWriteException(sprintf('Config "%s" is not readable.', $filename));
		}
		return Neon::decode($content) ?? [];
	}


	private static function validate(array $parameters): array
	{
		if (!is_dir($parameters['projectDir'])) {
			throw new InvalidConfigFormatException(sprintf('projectDir "%s" does not exist', $parameters['projectDir']));
		}
		if (!is_dir($parameters['migrationsDir'])) {
			throw new InvalidConfigFormatException(sprintf('migrationsDir "%s" does not exist', $parameters['migrationsDir']));
		}
		if (!is_file($parameters['sqlUtility'])) {
			throw new InvalidConfigFormatException(sprintf('sqlUtility "%s" does not exist', $parameters['sqlUtility']));
		}
		if (!is_file($parameters['dumpUtility'])) {
			throw new InvalidConfigFormatException(sprintf('dumpUtility "%s" does not exist', $parameters['dumpUtility']));
		}
		if (!is_file($parameters['structureFilename'])) {
			throw new InvalidConfigFormatException(sprintf('structureFilename "%s" does not exist', $parameters['structureFilename']));
		}
		return $parameters;
	}

}
